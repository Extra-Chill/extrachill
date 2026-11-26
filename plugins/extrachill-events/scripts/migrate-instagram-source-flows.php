<?php
/**
 * One-shot: wp eval-file scripts/migrate-instagram-source-flows.php
 * Apply only after handler AI guidance is deployed:
 * wp eval-file scripts/migrate-instagram-source-flows.php apply
 *
 * Dry run is the default. Terms must already exist; ambiguous matches fail closed.
 */
use ExtraChillEvents\Core\DataMachineFlowGateway;
use ExtraChillEvents\Core\EventSourceHandler;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$apply = in_array( 'apply', $args ?? array(), true );
$gateway = new DataMachineFlowGateway();
$handlers = apply_filters( 'datamachine_handlers', array(), 'event_import' );
if ( $apply && empty( $handlers['instagram_account']['ai_guidance'] ) && empty( $handlers['instagram_account']['meta']['ai_guidance'] ) ) {
	WP_CLI::error( 'Deploy Instagram handler AI guidance before clearing flow prompts.' );
}

foreach ( array( 902 => 'Grooving Violation', 903 => 'The Purple Buffalo', 908 => 'Kulture Klash', 909 => 'Mercantile and Mash' ) as $flow_id => $name ) {
	$flow = $gateway->get_flow( $flow_id );
	if ( ! $flow ) {
		WP_CLI::error( "Flow {$flow_id} not found." );
	}
	$matches = array();
	foreach ( array( 'venue', 'promoter' ) as $taxonomy ) {
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term ) {
			$matches[] = $term;
		}
	}
	if ( 1 !== count( $matches ) ) {
		WP_CLI::error( "{$name}: expected one canonical venue/promoter term." );
	}
	$term = $matches[0];
	$config = $flow['flow_config'];
	$instagram = false;
	foreach ( $config as $step ) {
		$instagram = $instagram || in_array( 'instagram_account', $step['handler_slugs'] ?? array(), true );
	}
	if ( ! $instagram ) {
		WP_CLI::error( "Flow {$flow_id} is not an Instagram flow." );
	}
	WP_CLI::log( "{$flow_id}: bind {$term->taxonomy} {$term->term_id}; clear copied prompts; draft; daily" );
	if ( ! $apply ) {
		continue;
	}
	$description = $gateway->describe_pipeline( array( 'pipeline_id' => $flow['pipeline_id'] ) );
	if ( is_wp_error( $description ) || empty( $description['pipeline'] ) ) {
		WP_CLI::error( 'Cannot read pipeline prompt; no prompts cleared.' );
	}
	$pipeline = $description['pipeline'];
	$prompt = '';
	foreach ( $pipeline['pipeline_config'] ?? array() as $pipeline_step ) {
		if ( 'ai' === ( $pipeline_step['step_type'] ?? '' ) ) {
			$prompt = (string) ( $pipeline_step['system_prompt'] ?? '' );
		}
	}
	if ( false === strpos( $prompt, EventSourceHandler::EVENT_POLICY ) ) {
		$updated = $gateway->update_step_configuration( array(
			'target' => 'pipeline',
			'pipeline_id' => $flow['pipeline_id'],
			'step_type' => 'ai',
			'expected_revision' => (string) ( $pipeline['revision'] ?? '' ),
			'configuration' => array( 'system_prompt' => $prompt . "\n\n" . EventSourceHandler::EVENT_POLICY ),
		) );
		if ( is_wp_error( $updated ) || empty( $updated['success'] ) ) {
			WP_CLI::error( 'Cannot install canonical events policy; no prompts cleared.' );
		}
	}
	foreach ( $config as $id => $step ) {
		$input = array( 'flow_step_id' => (string) ( $step['flow_step_id'] ?? $id ) );
		if ( 'upsert' === ( $step['step_type'] ?? '' ) ) {
			$upsert = $step['handler_configs']['upsert_event'] ?? array();
			$upsert[ 'taxonomy_' . $term->taxonomy . '_selection' ] = (string) $term->term_id;
			$upsert['post_status'] = 'draft';
			$input['handler_slug'] = 'upsert_event';
			$input['handler_config'] = $upsert;
		} elseif ( 'ai' === ( $step['step_type'] ?? '' ) ) {
			$input['user_message'] = '';
			$cleared = DataMachineFlowGateway::execute( 'datamachine/queue-clear', array( 'flow_id' => $flow_id, 'flow_step_id' => $input['flow_step_id'] ) );
			if ( is_wp_error( $cleared ) || empty( $cleared['success'] ) ) {
				WP_CLI::error( "Flow {$flow_id}: queue clear failed." );
			}
		} else {
			continue;
		}
		$result = $gateway->update_flow_step( $input );
		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			WP_CLI::error( "Flow {$flow_id}: update failed; stop and inspect before retrying." );
		}
	}
	if ( ! $gateway->update_scheduling( $flow_id, array_merge( $flow['scheduling_config'], array( 'interval' => 'daily' ) ) ) ) {
		WP_CLI::error( "Flow {$flow_id}: scheduling failed." );
	}
}
WP_CLI::success( $apply ? 'Migration applied.' : 'Dry run only; pass apply after deploying handler guidance and canonical events policy.' );

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

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

$apply    = in_array( 'apply', $args ?? array(), true );
$gateway  = new DataMachineFlowGateway();
$handlers = apply_filters( 'datamachine_handlers', array(), 'event_import' );
if ( $apply && empty( $handlers['instagram_account']['ai_guidance'] ) && empty( $handlers['instagram_account']['meta']['ai_guidance'] ) ) {
	WP_CLI::error( 'Deploy Instagram handler AI guidance before replacing flow prompts.' );
}

foreach ( array(
	902 => 'Grooving Violation',
	903 => 'The Purple Buffalo',
	908 => 'Kulture Klash',
	909 => 'Mercantile and Mash',
) as $flow_id => $name ) {
	$flow = $gateway->get_flow( $flow_id );
	if ( ! $flow ) {
		WP_CLI::error( "Flow {$flow_id} not found." );
	}
	$matches = array();
	foreach ( array( 'venue', 'promoter' ) as $ec_taxonomy ) {
		$ec_term = get_term_by( 'name', $name, $ec_taxonomy );
		if ( $ec_term ) {
			$matches[] = $ec_term;
		}
	}
	if ( 1 !== count( $matches ) ) {
		WP_CLI::error( "{$name}: expected one canonical venue/promoter term." );
	}
	$ec_term   = $matches[0];
	$config    = $flow['flow_config'];
	$instagram = false;
	foreach ( $config as $step ) {
		$instagram = $instagram || in_array( 'instagram_account', $step['handler_slugs'] ?? array(), true );
	}
	if ( ! $instagram ) {
		WP_CLI::error( "Flow {$flow_id} is not an Instagram flow." );
	}
	WP_CLI::log( "{$flow_id}: bind {$ec_term->taxonomy} {$ec_term->term_id}; replace copied flow user_message with events policy; draft; daily" );
	if ( ! $apply ) {
		continue;
	}
	foreach ( $config as $ec_step_id => $step ) {
		$input = array( 'flow_step_id' => (string) ( $step['flow_step_id'] ?? $ec_step_id ) );
		if ( 'upsert' === ( $step['step_type'] ?? '' ) ) {
			$upsert = $step['handler_configs']['upsert_event'] ?? array();
			if ( 'promoter' === $ec_term->taxonomy ) {
				$upsert['taxonomy_promoter_selection'] = (string) $ec_term->term_id;
			}
			$upsert['post_status']   = 'draft';
			$input['handler_slug']   = 'upsert_event';
			$input['handler_config'] = $upsert;
		} elseif ( 'ai' === ( $step['step_type'] ?? '' ) ) {
			$input['user_message'] = EventSourceHandler::EVENT_POLICY;
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
WP_CLI::success( $apply ? 'Migration applied.' : 'Dry run only; pass apply after deploying handler guidance.' );

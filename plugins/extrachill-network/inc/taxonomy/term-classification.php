<?php
/**
 * Data Machine registration and post lifecycle scheduling for term classification.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Data Machine task type. */
const EXTRACHILL_NETWORK_TERM_CLASSIFICATION_TASK = 'extrachill_network_classify_post_terms';

/** Post meta tracking active/terminal Data Machine attempts per operation lane. */
const EXTRACHILL_NETWORK_TERM_CLASSIFICATION_JOBS_META = '_extrachill_network_term_classification_jobs';

add_filter(
	'datamachine_tasks',
	static function ( array $tasks ): array {
		if ( ! class_exists( '\DataMachine\Engine\AI\System\Tasks\SystemTask' ) ) {
			return $tasks;
		}
		require_once __DIR__ . '/term-classification-task.php';
		$tasks[ EXTRACHILL_NETWORK_TERM_CLASSIFICATION_TASK ] = \ExtraChillNetwork\Taxonomy\TermClassificationTask::class;
		return $tasks;
	}
);

/**
 * Schedule one bounded classification using Data Machine idempotency.
 *
 * @param array $args {site, post_id, taxonomies?, force?, dry_run?}.
 * @return array<string,mixed>|WP_Error
 */
function extrachill_network_schedule_term_classification( $args ) {
	if ( ! class_exists( '\DataMachine\Engine\Tasks\TaskScheduler' ) || ! class_exists( '\DataMachine\Core\PluginSettings' ) ) {
		return new WP_Error( 'classification_runtime_unavailable', __( 'Data Machine task execution is unavailable.', 'extrachill-network' ) );
	}
	if ( ! \DataMachine\Core\PluginSettings::get( 'extrachill_network_term_classification_enabled', true ) ) {
		return new WP_Error( 'classification_task_disabled', __( 'Network term classification is disabled.', 'extrachill-network' ) );
	}

	$site_key = sanitize_key( $args['site'] ?? '' );
	$post_id  = absint( $args['post_id'] ?? 0 );
	$blog_id  = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $site_key ) : 0;
	if ( ! $blog_id || ! $post_id ) {
		return new WP_Error( 'invalid_classification_target', __( 'A valid site and post are required.', 'extrachill-network' ) );
	}

	switch_to_blog( $blog_id );
	try {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'classification_post_not_found', __( 'The classification target was not found.', 'extrachill-network' ) );
		}
		$taxonomies = extrachill_network_get_eligible_term_taxonomies( $site_key, $post->post_type, $args['taxonomies'] ?? array() );
		if ( empty( $taxonomies ) ) {
			return new WP_Error( 'unsupported_classification_target', __( 'No approved taxonomy is registered for this post type.', 'extrachill-network' ) );
		}

		$fingerprint = extrachill_network_term_classification_fingerprint( $post );
		if ( empty( $args['force'] ) && extrachill_network_term_classification_is_current( $post_id, $taxonomies, $fingerprint ) ) {
			return array(
				'scheduled'   => false,
				'reason'      => 'identical_fingerprint',
				'fingerprint' => $fingerprint,
			);
		}

		$model = \DataMachine\Core\PluginSettings::resolveModelForAgentMode( 0, 'system' );
		if ( empty( $model['provider'] ) || empty( $model['model'] ) ) {
			return new WP_Error( 'classification_provider_unavailable', __( 'No system AI provider and model are configured.', 'extrachill-network' ) );
		}

		$params     = array(
			'site'        => $site_key,
			'post_id'     => $post_id,
			'taxonomies'  => $taxonomies,
			'fingerprint' => $fingerprint,
			'force'       => ! empty( $args['force'] ),
			'dry_run'     => ! empty( $args['dry_run'] ),
		);
		$mode       = ! empty( $args['dry_run'] ) ? 'preview' : 'live';
		$lane       = hash( 'sha256', implode( '|', array( $site_key, $post_id, implode( ',', $taxonomies ), $fingerprint, $mode ) ) );
		$job_states = get_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_CLASSIFICATION_JOBS_META, true );
		$job_states = is_array( $job_states ) ? $job_states : array();
		$state      = is_array( $job_states[ $lane ] ?? null ) ? $job_states[ $lane ] : array();
		$attempt    = max( 1, absint( $state['attempt'] ?? 1 ) );

		if ( ! empty( $state['job_id'] ) && class_exists( '\DataMachine\Core\Database\Jobs\Jobs' ) && class_exists( '\DataMachine\Core\JobStatus' ) ) {
			/* @phpstan-ignore-next-line class.notFound */
			$existing_job = ( new \DataMachine\Core\Database\Jobs\Jobs() )->get_job( (int) $state['job_id'] );
			if ( is_array( $existing_job ) && ! \DataMachine\Core\JobStatus::isStatusFinal( (string) ( $existing_job['status'] ?? '' ) ) ) {
				return array(
					'scheduled'   => false,
					'reason'      => 'duplicate_active_job',
					'job_id'      => (int) $state['job_id'],
					'fingerprint' => $fingerprint,
					'taxonomies'  => $taxonomies,
				);
			}
			++$attempt;
		}

		$key = sprintf( 'extrachill-network-terms:%s:%d:%s:%s:%s:%d', $site_key, $post_id, implode( ',', $taxonomies ), $fingerprint, $mode, $attempt );

		$job_id = \DataMachine\Engine\Tasks\TaskScheduler::schedule(
			EXTRACHILL_NETWORK_TERM_CLASSIFICATION_TASK,
			$params,
			array( 'origin' => 'extrachill-network' ),
			0,
			$key
		);
		if ( ! $job_id ) {
			$error = \DataMachine\Engine\Tasks\TaskScheduler::getLastScheduleError();
			return new WP_Error( 'classification_schedule_failed', $error['message'] ?? __( 'The classification job could not be scheduled.', 'extrachill-network' ) );
		}

		$job_states[ $lane ] = array(
			'job_id'      => (int) $job_id,
			'attempt'     => $attempt,
			'mode'        => $mode,
			'fingerprint' => $fingerprint,
		);
		update_post_meta( $post_id, EXTRACHILL_NETWORK_TERM_CLASSIFICATION_JOBS_META, $job_states );

		return array(
			'scheduled'   => true,
			'job_id'      => (int) $job_id,
			'fingerprint' => $fingerprint,
			'taxonomies'  => $taxonomies,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Schedule configured pending, direct-publish, and meaningful publish updates.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Previous status.
 * @param WP_Post $post       Post object.
 * @return void
 */
function extrachill_network_maybe_schedule_term_classification( $new_status, $old_status, $post ) {
	if ( ! empty( $GLOBALS['extrachill_network_term_classifier_writing'] ) ) {
		return;
	}
	if ( wp_is_post_autosave( $post->ID ) || wp_is_post_revision( $post->ID ) || in_array( $new_status, array( 'auto-draft', 'draft', 'trash', 'inherit' ), true ) ) {
		return;
	}

	$is_pending_transition = 'pending' === $new_status && 'pending' !== $old_status;
	$is_publish_transition = in_array( $new_status, array( 'publish', 'closed' ), true );
	if ( ! $is_pending_transition && ! $is_publish_transition ) {
		return;
	}

	/*
	 * publish -> publish is an update, not a publication.
	 *
	 * The pending branch above already excludes no-op transitions; this one
	 * did not, so every save of an already-published post scheduled a paid
	 * classification. That is fine for an editor changing the body text and
	 * wrong for metadata churn: an importer refreshing post_date on 10,146
	 * unchanged events in one evening scheduled a job for each.
	 *
	 * The downstream identical-fingerprint check cannot absorb this, because
	 * it only recognises classifications that succeeded — and 86% of events
	 * on this network have no provenance, so they never look done and never
	 * converge. Compare against what was already attempted instead.
	 */
	$is_noop_transition = $old_status === $new_status;
	if ( $is_noop_transition && ! empty( $post->ID ) ) {
		$fingerprint = extrachill_network_term_classification_fingerprint( $post );
		if ( extrachill_network_term_classification_already_attempted( $post->ID, $fingerprint ) ) {
			return;
		}
	}

	$site_key = function_exists( 'extrachill_get_current_site_key' ) ? extrachill_get_current_site_key() : null;
	if ( ! $site_key ) {
		return;
	}
	$taxonomies = extrachill_network_get_eligible_term_taxonomies( $site_key, $post->post_type );
	if ( empty( $taxonomies ) ) {
		return;
	}

	$content_text = trim( wp_strip_all_tags( (string) $post->post_title . ' ' . strip_shortcodes( (string) $post->post_content ) ) );
	if ( mb_strlen( $content_text ) < (int) apply_filters( 'extrachill_network_term_classification_min_length', 40, $post ) ) {
		return;
	}

	/**
	 * Whether a post is worth classifying automatically.
	 *
	 * Classification costs an inference call per post, so a site that knows
	 * some of its content will never be browsed should be able to say so.
	 * The canonical case is the events site: terms exist to make upcoming
	 * shows discoverable, and 74% of its 131,008 published events are already
	 * in the past, where no amount of classification changes what anyone
	 * finds.
	 *
	 * This deliberately guards only the automatic path. An explicit call to
	 * extrachill_network_schedule_term_classification(), including a forced
	 * reclassification from the CLI or an ability, still runs — a human
	 * asking for the work is not the case this is protecting against.
	 *
	 * Whether a given post matters is domain knowledge owned by the plugin
	 * that defines the post type, not by this one, which is why it is a seam
	 * rather than a condition here.
	 *
	 * @param bool    $should_classify Default true.
	 * @param WP_Post $post            Post being considered.
	 * @param string  $site_key        Network site key.
	 */
	if ( ! apply_filters( 'extrachill_network_should_classify_post', true, $post, $site_key ) ) {
		return;
	}

	extrachill_network_schedule_term_classification(
		array(
			'site'       => $site_key,
			'post_id'    => $post->ID,
			'taxonomies' => $taxonomies,
		)
	);
}
add_action( 'transition_post_status', 'extrachill_network_maybe_schedule_term_classification', 30, 3 );

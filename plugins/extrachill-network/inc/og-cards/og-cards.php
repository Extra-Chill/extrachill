<?php
/**
 * OG Cards Feature Bootstrap.
 *
 * Wires the OG card pieces together:
 *   - Brand token bridge (theme tokens → DM image templates)
 *   - Post-type → template registry + per-post-type data resolver
 *   - SystemTask registration with Data Machine
 *   - Cache-only extrachill-seo singular OG image filter
 *   - Deferred generation on post, metadata and taxonomy changes
 *
 * The feature is opt-in: posts only get cards when their post type is
 * registered in the `extrachill_og_card_template_map` filter and a
 * data collector responds to `extrachill_og_card_data_<post_type>`.
 *
 * @package ExtraChillNetwork\OgCards
 * @since 1.11.0
 */

namespace ExtraChillNetwork\OgCards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/brand-tokens.php';
require_once __DIR__ . '/data-resolver.php';
require_once __DIR__ . '/og-card-task.php';

/**
 * Register the OG card task with Data Machine.
 *
 * @param array $tasks Existing task type → handler class map.
 * @return array
 */
add_filter(
	'datamachine_tasks',
	function ( array $tasks ): array {
		$tasks['og_card_generation'] = OgCardGenerationTask::class;
		return $tasks;
	}
);

/**
 * Provide the OG card URL for a singular post.
 *
 * Hooks `extrachill_seo_singular_og_image_url` from extrachill-seo. When
 * a card has been generated, returns its cached URL. Never renders on
 * a visitor/crawler request; publish/change hooks and backfill own generation.
 *
 * @param string   $existing Existing URL (from earlier filters).
 * @param \WP_Post $post     Queried post.
 * @return string
 */
add_filter(
	'extrachill_seo_singular_og_image_url',
	function ( string $existing, $post ): string {
		if ( '' !== $existing ) {
			return $existing;
		}

		if ( ! $post instanceof \WP_Post ) {
			return $existing;
		}

		// Respect the SystemTask enabled toggle so site owners can disable
		// generation without unhooking the filter.
		if ( ! \DataMachine\Core\PluginSettings::get( 'og_card_generation_enabled', true ) ) {
			return $existing;
		}

		if ( 'publish' !== $post->post_status || null === template_id_for_post( $post ) ) {
			return $existing;
		}

		$inspection = OgCardGenerationTask::inspect_for_post( $post );
		if ( $inspection['is_current'] ) {
			return $inspection['existing_url'];
		}

		return $existing;
	},
	10,
	2
);

/**
 * Provide alt text for the generated card.
 *
 * @param string   $existing Existing alt text.
 * @param \WP_Post $post     Queried post.
 * @return string
 */
add_filter(
	'extrachill_seo_singular_og_image_alt',
	function ( string $existing, $post ): string {
		if ( '' !== $existing || ! $post instanceof \WP_Post ) {
			return $existing;
		}

		$cached_url = (string) get_post_meta( $post->ID, OgCardGenerationTask::META_URL, true );
		if ( '' === $cached_url ) {
			return $existing;
		}

		// Build a descriptive alt from the resolved data so screen readers
		// and crawlers get meaningful text without us pre-computing it.
		$data   = resolve_card_data( $post );
		$pieces = array_filter(
			array(
				$data['event_name'] ?? get_the_title( $post ),
				$data['venue'] ?? '',
				$data['city'] ?? '',
				$data['date_label'] ?? '',
			)
		);

		return implode( ' — ', $pieces );
	},
	10,
	2
);

/**
 * Coalesce updates into a site-local cron event. Resolve data only after the
 * write request has finished: importers often save dates/terms after save_post.
 * Retain the old asset until its replacement has rendered successfully.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function queue_card_generation( int $post_id ): void {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || null === template_id_for_post( $post ) ) {
		return;
	}

	$args = array( $post_id );
	if ( ! wp_next_scheduled( 'extrachill_generate_og_card', $args ) ) {
		wp_schedule_single_event( time() + 30, 'extrachill_generate_og_card', $args );
	}
}

/** Dispatch changed/missing cards through the existing SystemTask machinery. */
function generate_queued_card( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status
		|| ! \DataMachine\Core\PluginSettings::get( 'og_card_generation_enabled', true )
		|| has_post_thumbnail( $post_id ) ) {
		return;
	}

	$inspection = OgCardGenerationTask::inspect_for_post( $post );
	if ( null === $inspection['template_id'] || empty( $inspection['data'] ) || $inspection['is_current'] ) {
		return;
	}

	$job_id = \DataMachine\Engine\Tasks\TaskScheduler::schedule( 'og_card_generation', array( 'post_id' => $post_id ) );
	if ( false === $job_id ) {
		// A transient scheduler failure must not silently lose a published card.
		queue_card_generation( $post_id );
	}
}

add_action( 'save_post', __NAMESPACE__ . '\\queue_card_generation', 20, 1 );
add_action( 'extrachill_generate_og_card', __NAMESPACE__ . '\\generate_queued_card', 10, 1 );

foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
	add_action(
		$hook,
		function ( $meta_id, $post_id, $meta_key ): void {
			// Our own cache writes must not recursively enqueue another render.
			if ( in_array( $meta_key, array( OgCardGenerationTask::META_URL, OgCardGenerationTask::META_SIGNATURE ), true ) ) {
				return;
			}
			queue_card_generation( (int) $post_id );
		},
		20,
		3
	);
}
add_action( 'set_object_terms', __NAMESPACE__ . '\\queue_card_generation', 20, 1 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/backfill-command.php';
}

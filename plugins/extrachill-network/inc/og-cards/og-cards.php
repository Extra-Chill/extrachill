<?php
/**
 * OG Cards Feature Bootstrap.
 *
 * Wires the OG card pieces together:
 *   - Brand token bridge (theme tokens → DM image templates)
 *   - Post-type → template registry + per-post-type data resolver
 *   - SystemTask registration with Data Machine
 *   - Lazy-render hook on the extrachill-seo singular OG image filter
 *   - Cache invalidation on post save
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
 * the post type has an OG template registered, renders + caches the
 * card synchronously and returns the cached URL. Falls back to the
 * existing value when no template is mapped.
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

		$result = OgCardGenerationTask::render_for_post( $post );
		if ( ! empty( $result['cached_url'] ) ) {
			return (string) $result['cached_url'];
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
 * Invalidate the cached card when a post is saved.
 *
 * Compares the current data signature to the stored one and deletes
 * the cache when they differ. The next OG request regenerates lazily.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post object.
 * @param bool     $update  Whether this was an update.
 * @return void
 */
add_action(
	'save_post',
	function ( $post_id, $post, $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- save_post hook contract; third arg unused.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( null === template_id_for_post( $post ) ) {
			return;
		}

		$current_sig = OgCardGenerationTask::signature_for( resolve_card_data( $post ) );
		$stored_sig  = (string) get_post_meta( $post_id, OgCardGenerationTask::META_SIGNATURE, true );

		if ( $current_sig === $stored_sig ) {
			return;
		}

		OgCardGenerationTask::invalidate( $post_id );
	},
	20,
	3
);

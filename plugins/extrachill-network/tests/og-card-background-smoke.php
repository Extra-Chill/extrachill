<?php
/** Standalone lifecycle regression tests for #1795. */
namespace DataMachine\Core {
	class PluginSettings {
		public static function get( $key, $default ) { return $GLOBALS['background_enabled'] ?? $default; }
	}
}
namespace DataMachine\Engine\Tasks {
	class TaskScheduler {
		public static function schedule( $type, $params ) {
			$GLOBALS['background_jobs'][] = array( $type, $params );
			return count( $GLOBALS['background_jobs'] );
		}
	}
}
namespace {
	// Reuse the cache unit fixture, including an ability that rejects callers.
	require __DIR__ . '/og-card-content-addressed-cache-smoke.php';
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { add_filter( $hook, $callback, $priority, $accepted_args ); }
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['og_smoke_filters'][ $hook ] ?? array() as list( $callback, $accepted_args ) ) {
			$callback( ...array_slice( $args, 0, $accepted_args ) );
		}
	}
	function get_post( $id ) { return $GLOBALS['background_post']; }
	function wp_is_post_autosave( $id ) { return false; }
	function wp_is_post_revision( $id ) { return false; }
	function has_post_thumbnail( $id ) { return false; }
	function wp_next_scheduled( $hook, $args ) { return $GLOBALS['background_events'][ $args[0] ] ?? false; }
	function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['background_events'][ $args[0] ] = $time; }

	require dirname( __DIR__ ) . '/inc/og-cards/og-cards.php';
	$post = new WP_Post( 888001, 'og_smoke_test' );
	$GLOBALS['background_events'] = array();
	$GLOBALS['background_jobs'] = array();
	$post->post_status = 'draft';
	$GLOBALS['background_post'] = $post;
	do_action( 'save_post', $post->ID );
	og_smoke_assert_same( false, wp_next_scheduled( '', array( $post->ID ) ), 'Drafts do not queue.' );
	$post->post_status = 'publish';
	$calls = $GLOBALS['og_smoke_render_calls'];
	do_action( 'save_post', $post->ID );
	do_action( 'updated_post_meta', 1, $post->ID, 'event_date' );
	og_smoke_assert_same( 1, count( $GLOBALS['background_events'] ), 'Publish and metadata updates coalesce.' );
	og_smoke_assert_same( $calls, $GLOBALS['og_smoke_render_calls'], 'Saving never renders.' );
	og_smoke_assert_same( '', apply_filters( 'extrachill_seo_singular_og_image_url', '', $post ), 'Cache miss does not render.' );
	do_action( 'extrachill_generate_og_card', $post->ID );
	og_smoke_assert_same( array( 'og_card_generation', array( 'post_id' => $post->ID ) ), $GLOBALS['background_jobs'][0], 'Background callback schedules the SystemTask.' );
	$result = \ExtraChillNetwork\OgCards\OgCardGenerationTask::render_for_post( $post );
	$calls = $GLOBALS['og_smoke_render_calls'];
	og_smoke_assert_same( $result['cached_url'], apply_filters( 'extrachill_seo_singular_og_image_url', '', $post ), 'Anonymous SEO filter serves cached URL.' );
	og_smoke_assert_same( 'earlier', apply_filters( 'extrachill_seo_singular_og_image_url', 'earlier', $post ), 'Earlier filters retain precedence.' );
	do_action( 'extrachill_generate_og_card', $post->ID );
	og_smoke_assert_same( 1, count( $GLOBALS['background_jobs'] ), 'Current cards do not schedule another job.' );
	$GLOBALS['og_smoke_event_name'] = 'Changed after publish';
	og_smoke_assert_same( '', apply_filters( 'extrachill_seo_singular_og_image_url', '', $post ), 'Stale cards are not served.' );
	og_smoke_assert_same( $calls, $GLOBALS['og_smoke_render_calls'], 'Neither cache hits nor stale reads render.' );
	echo "OG card background tests passed.\n";
}

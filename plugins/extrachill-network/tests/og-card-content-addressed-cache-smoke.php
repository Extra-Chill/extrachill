<?php
/**
 * Standalone tests for content-addressed OG card cache keys (#247).
 *
 * Verifies:
 *  - The cache key/URL embeds a digest of the existing data signature
 *    (not a second hash) so a regenerated card lands at a new URL.
 *  - An unchanged signature reuses the existing file (no rewrite, no
 *    extra render call) so caching isn't broken in the other direction.
 *  - The previous file is deleted on regeneration, derived from the
 *    stored URL, so the bucket cannot grow unbounded.
 *  - Legacy (pre-#247, non-addressed) stored URLs are still readable
 *    and are cleaned up correctly on their first regeneration.
 *  - A missing/empty signature still produces a usable key.
 *  - The blog ID prefix is preserved (multisite collision guard).
 *
 * @package ExtraChillNetwork\OgCards
 */

declare( strict_types=1 );

// A file containing a bracketed namespace declaration must use bracketed
// syntax for every namespace block, including the unnamed/global one, and
// no statement (besides declare()) may precede the first namespace block.
namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	function og_smoke_assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
			exit( 1 );
		}
	}

	function og_smoke_assert_true( $actual, string $message ): void {
		og_smoke_assert_same( true, (bool) $actual, $message );
	}

	// -------------------------------------------------------------------
	// Minimal WordPress + Data Machine stubs. Only what og-card-task.php
	// and data-resolver.php actually call.
	// -------------------------------------------------------------------

	$GLOBALS['og_smoke_filters']      = array();
	$GLOBALS['og_smoke_post_meta']    = array();
	$GLOBALS['og_smoke_blog_id']      = 7;
	$GLOBALS['og_smoke_render_calls'] = 0;
	$GLOBALS['og_smoke_uploads_base'] = sys_get_temp_dir() . '/og-card-smoke-' . uniqid();

	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['og_smoke_filters'][ $hook ][] = array( $callback, $accepted_args );
	}

	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['og_smoke_filters'][ $hook ] ?? array() as list( $callback, $accepted_args ) ) {
			$all   = array_merge( array( $value ), $args );
			$value = $callback( ...array_slice( $all, 0, $accepted_args ) );
		}
		return $value;
	}

	function get_the_terms( $post_id, $taxonomy ) {
		return false; // No location/venue terms in this smoke test.
	}

	function get_current_blog_id() {
		return $GLOBALS['og_smoke_blog_id'];
	}

	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['og_smoke_post_meta'][ $post_id ][ $key ] ?? '';
	}

	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['og_smoke_post_meta'][ $post_id ][ $key ] = $value;
	}

	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['og_smoke_post_meta'][ $post_id ][ $key ] );
	}

	function wp_upload_dir() {
		return array(
			'basedir' => $GLOBALS['og_smoke_uploads_base'],
			'baseurl' => 'https://example.test/wp-content/uploads',
		);
	}

	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}

	function wp_json_encode( $data ) {
		return json_encode( $data );
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function wp_basename( $path ) {
		return basename( (string) $path );
	}

	function sanitize_file_name( $name ) {
		return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
	}

	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}

	function wp_delete_file( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}

	class OgSmokeImageTemplateAbility {
		public function execute( array $input ) {
			$GLOBALS['og_smoke_render_calls']++;

			$bucket = sanitize_file_name( (string) $input['cache']['bucket'] );
			$key    = sanitize_file_name( (string) $input['cache']['key'] );
			$ext    = (string) $input['format'];

			$bucket_dir = trailingslashit( $GLOBALS['og_smoke_uploads_base'] ) . $bucket;
			wp_mkdir_p( $bucket_dir );

			$filename  = $key . '.' . $ext;
			$dest_path = trailingslashit( $bucket_dir ) . $filename;
			$dest_url  = 'https://example.test/wp-content/uploads/' . $bucket . '/' . $filename;

			// Content varies with the render call count so byte-for-byte
			// comparisons would also detect a spurious rewrite.
			file_put_contents( $dest_path, 'render-' . $GLOBALS['og_smoke_render_calls'] . ':' . wp_json_encode( $input['data'] ) );

			return array(
				'cached_paths' => array( $dest_path ),
				'cached_urls'  => array( $dest_url ),
			);
		}
	}

	function wp_get_ability( $slug ) {
		if ( 'datamachine/render-image-template' !== $slug ) {
			return null;
		}
		return new OgSmokeImageTemplateAbility();
	}

	function is_wp_error( $thing ) {
		return false;
	}

	function og_smoke_rmrf( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? og_smoke_rmrf( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	register_shutdown_function( 'og_smoke_rmrf', $GLOBALS['og_smoke_uploads_base'] );
}

// Minimal SystemTask stand-in: only the two abstract methods the real
// base class declares. render_for_post()/cache_key_for() etc. are all
// static and never touch job machinery, so nothing else is needed.
namespace DataMachine\Engine\AI\System\Tasks {
	abstract class SystemTask {
		abstract public function executeTask( int $jobId, array $params ): void;
		abstract public function getTaskType(): string;
	}
}

namespace {

	class WP_Post {
		public $ID;
		public $post_type;
		public $post_title = '';

		public function __construct( int $id, string $post_type ) {
			$this->ID        = $id;
			$this->post_type = $post_type;
		}
	}

	require_once dirname( __DIR__ ) . '/inc/og-cards/data-resolver.php';
	require_once dirname( __DIR__ ) . '/inc/og-cards/og-card-task.php';

	// Route a dedicated test post type through the template map + data
	// collector filters so we control the payload directly, without
	// touching the real events collector (which needs its own set of
	// events-plugin stubs out of scope for this fix).
	add_filter(
		'extrachill_og_card_template_map',
		function ( array $map ): array {
			$map['og_smoke_test'] = 'smoke_template';
			return $map;
		}
	);

	add_filter(
		'extrachill_og_card_data_og_smoke_test',
		function ( array $data, \WP_Post $post ): array {
			$data['event_name'] = $GLOBALS['og_smoke_event_name'];
			return $data;
		},
		10,
		2
	);

	// -------------------------------------------------------------
	// 1. Generate a card. URL embeds an 8-char digest of the existing
	//    signature (not a second hash) — not the bare post-id key.
	// -------------------------------------------------------------
	$post                           = new \WP_Post( 486727, 'og_smoke_test' );
	$GLOBALS['og_smoke_event_name'] = 'Original Title';

	$data      = \ExtraChillNetwork\OgCards\resolve_card_data( $post );
	$signature = \ExtraChillNetwork\OgCards\OgCardGenerationTask::signature_for( $data );
	$digest    = substr( $signature, 0, 8 );

	$result1 = \ExtraChillNetwork\OgCards\OgCardGenerationTask::render_for_post( $post );
	og_smoke_assert_same( '', $result1['error'] ?? '', 'First render succeeds.' );
	og_smoke_assert_true( str_contains( $result1['cached_url'], "b7-og_smoke_test-486727-{$digest}.png" ), 'URL embeds blog id, post type, post id, and the 8-char signature digest — not a second hash.' );
	og_smoke_assert_true( file_exists( $result1['cached_path'] ), 'First render writes a file.' );
	og_smoke_assert_same( 1, $GLOBALS['og_smoke_render_calls'], 'First render actually calls the render ability.' );

	$path_v1 = $result1['cached_path'];
	$url_v1  = $result1['cached_url'];

	// -------------------------------------------------------------
	// 2. Regenerate with SAME data. URL/file must be stable and
	//    reused — a key that changes on unchanged input is as broken
	//    as one that never changes.
	// -------------------------------------------------------------
	$result_same = \ExtraChillNetwork\OgCards\OgCardGenerationTask::render_for_post( $post );
	og_smoke_assert_same( $url_v1, $result_same['cached_url'], 'Unchanged input produces a stable URL.' );
	og_smoke_assert_true( $result_same['reused_cache'], 'Unchanged input reuses the cache instead of rewriting.' );
	og_smoke_assert_same( 1, $GLOBALS['og_smoke_render_calls'], 'Unchanged input does not call the render ability again (no disk churn).' );
	og_smoke_assert_true( file_exists( $path_v1 ), 'Original file still present after a no-op regeneration.' );

	// -------------------------------------------------------------
	// 3. Change the input (title), regenerate. URL MUST differ and
	//    the old file MUST be gone (deleted, not orphaned).
	// -------------------------------------------------------------
	$GLOBALS['og_smoke_event_name'] = 'Corrected Title After Fix';

	$result2 = \ExtraChillNetwork\OgCards\OgCardGenerationTask::render_for_post( $post );
	og_smoke_assert_same( '', $result2['error'] ?? '', 'Second render succeeds.' );
	og_smoke_assert_true( $result2['cached_url'] !== $url_v1, 'Changed input produces a different URL.' );
	og_smoke_assert_true( ! $result2['reused_cache'], 'Changed input is not reported as reused.' );
	og_smoke_assert_same( 2, $GLOBALS['og_smoke_render_calls'], 'Changed input actually re-renders.' );
	og_smoke_assert_true( file_exists( $result2['cached_path'] ), 'New file exists after regeneration.' );
	og_smoke_assert_true( ! file_exists( $path_v1 ), 'Old file is deleted on regeneration (bucket does not grow unbounded).' );

	// Bucket has exactly one file for this post.
	$bucket_dir = trailingslashit( $GLOBALS['og_smoke_uploads_base'] ) . \ExtraChillNetwork\OgCards\OgCardGenerationTask::CACHE_BUCKET;
	$matches    = glob( $bucket_dir . '/b7-og_smoke_test-486727-*.png' );
	og_smoke_assert_same( 1, count( $matches ), 'Exactly one live card file remains for this post after regeneration.' );

	// -------------------------------------------------------------
	// 4. Regenerate again with the (now current) unchanged data —
	//    stability holds after a real content change too, not just
	//    on the very first render.
	// -------------------------------------------------------------
	$result2_again = \ExtraChillNetwork\OgCards\OgCardGenerationTask::render_for_post( $post );
	og_smoke_assert_same( $result2['cached_url'], $result2_again['cached_url'], 'URL stays stable across repeated regeneration with unchanged (post-fix) input.' );
	og_smoke_assert_true( $result2_again['reused_cache'], 'Repeated regeneration with unchanged input reuses the cache.' );
	og_smoke_assert_same( 2, $GLOBALS['og_smoke_render_calls'], 'No extra render call for the stable case.' );

	// -------------------------------------------------------------
	// 5. Legacy compatibility: a pre-#247 stored URL (no signature
	//    suffix) must remain readable, and must be correctly deleted
	//    (not orphaned, not globbed) on its next regeneration.
	// -------------------------------------------------------------
	$legacy_post_id = 999001;
	$legacy_post    = new \WP_Post( $legacy_post_id, 'og_smoke_test' );
	$legacy_dir     = trailingslashit( $GLOBALS['og_smoke_uploads_base'] ) . \ExtraChillNetwork\OgCards\OgCardGenerationTask::CACHE_BUCKET;
	wp_mkdir_p( $legacy_dir );
	$legacy_filename = 'b7-og_smoke_test-999001.png'; // old scheme: no -<sig8> suffix
	$legacy_path     = trailingslashit( $legacy_dir ) . $legacy_filename;
	file_put_contents( $legacy_path, 'legacy-bytes' );
	$legacy_url = 'https://example.test/wp-content/uploads/og-cards/' . $legacy_filename;

	update_post_meta( $legacy_post_id, \ExtraChillNetwork\OgCards\OgCardGenerationTask::META_URL, $legacy_url );
	update_post_meta( $legacy_post_id, \ExtraChillNetwork\OgCards\OgCardGenerationTask::META_SIGNATURE, 'stale-legacy-signature' );

	og_smoke_assert_same(
		$legacy_url,
		get_post_meta( $legacy_post_id, \ExtraChillNetwork\OgCards\OgCardGenerationTask::META_URL, true ),
		'A legacy stored URL is readable as-is by consumers (no changes needed on their side).'
	);

	$GLOBALS['og_smoke_event_name'] = 'Legacy Post Title';
	$legacy_result                  = \ExtraChillNetwork\OgCards\OgCardGenerationTask::render_for_post( $legacy_post );
	og_smoke_assert_same( '', $legacy_result['error'] ?? '', 'Legacy post regenerates successfully.' );
	og_smoke_assert_true( $legacy_result['cached_url'] !== $legacy_url, 'Legacy post gets a new content-addressed URL on regeneration.' );
	og_smoke_assert_true( ! file_exists( $legacy_path ), 'Legacy (non-addressed) file is deleted on regeneration, found via the stored URL rather than a directory glob.' );
	og_smoke_assert_true( file_exists( $legacy_result['cached_path'] ), 'New content-addressed file exists after migrating off the legacy scheme.' );

	// -------------------------------------------------------------
	// 6. Empty/unavailable signature still produces a usable key —
	//    never an empty trailing segment, never a fatal.
	// -------------------------------------------------------------
	$fallback_post = new \WP_Post( 12345, 'whatever_type' );
	$fallback_key  = \ExtraChillNetwork\OgCards\OgCardGenerationTask::cache_key_for( $fallback_post, '' );
	og_smoke_assert_same( 'b7-whatever_type-12345-nosig000', $fallback_key, 'Empty signature falls back to a fixed non-empty digest segment.' );
	og_smoke_assert_true( ! str_ends_with( $fallback_key, '-' ), 'Fallback key never ends with a bare/empty segment separator.' );

	// -------------------------------------------------------------
	// 7. Multisite: blog ID prefix is preserved and distinguishes
	//    otherwise-identical posts on different sites.
	// -------------------------------------------------------------
	$GLOBALS['og_smoke_blog_id'] = 11;
	$other_site_key              = \ExtraChillNetwork\OgCards\OgCardGenerationTask::cache_key_for( $fallback_post, 'abcdef1234567890' );
	$GLOBALS['og_smoke_blog_id'] = 7;
	$this_site_key               = \ExtraChillNetwork\OgCards\OgCardGenerationTask::cache_key_for( $fallback_post, 'abcdef1234567890' );
	og_smoke_assert_true( $other_site_key !== $this_site_key, 'Same post type/id on different blog ids does not collide.' );
	og_smoke_assert_true( str_starts_with( $other_site_key, 'b11-' ), 'Blog id prefix is preserved for multisite collision safety.' );
	og_smoke_assert_true( str_starts_with( $this_site_key, 'b7-' ), 'Blog id prefix is preserved for multisite collision safety.' );

	// -------------------------------------------------------------
	// 8. inspect_for_post() — read-only predicate extracted from
	//    render_for_post() for #250 (regenerate-og-card ability). Must
	//    report reality without writing anything or calling the render
	//    ability, and must agree exactly with what render_for_post()
	//    itself would do (same signature, same reuse decision).
	// -------------------------------------------------------------
	$calls_before = $GLOBALS['og_smoke_render_calls'];

	$inspect_post = new \WP_Post( 555001, 'og_smoke_test' );
	$GLOBALS['og_smoke_event_name'] = 'Inspect Me';

	$before = \ExtraChillNetwork\OgCards\OgCardGenerationTask::inspect_for_post( $inspect_post );
	og_smoke_assert_same( 'smoke_template', $before['template_id'], 'inspect_for_post() resolves the mapped template id.' );
	og_smoke_assert_same( '', $before['existing_url'], 'A never-rendered post has no existing URL.' );
	og_smoke_assert_true( ! $before['is_current'], 'A never-rendered post is never reported as current.' );
	og_smoke_assert_same( $calls_before, $GLOBALS['og_smoke_render_calls'], 'inspect_for_post() never calls the render ability.' );
	og_smoke_assert_true( ! file_exists( $before['cached_path'] ), 'inspect_for_post() never writes a file.' );

	// cached_url_for() must predict the exact URL render_for_post() lands on.
	$predicted_url = \ExtraChillNetwork\OgCards\OgCardGenerationTask::cached_url_for( $inspect_post, $before['signature'] );
	$actual        = \ExtraChillNetwork\OgCards\OgCardGenerationTask::render_for_post( $inspect_post );
	og_smoke_assert_same( $actual['cached_url'], $predicted_url, 'cached_url_for() predicts the exact URL render_for_post() produces (content addressing is deterministic).' );

	$after = \ExtraChillNetwork\OgCards\OgCardGenerationTask::inspect_for_post( $inspect_post );
	og_smoke_assert_true( $after['is_current'], 'After a real render, inspect_for_post() reports the card as current.' );
	og_smoke_assert_same( $actual['cached_url'], $after['existing_url'], 'inspect_for_post() reflects the just-written meta.' );

	// Changing the data must flip is_current back to false, without inspect_for_post() itself writing anything.
	$GLOBALS['og_smoke_event_name'] = 'Inspect Me — Changed';
	$calls_after_first_render       = $GLOBALS['og_smoke_render_calls'];
	$changed                        = \ExtraChillNetwork\OgCards\OgCardGenerationTask::inspect_for_post( $inspect_post );
	og_smoke_assert_true( ! $changed['is_current'], 'Changed data is reported as not current by inspection alone.' );
	og_smoke_assert_same( $calls_after_first_render, $GLOBALS['og_smoke_render_calls'], 'Detecting staleness via inspect_for_post() does not itself render anything.' );

	fwrite( STDOUT, "OK: og-card-content-addressed-cache-smoke.php\n" );
}

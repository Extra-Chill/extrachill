<?php
/**
 * Standalone tests for the regenerate-og-card ability (#250).
 *
 * Verifies:
 *  1. A single post regenerates and the new URL differs from the old.
 *  2. dry_run writes nothing — the on-disk file mtime is unchanged.
 *  3. Without force, an unchanged post is reported reused and NOT rewritten.
 *  4. With force, the same (still-unchanged) post regenerates anyway.
 *  5. An ineligible post type is skipped and counted, and the run succeeds.
 *  6. A bulk run over a bounded set completes and reports per-card old/new
 *     URLs, plus has_more/next_offset pagination across a second page.
 *  7. Permission gating: logged-out and unprivileged users are denied;
 *     a manage_options user is allowed.
 *  8. Missing-target input is a validation error, not a crash.
 *
 * @package ExtraChillNetwork\Abilities
 */

declare( strict_types=1 );

namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	function ogr_smoke_assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
			exit( 1 );
		}
	}

	function ogr_smoke_assert_true( $actual, string $message ): void {
		ogr_smoke_assert_same( true, (bool) $actual, $message );
	}

	// -------------------------------------------------------------------
	// Minimal WordPress + Data Machine stubs. Only what og-card-task.php,
	// data-resolver.php, and OgCardRegenerationAbility.php actually call.
	// -------------------------------------------------------------------

	$GLOBALS['ogr_filters']       = array();
	$GLOBALS['ogr_post_meta']     = array();
	$GLOBALS['ogr_posts']         = array();
	$GLOBALS['ogr_blog_id']       = 7;
	$GLOBALS['ogr_render_calls']  = 0;
	$GLOBALS['ogr_uploads_base']  = sys_get_temp_dir() . '/og-card-regen-smoke-' . uniqid();
	$GLOBALS['ogr_current_user']  = false; // false = logged out, or an array of capabilities.
	$GLOBALS['ogr_blog_switches'] = array();

	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ogr_filters'][ $hook ][] = array( $callback, $accepted_args );
	}

	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ogr_filters'][ $hook ] ?? array() as list( $callback, $accepted_args ) ) {
			$all   = array_merge( array( $value ), $args );
			$value = $callback( ...array_slice( $all, 0, $accepted_args ) );
		}
		return $value;
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ogr_actions'][ $hook ][] = $callback;
	}

	function get_the_terms( $post_id, $taxonomy ) {
		return false;
	}

	function get_current_blog_id() {
		return $GLOBALS['ogr_blog_id'];
	}

	function is_multisite() {
		return true;
	}

	function switch_to_blog( $blog_id ) {
		$GLOBALS['ogr_blog_switches'][] = array( 'to', $blog_id );
		$GLOBALS['ogr_blog_id']         = $blog_id;
	}

	function restore_current_blog() {
		$GLOBALS['ogr_blog_switches'][] = array( 'restore' );
		$GLOBALS['ogr_blog_id']         = 7;
	}

	function is_user_logged_in() {
		return false !== $GLOBALS['ogr_current_user'];
	}

	function current_user_can( $cap ) {
		if ( false === $GLOBALS['ogr_current_user'] ) {
			return false;
		}
		return in_array( $cap, $GLOBALS['ogr_current_user'], true );
	}

	function get_post( $post_id ) {
		return $GLOBALS['ogr_posts'][ $post_id ] ?? null;
	}

	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['ogr_post_meta'][ $post_id ][ $key ] ?? '';
	}

	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['ogr_post_meta'][ $post_id ][ $key ] = $value;
	}

	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['ogr_post_meta'][ $post_id ][ $key ] );
	}

	function wp_upload_dir() {
		return array(
			'basedir' => $GLOBALS['ogr_uploads_base'],
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

	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}

	function wp_delete_file( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}

	function __( $text, $domain = 'default' ) {
		return $text;
	}

	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}

	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}

	class OgrSmokeImageTemplateAbility {
		public function execute( array $input ) {
			$GLOBALS['ogr_render_calls']++;

			$bucket = sanitize_file_name( (string) $input['cache']['bucket'] );
			$key    = sanitize_file_name( (string) $input['cache']['key'] );
			$ext    = (string) $input['format'];

			$bucket_dir = trailingslashit( $GLOBALS['ogr_uploads_base'] ) . $bucket;
			wp_mkdir_p( $bucket_dir );

			$filename  = $key . '.' . $ext;
			$dest_path = trailingslashit( $bucket_dir ) . $filename;
			$dest_url  = 'https://example.test/wp-content/uploads/' . $bucket . '/' . $filename;

			file_put_contents( $dest_path, 'render-' . $GLOBALS['ogr_render_calls'] . ':' . wp_json_encode( $input['data'] ) );

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
		return new OgrSmokeImageTemplateAbility();
	}

	// Ability registration capture (register() is invoked directly by the
	// test, not through a real wp_abilities_api_init hook cycle).
	$GLOBALS['ogr_registered_abilities'] = array();
	function wp_register_ability( $slug, $args ) {
		$GLOBALS['ogr_registered_abilities'][ $slug ] = $args;
	}

	function ogr_smoke_rmrf( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? ogr_smoke_rmrf( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	register_shutdown_function( 'ogr_smoke_rmrf', $GLOBALS['ogr_uploads_base'] );
}

// Minimal SystemTask stand-in — same as the content-addressed-cache smoke test.
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

	/**
	 * Minimal WP_Query stand-in: filters an in-memory post table by
	 * post_type IN (...) and a hard-coded "meta EXISTS" semantics matching
	 * the only meta_query the ability ever issues (og card URL exists).
	 * Sorting is by ID ascending, matching the ability's explicit orderby.
	 */
	class WP_Query {
		public array $posts = array();

		public function __construct( array $args ) {
			$GLOBALS['ogr_queries'][] = $args;

			$types = (array) ( $args['post_type'] ?? array() );
			$all   = $GLOBALS['ogr_posts'];

			$matches = array();
			foreach ( $all as $post ) {
				if ( ! in_array( $post->post_type, $types, true ) ) {
					continue;
				}
				if ( '' === (string) ( $GLOBALS['ogr_post_meta'][ $post->ID ][ \ExtraChillNetwork\OgCards\OgCardGenerationTask::META_URL ] ?? '' ) ) {
					continue; // meta_query EXISTS semantics.
				}
				$matches[] = $post->ID;
			}

			sort( $matches, SORT_NUMERIC );

			$offset = (int) ( $args['offset'] ?? 0 );
			$limit  = (int) ( $args['posts_per_page'] ?? count( $matches ) );

			$this->posts = array_slice( $matches, $offset, $limit );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/og-cards/data-resolver.php';
	require_once dirname( __DIR__ ) . '/inc/og-cards/og-card-task.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/OgCardRegenerationAbility.php';

	use ExtraChillNetwork\Abilities\OgCardRegenerationAbility;
	use ExtraChillNetwork\OgCards\OgCardGenerationTask;

	// Route a dedicated test post type through the template map + data
	// collector filters, mirroring the content-addressed-cache smoke test.
	add_filter(
		'extrachill_og_card_template_map',
		function ( array $map ): array {
			$map['ogr_smoke_test'] = 'smoke_template';
			return $map;
		}
	);

	add_filter(
		'extrachill_og_card_data_ogr_smoke_test',
		function ( array $data, \WP_Post $post ): array {
			$data['event_name'] = $GLOBALS['ogr_event_names'][ $post->ID ] ?? 'Untitled';
			return $data;
		},
		10,
		2
	);

	function ogr_seed_post( int $id, string $type, string $name ): \WP_Post {
		$post                              = new \WP_Post( $id, $type );
		$GLOBALS['ogr_posts'][ $id ]       = $post;
		$GLOBALS['ogr_event_names'][ $id ] = $name;
		return $post;
	}

	$ability = new OgCardRegenerationAbility();
	$ability->register();

	ogr_smoke_assert_true(
		isset( $GLOBALS['ogr_registered_abilities']['extrachill/regenerate-og-card'] ),
		'The ability registers under the extrachill/regenerate-og-card slug.'
	);
	ogr_smoke_assert_true(
		! empty( $GLOBALS['ogr_registered_abilities']['extrachill/regenerate-og-card']['meta']['annotations']['destructive'] ),
		'The ability is annotated destructive (writes files, deletes the previous cache file).'
	);

	// -------------------------------------------------------------
	// 7. Permission gating — checked first since every other scenario
	//    below calls execute() directly (bypassing the ability runner's
	//    own permission check), so this is the only place it's verified.
	// -------------------------------------------------------------
	$GLOBALS['ogr_current_user'] = false;
	ogr_smoke_assert_true( ! $ability->permissionCallback(), 'Logged-out users are denied.' );

	$GLOBALS['ogr_current_user'] = array( 'read' );
	ogr_smoke_assert_true( ! $ability->permissionCallback(), 'A logged-in user without manage_options/manage_network is denied.' );

	$GLOBALS['ogr_current_user'] = array( 'manage_options' );
	ogr_smoke_assert_true( $ability->permissionCallback(), 'A manage_options user is allowed — gated as a mutating operation, not a public read.' );

	$GLOBALS['ogr_current_user'] = array( 'manage_network' );
	ogr_smoke_assert_true( $ability->permissionCallback(), 'A manage_network (super admin) user is allowed.' );

	// -------------------------------------------------------------
	// 8. Missing target — must be a clean validation error, not a fatal.
	// -------------------------------------------------------------
	$missing_target = $ability->execute( array() );
	ogr_smoke_assert_true( $missing_target instanceof \WP_Error, 'No selector at all is a WP_Error, not a silent no-op or a fatal.' );
	ogr_smoke_assert_same( 'missing_target', $missing_target->get_error_code(), 'Missing-target error uses a stable, identifiable error code.' );

	// -------------------------------------------------------------
	// 1–4. Single post: first render, dry-run no-op, unforced reuse,
	//      forced regeneration.
	// -------------------------------------------------------------
	$post_a = ogr_seed_post( 486727, 'ogr_smoke_test', 'Original Title' );

	// 1. First real regeneration produces a new (previously nonexistent) URL.
	$calls_before = $GLOBALS['ogr_render_calls'];
	$result1      = $ability->execute( array( 'post_id' => 486727 ) );
	ogr_smoke_assert_true( ! ( $result1 instanceof \WP_Error ), 'Single-post regeneration succeeds.' );
	ogr_smoke_assert_same( 1, $result1['regenerated'], 'First regeneration counts as regenerated, not reused.' );
	ogr_smoke_assert_same( 1, count( $result1['cards'] ), 'Exactly one card entry for a single-post call.' );
	ogr_smoke_assert_same( '', $result1['cards'][0]['old_url'], 'No prior URL existed before the first render.' );
	ogr_smoke_assert_true( '' !== $result1['cards'][0]['new_url'], 'A new URL is reported after the first render.' );
	ogr_smoke_assert_same( $calls_before + 1, $GLOBALS['ogr_render_calls'], 'A real (non-dry-run) regeneration actually calls the render ability.' );

	$url_v1 = $result1['cards'][0]['new_url'];
	$path_v1 = OgCardGenerationTask::inspect_for_post( $post_a )['cached_path'];
	$mtime_v1 = filemtime( $path_v1 );

	// 2. dry_run reports without writing: mtime unchanged, no render call.
	sleep( 1 ); // Ensure a rewrite (if one incorrectly happened) would be detectable via mtime.
	$calls_before  = $GLOBALS['ogr_render_calls'];
	$dry_unchanged = $ability->execute( array( 'post_id' => 486727, 'dry_run' => true ) );
	ogr_smoke_assert_same( $calls_before, $GLOBALS['ogr_render_calls'], 'dry_run never calls the render ability.' );
	ogr_smoke_assert_same( $mtime_v1, filemtime( $path_v1 ), 'dry_run does not touch the existing file (mtime unchanged).' );
	ogr_smoke_assert_true( $dry_unchanged['cards'][0]['reused'], 'dry_run on an unchanged, unforced post reports reused.' );
	ogr_smoke_assert_same( $url_v1, $dry_unchanged['cards'][0]['new_url'], 'dry_run reports the existing URL as the (unchanged) predicted new URL.' );

	// 2b. dry_run WOULD regenerate under force, and predicts the exact URL
	// a real forced render lands on — without writing or rendering.
	$dry_forced = $ability->execute( array( 'post_id' => 486727, 'dry_run' => true, 'force' => true ) );
	ogr_smoke_assert_same( $calls_before, $GLOBALS['ogr_render_calls'], 'dry_run+force still never calls the render ability.' );
	ogr_smoke_assert_same( $mtime_v1, filemtime( $path_v1 ), 'dry_run+force still does not touch the existing file.' );
	ogr_smoke_assert_true( $dry_forced['cards'][0]['regenerated'], 'dry_run+force reports what WOULD regenerate.' );
	$predicted_forced_url = $dry_forced['cards'][0]['new_url'];

	// 3. Without force, a real run on unchanged data reuses — does not rewrite.
	$calls_before = $GLOBALS['ogr_render_calls'];
	$result_reuse = $ability->execute( array( 'post_id' => 486727 ) );
	ogr_smoke_assert_same( $calls_before, $GLOBALS['ogr_render_calls'], 'Unforced regeneration of unchanged data does not call the render ability.' );
	ogr_smoke_assert_same( $mtime_v1, filemtime( $path_v1 ), 'Unforced regeneration of unchanged data does not rewrite the file.' );
	ogr_smoke_assert_same( 1, $result_reuse['reused'], 'Unforced regeneration of unchanged data is counted as reused.' );
	ogr_smoke_assert_same( 0, $result_reuse['regenerated'], 'Unforced regeneration of unchanged data is not counted as regenerated.' );
	ogr_smoke_assert_same( $url_v1, $result_reuse['cards'][0]['new_url'], 'Reused card reports the same URL as old_url.' );

	// 4. With force, the SAME unchanged post regenerates anyway — this is
	//    the central feature: a rendering fix ships with no data change,
	//    so only force reaches an already-cached card. Content addressing
	//    ties the URL to the DATA signature, not to force — since the data
	//    is unchanged, the new render lands at the SAME URL/path (this is
	//    exactly the scenario in extrachill-network#250: the signature
	//    doesn't move, only the renderer changed) and the file is
	//    overwritten in place rather than replaced at a new path.
	$content_before_force = file_get_contents( $path_v1 );
	$calls_before          = $GLOBALS['ogr_render_calls'];
	$result_forced         = $ability->execute( array( 'post_id' => 486727, 'force' => true ) );
	ogr_smoke_assert_same( $calls_before + 1, $GLOBALS['ogr_render_calls'], 'force actually calls the render ability even though data is unchanged.' );
	ogr_smoke_assert_same( 1, $result_forced['regenerated'], 'Forced regeneration is counted as regenerated, not reused.' );
	ogr_smoke_assert_same( $url_v1, $result_forced['cards'][0]['old_url'], 'Forced regeneration reports the previous URL as old_url.' );
	ogr_smoke_assert_same( $predicted_forced_url, $result_forced['cards'][0]['new_url'], 'The dry-run prediction for the forced regeneration matches what actually happened.' );
	ogr_smoke_assert_same( $url_v1, $result_forced['cards'][0]['new_url'], 'Unchanged data means the same content-addressed URL even after a forced regeneration.' );
	ogr_smoke_assert_true( file_exists( $path_v1 ), 'The file at the (unchanged) content-addressed path still exists after a forced regeneration.' );
	ogr_smoke_assert_true( file_get_contents( $path_v1 ) !== $content_before_force, 'The file content is actually rewritten by the forced regeneration (this is the fix reaching the card), not left untouched.' );

	// -------------------------------------------------------------
	// 5. Ineligible post type: skipped and counted, run still succeeds.
	// -------------------------------------------------------------
	$post_ineligible = ogr_seed_post( 900001, 'not_a_card_type', 'N/A' );
	update_post_meta( 900001, OgCardGenerationTask::META_URL, 'https://example.test/wp-content/uploads/og-cards/stale.png' );

	$ineligible_result = $ability->execute( array( 'post_id' => 900001 ) );
	ogr_smoke_assert_true( ! ( $ineligible_result instanceof \WP_Error ), 'An ineligible post type does not error the call.' );
	ogr_smoke_assert_same( 1, $ineligible_result['skipped_ineligible'], 'An ineligible post type is counted as skipped.' );
	ogr_smoke_assert_true( ! empty( $ineligible_result['cards'][0]['skipped'] ), 'The card entry itself is flagged skipped.' );
	ogr_smoke_assert_same( 'ineligible_post_type', $ineligible_result['cards'][0]['reason'], 'The skip reason identifies why.' );
	ogr_smoke_assert_same( array(), $ineligible_result['errors'], 'An ineligible post type is a skip, never an error.' );

	// -------------------------------------------------------------
	// 6. Bulk run: bounded set, per-card old/new URLs, pagination.
	// -------------------------------------------------------------
	// Seed 5 posts of a second eligible type, each already carrying a card
	// (meta EXISTS) so the meta-query candidate pool picks them all up.
	add_filter(
		'extrachill_og_card_template_map',
		function ( array $map ): array {
			$map['ogr_bulk_test'] = 'smoke_template';
			return $map;
		}
	);
	add_filter(
		'extrachill_og_card_data_ogr_bulk_test',
		function ( array $data, \WP_Post $post ): array {
			$data['event_name'] = $GLOBALS['ogr_event_names'][ $post->ID ] ?? 'Untitled';
			return $data;
		},
		10,
		2
	);

	$bulk_ids = array( 700001, 700002, 700003, 700004, 700005 );
	foreach ( $bulk_ids as $i => $id ) {
		ogr_seed_post( $id, 'ogr_bulk_test', 'Bulk Post ' . $i );
		// Give each an existing (stale, pre-fix) card so it is a candidate.
		update_post_meta( $id, OgCardGenerationTask::META_URL, "https://example.test/wp-content/uploads/og-cards/stale-{$id}.png" );
		update_post_meta( $id, OgCardGenerationTask::META_SIGNATURE, 'stale-signature-that-will-never-match' );
	}

	// Page 1: limit=3 over 5 candidates -> has_more, next_offset=3.
	$page1 = $ability->execute(
		array(
			'post_type' => 'ogr_bulk_test',
			'force'     => true,
			'limit'     => 3,
			'offset'    => 0,
		)
	);
	ogr_smoke_assert_true( ! ( $page1 instanceof \WP_Error ), 'Bulk post_type regeneration succeeds.' );
	ogr_smoke_assert_same( 3, $page1['requested'], 'Page 1 is bounded to the requested limit.' );
	ogr_smoke_assert_same( 3, $page1['regenerated'], 'All 3 candidates in page 1 regenerate (force=true, stale signature).' );
	ogr_smoke_assert_true( $page1['has_more'], 'Page 1 reports more candidates remain.' );
	ogr_smoke_assert_same( 3, $page1['next_offset'], 'next_offset correctly resumes after page 1.' );
	foreach ( $page1['cards'] as $card ) {
		ogr_smoke_assert_true( str_starts_with( (string) $card['old_url'], 'https://example.test/wp-content/uploads/og-cards/stale-' ), 'Bulk card reports the pre-regeneration (stale) URL as old_url.' );
		ogr_smoke_assert_true( ! str_starts_with( (string) $card['new_url'], 'https://example.test/wp-content/uploads/og-cards/stale-' ), 'Bulk card reports a genuinely new URL, not the stale one.' );
	}

	// Page 2: the remaining 2.
	$page2 = $ability->execute(
		array(
			'post_type' => 'ogr_bulk_test',
			'force'     => true,
			'limit'     => 3,
			'offset'    => $page1['next_offset'],
		)
	);
	ogr_smoke_assert_same( 2, $page2['requested'], 'Page 2 picks up exactly the remaining candidates.' );
	ogr_smoke_assert_true( ! $page2['has_more'], 'Page 2 reports no further candidates.' );
	// next_offset is declared as an integer in the output schema (#253) — 0,
	// not null, once the candidate set is exhausted. Consumers must key off
	// has_more, never off next_offset's truthiness, to decide whether to page.
	ogr_smoke_assert_same( 0, $page2['next_offset'], 'next_offset is 0 once the candidate set is exhausted.' );

	// A follow-up unforced pass over the whole (now-current) set reuses everything.
	$reuse_pass = $ability->execute(
		array(
			'post_type' => 'ogr_bulk_test',
			'limit'     => 10,
		)
	);
	ogr_smoke_assert_same( 5, $reuse_pass['reused'], 'A subsequent unforced bulk pass reuses every now-current card.' );
	ogr_smoke_assert_same( 0, $reuse_pass['regenerated'], 'A subsequent unforced bulk pass regenerates nothing.' );

	fwrite( STDOUT, "OK: og-card-regeneration-ability-smoke.php\n" );
}

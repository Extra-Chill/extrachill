<?php
/** Standalone multisite contract tests for approved network term classification. */

declare( strict_types=1 );

namespace DataMachine\Core {
	class PluginSettings {
		public static bool $enabled = true;
		public static function get( string $key, $default = null ) { return self::$enabled; }
		public static function resolveModelForAgentMode( int $agent_id = 0, string $mode = 'system' ): array { return array( 'provider' => 'test', 'model' => 'test' ); }
	}
	class JobStatus {
		public static function isStatusFinal( string $status ): bool { return in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ); }
	}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public static array $children = array();
		public function get_job( int $job_id ): ?array { return \DataMachine\Engine\Tasks\TaskScheduler::$jobs[ $job_id ] ?? null; }
		public function get_children( int $job_id ): array { return self::$children[ $job_id ] ?? array(); }
	}
}

namespace DataMachine\Engine\AI {
	class ConversationManager {
		public static function buildConversationMessage( string $role, string $content ): array { return compact( 'role', 'content' ); }
	}
	class RequestBuilder {
		public static $response = '[]';
		public static function build() { return self::$response; }
		public static function resultText( $response ): string { return (string) $response; }
	}
}

namespace DataMachine\Engine\AI\System\Tasks {
	abstract class SystemTask {
		public static array $completed = array();
		public static array $failed = array();
		abstract public function executeTask( int $job_id, array $params ): void;
		abstract public function getTaskType(): string;
		protected function completeJob( int $job_id, array $data ): void { self::$completed[ $job_id ] = $data; }
		protected function failJob( int $job_id, string $message ): void { self::$failed[ $job_id ] = $message; }
		protected function resolveSystemModel( array $params ): array { return array( 'provider' => 'test', 'model' => 'test' ); }
	}
}

namespace DataMachine\Engine\Tasks {
	class TaskScheduler {
		public static array $jobs = array();
		public static array $keys = array();
		public static function schedule( string $type, array $params, array $context = array(), int $parent = 0, string $key = '' ) {
			if ( isset( self::$keys[ $key ] ) ) {
				return self::$keys[ $key ];
			}
			$id                 = count( self::$jobs ) + 1;
			self::$keys[ $key ] = $id;
			self::$jobs[ $id ]  = array_merge( compact( 'type', 'params', 'context', 'parent', 'key' ), array( 'job_id' => $id, 'status' => 'pending' ) );
			return $id;
		}
		public static function getLastScheduleError(): ?array { return null; }
	}
}

namespace {
	// phpcs:disable -- WordPress standalone mocks intentionally share one file.
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = null ) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_data( string $code = '' ) { return is_array( $this->data ) ? ( $this->data[ $code ] ?? $this->data ) : $this->data; }
	}

	class WP_Post {
		public function __construct(
			public int $ID,
			public string $post_type,
			public string $post_status,
			public string $post_title,
			public string $post_content
		) {}
	}

	class WP_Term {
		public function __construct(
			public int $term_id,
			public string $slug,
			public string $name,
			public string $description = '',
			public int $parent = 0
		) {}
	}

	$GLOBALS['ntc_blog_id']       = 2;
	$GLOBALS['ntc_blog_stack']    = array();
	$GLOBALS['ntc_terms']         = array();
	$GLOBALS['ntc_posts']         = array();
	$GLOBALS['ntc_meta']          = array();
	$GLOBALS['ntc_relationships'] = array();
	$GLOBALS['ntc_post_types']    = array( 'topic', 'post', 'festival_wire' );
	$GLOBALS['ntc_taxonomies']    = array(
		'topic'         => array( 'artist', 'festival', 'location' ),
		'post'          => array( 'artist', 'festival', 'location' ),
		'festival_wire' => array( 'festival', 'location' ),
	);
	$GLOBALS['ntc_autosaves']     = array();
	$GLOBALS['ntc_revisions']     = array();
	$GLOBALS['ntc_filters']       = array();
	$GLOBALS['ntc_actions']       = array();
	$GLOBALS['ntc_abilities']     = array();
	$GLOBALS['ntc_logged_in']     = true;
	$GLOBALS['ntc_can_edit']      = true;
	$GLOBALS['ntc_cross_site']    = array();
	$GLOBALS['ntc_cross_result']  = array( 'routed' => true );

	function __( string $text ): string { return $text; }
	function absint( $value ): int { return abs( (int) $value ); }
	function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
	function sanitize_title( $value ): string { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ), '-' ) ); }
	function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
	function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
	function strip_shortcodes( $value ): string { return (string) $value; }
	function wp_json_encode( $value ): string { return (string) json_encode( $value ); }
	function wp_generate_uuid4(): string { return 'force-' . ( count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ) + 1 ); }
	function wp_trim_words( string $text, int $num_words, string $more = '...' ): string { return implode( ' ', array_slice( preg_split( '/\s+/', $text ), 0, $num_words ) ); }
	function current_time(): string { return '2026-07-28 12:00:00'; }
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function get_current_blog_id(): int { return $GLOBALS['ntc_blog_id']; }
	function switch_to_blog( $blog_id ): bool {
		$GLOBALS['ntc_blog_stack'][] = $GLOBALS['ntc_blog_id'];
		$GLOBALS['ntc_blog_id']      = (int) $blog_id;
		return true;
	}
	function restore_current_blog(): bool {
		$GLOBALS['ntc_blog_id'] = (int) array_pop( $GLOBALS['ntc_blog_stack'] );
		return true;
	}
	function ec_get_blog_id( string $key ): int { return array( 'main' => 1, 'community' => 2, 'events' => 7, 'wire' => 11, 'studio' => 12 )[ $key ] ?? 0; }
	function extrachill_get_current_site_key(): ?string { return array( 1 => 'main', 2 => 'community', 7 => 'events', 11 => 'wire', 12 => 'studio' )[ get_current_blog_id() ] ?? null; }
	function taxonomy_exists( string $taxonomy ): bool { return in_array( $taxonomy, array( 'artist', 'festival', 'location' ), true ); }
	function post_type_exists( string $post_type ): bool { return in_array( $post_type, $GLOBALS['ntc_post_types'], true ); }
	function is_object_in_taxonomy( string $post_type, string $taxonomy ): bool { return in_array( $taxonomy, $GLOBALS['ntc_taxonomies'][ $post_type ] ?? array(), true ); }
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ntc_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
	function add_filter( string $hook, $callback ): void { $GLOBALS['ntc_filters'][ $hook ][] = $callback; }
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['ntc_actions'][ $hook ][] = $callback; }
	function wp_register_ability( string $name, array $args ): void { $GLOBALS['ntc_abilities'][ $name ] = $args; }
	function is_user_logged_in(): bool { return $GLOBALS['ntc_logged_in']; }
	function get_current_user_id(): int { return $GLOBALS['ntc_logged_in'] ? 77 : 0; }
	function current_user_can( string $capability, ...$args ): bool { return $GLOBALS['ntc_can_edit']; }
	function extrachill_community_ability_update_topic_permission( array $input ) { return $GLOBALS['ntc_can_edit']; }
	function ec_cross_site_rest_request_http( string $site, string $method, string $route, array $args ) {
		$GLOBALS['ntc_cross_site'][] = compact( 'site', 'method', 'route', 'args' );
		return $GLOBALS['ntc_cross_result'];
	}
	function wp_is_post_autosave( int $post_id ) { return in_array( $post_id, $GLOBALS['ntc_autosaves'], true ) ? $post_id : false; }
	function wp_is_post_revision( int $post_id ) { return in_array( $post_id, $GLOBALS['ntc_revisions'], true ) ? $post_id : false; }
	function get_post( int $post_id ) { return $GLOBALS['ntc_posts'][ get_current_blog_id() ][ $post_id ] ?? null; }
	function get_post_meta( int $post_id, string $key, bool $single = false ) { return $GLOBALS['ntc_meta'][ get_current_blog_id() ][ $post_id ][ $key ] ?? ''; }
	function update_post_meta( int $post_id, string $key, $value ): bool { $GLOBALS['ntc_meta'][ get_current_blog_id() ][ $post_id ][ $key ] = $value; return true; }
	function delete_post_meta( int $post_id, string $key ): bool { unset( $GLOBALS['ntc_meta'][ get_current_blog_id() ][ $post_id ][ $key ] ); return true; }
	function get_term( int $term_id, string $taxonomy ) {
		foreach ( $GLOBALS['ntc_terms'][ get_current_blog_id() ][ $taxonomy ] ?? array() as $term ) {
			if ( $term->term_id === $term_id ) {
				return $term;
			}
		}
		return false;
	}
	function get_term_by( string $field, $value, string $taxonomy ) {
		foreach ( $GLOBALS['ntc_terms'][ get_current_blog_id() ][ $taxonomy ] ?? array() as $term ) {
			if ( ( 'slug' === $field && $term->slug === $value ) || ( 'id' === $field && $term->term_id === (int) $value ) ) {
				return $term;
			}
		}
		return false;
	}
	function get_terms( array $args ) {
		$terms = array_values( $GLOBALS['ntc_terms'][ get_current_blog_id() ][ $args['taxonomy'] ] ?? array() );
		if ( ! empty( $args['search'] ) ) {
			$query = strtolower( (string) $args['search'] );
			$terms = array_values( array_filter( $terms, static fn( WP_Term $term ): bool => str_contains( strtolower( $term->name ), $query ) || str_contains( $term->slug, sanitize_title( $query ) ) ) );
		}
		return array_slice( $terms, 0, (int) ( $args['number'] ?? count( $terms ) ) );
	}
	function wp_insert_term( string $name, string $taxonomy, array $args ) {
		$existing = get_term_by( 'slug', $args['slug'], $taxonomy );
		if ( $existing ) {
			return new WP_Error( 'term_exists', 'Exists', array( 'term_exists' => $existing->term_id ) );
		}
		$all = $GLOBALS['ntc_terms'][ get_current_blog_id() ][ $taxonomy ] ?? array();
		$id  = 1000 + count( $all );
		$GLOBALS['ntc_terms'][ get_current_blog_id() ][ $taxonomy ][ $args['slug'] ] = new WP_Term( $id, $args['slug'], $name, $args['description'] ?? '', (int) ( $args['parent'] ?? 0 ) );
		return array( 'term_id' => $id );
	}
	function wp_get_object_terms( int $post_id, string $taxonomy ) {
		$terms = array();
		foreach ( $GLOBALS['ntc_relationships'][ get_current_blog_id() ][ $post_id ][ $taxonomy ] ?? array() as $term_id ) {
			$term = get_term( (int) $term_id, $taxonomy );
			if ( $term ) {
				$terms[] = $term;
			}
		}
		return $terms;
	}
	function wp_set_object_terms( int $post_id, array $term_ids, string $taxonomy ) {
		$GLOBALS['ntc_relationships'][ get_current_blog_id() ][ $post_id ][ $taxonomy ] = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		return $term_ids;
	}

	require_once dirname( __DIR__ ) . '/inc/taxonomy/network-terms.php';
	require_once dirname( __DIR__ ) . '/inc/taxonomy/term-classification.php';
	require_once dirname( __DIR__ ) . '/inc/taxonomy/term-classification-task.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/NetworkTermAbilities.php';

	function ntc_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
	function ntc_assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}
	function ntc_slugs( int $blog_id, int $post_id, string $taxonomy ): array {
		$previous = get_current_blog_id();
		switch_to_blog( $blog_id );
		$slugs = array_map( static fn( WP_Term $term ): string => $term->slug, wp_get_object_terms( $post_id, $taxonomy ) );
		restore_current_blog();
		ntc_assert_same( $previous, get_current_blog_id(), 'Term lookup restores blog context.' );
		sort( $slugs );
		return $slugs;
	}
	function ntc_reset_scheduler(): void {
		\DataMachine\Engine\Tasks\TaskScheduler::$jobs = array();
		\DataMachine\Engine\Tasks\TaskScheduler::$keys = array();
		\DataMachine\Core\Database\Jobs\Jobs::$children = array();
		unset( $GLOBALS['ntc_meta'][ get_current_blog_id() ][50][EXTRACHILL_NETWORK_TERM_CLASSIFICATION_JOBS_META] );
	}

	// Approved source policy excludes Events' broad scraper union.
	$policy = extrachill_network_get_term_policy();
	ntc_assert_same( array( 'main' ), $policy['sources']['artist'], 'Artist identity comes only from the curated Main taxonomy.' );
	foreach ( array( 'artist', 'festival', 'location' ) as $scraper_union_taxonomy ) {
		ntc_assert( ! in_array( 'events', $policy['sources'][ $scraper_union_taxonomy ], true ), "Events is not an approved source for {$scraper_union_taxonomy}." );
	}
	ntc_assert_same( array( 'events' ), $policy['sources']['venue'], 'Venue identity is owned by the Events site.' );

	$GLOBALS['ntc_terms'][1]['artist']['phish']       = new WP_Term( 10, 'phish', 'Phish', 'Jam band' );
	$GLOBALS['ntc_terms'][1]['artist']['radiohead']   = new WP_Term( 13, 'radiohead', 'Radiohead', 'Rock band' );
	$GLOBALS['ntc_terms'][1]['festival']['bonnaroo']  = new WP_Term( 11, 'bonnaroo', 'Bonnaroo' );
	$GLOBALS['ntc_terms'][1]['location']['charleston'] = new WP_Term( 12, 'charleston', 'Charleston' );
	$GLOBALS['ntc_terms'][11]['festival']['bonnaroo'] = new WP_Term( 210, 'bonnaroo', 'Bonnaroo', 'Editorial festival' );
	$GLOBALS['ntc_terms'][7]['artist']['noise-act']   = new WP_Term( 70001, 'noise-act', 'Noise Act' );
	$GLOBALS['ntc_terms'][2]['location']['charleston'] = new WP_Term( 201, 'charleston', 'Charleston' );
	$GLOBALS['ntc_terms'][2]['festival']['oldfest']   = new WP_Term( 202, 'oldfest', 'Oldfest' );
	$GLOBALS['ntc_terms'][2]['artist']['rogue-local'] = new WP_Term( 203, 'rogue-local', 'Rogue Local' );
	$GLOBALS['ntc_terms'][2]['artist']['human-added'] = new WP_Term( 204, 'human-added', 'Human Added' );
	$GLOBALS['ntc_posts'][2][50] = new WP_Post( 50, 'topic', 'publish', 'Phish at Bonnaroo', 'Charleston fans discuss the Phish set at Bonnaroo.' );
	$GLOBALS['ntc_relationships'][2][50] = array( 'location' => array( 201 ), 'festival' => array( 202 ), 'artist' => array() );
	$GLOBALS['ntc_meta'][2][50][EXTRACHILL_NETWORK_TERM_PROVENANCE_META] = array(
		'schema_version' => 1,
		'fingerprints'   => array( 'festival' => 'old' ),
		'terms'          => array( 'festival' => array( 'oldfest' ) ),
	);

	$search = extrachill_network_search_terms( 'artist', 'Noise', 20 );
	ntc_assert_same( array(), $search, 'Unapproved Events terms never appear in registry search.' );
	$search = extrachill_network_search_terms( 'festival', 'Bonnaroo', 20 );
	ntc_assert_same( 'wire', $search[0]['source'], 'Festival search honors trusted source priority and de-duplicates identity.' );

	$start_blog = get_current_blog_id();
	$projected  = extrachill_network_project_term( 'community', 'topic', 'artist', 'phish' );
	ntc_assert( ! is_wp_error( $projected ) && $projected['term_id'] !== 10, 'Projection creates a target-local term ID from taxonomy and slug.' );
	ntc_assert_same( $start_blog, get_current_blog_id(), 'Projection restores nested multisite context.' );
	$rejected = extrachill_network_project_term( 'community', 'topic', 'artist', 'noise-act' );
	ntc_assert( is_wp_error( $rejected ), 'Projection cannot mint an arbitrary or unapproved identity.' );
	$rejected_existing = extrachill_network_project_term( 'community', 'topic', 'artist', 'rogue-local' );
	ntc_assert( is_wp_error( $rejected_existing ), 'An existing local slug is still rejected unless an approved source validates it.' );

	$selector_calls = 0;
	$result = extrachill_network_classify_post_terms(
		array( 'site' => 'community', 'post_id' => 50 ),
		static function ( WP_Post $post, array $candidates ) use ( &$selector_calls ): array {
			++$selector_calls;
			return array(
				array( 'taxonomy' => 'artist', 'slug' => 'phish', 'confidence' => 0.99 ),
				array( 'taxonomy' => 'festival', 'slug' => 'bonnaroo', 'confidence' => 0.96 ),
				array( 'taxonomy' => 'location', 'slug' => 'charleston', 'confidence' => 0.50 ),
			);
		}
	);
	ntc_assert( ! is_wp_error( $result ), 'Eligible topic classification succeeds.' );
	ntc_assert_same( array( 'charleston' ), ntc_slugs( 2, 50, 'location' ), 'Existing human location survives low-confidence AI output.' );
	ntc_assert_same( array( 'bonnaroo' ), ntc_slugs( 2, 50, 'festival' ), 'Prior AI festival is replaced by a high-confidence approved identity.' );
	ntc_assert_same( array( 'phish' ), ntc_slugs( 2, 50, 'artist' ), 'Missing local artist projection is assigned by local ID.' );

	// Undo reverses only classifier deltas and preserves later human edits.
	$GLOBALS['ntc_posts'][2][51] = new WP_Post( 51, 'topic', 'publish', 'Phish at Bonnaroo', 'Charleston fans discuss the Phish set at Bonnaroo.' );
	$GLOBALS['ntc_relationships'][2][51] = array( 'location' => array( 201 ), 'festival' => array( 202 ), 'artist' => array() );
	$GLOBALS['ntc_meta'][2][51][EXTRACHILL_NETWORK_TERM_PROVENANCE_META] = array(
		'schema_version' => 1,
		'fingerprints'   => array( 'festival' => 'old' ),
		'terms'          => array( 'festival' => array( 'oldfest' ) ),
	);
	$undo_source = extrachill_network_classify_post_terms(
		array( 'site' => 'community', 'post_id' => 51 ),
		static fn(): array => array(
			array( 'taxonomy' => 'artist', 'slug' => 'phish', 'confidence' => 0.99 ),
			array( 'taxonomy' => 'festival', 'slug' => 'bonnaroo', 'confidence' => 0.99 ),
		)
	);
	$GLOBALS['ntc_relationships'][2][51]['artist'][] = 204;
	$undo = extrachill_network_restore_term_classification_effect( $undo_source['effects'][0] );
	ntc_assert_same( 'reverted', $undo['status'], 'Undo applies while classifier provenance still matches.' );
	ntc_assert_same( array( 'human-added' ), ntc_slugs( 2, 51, 'artist' ), 'Undo removes classifier additions without erasing later human additions.' );
	ntc_assert_same( array( 'oldfest' ), ntc_slugs( 2, 51, 'festival' ), 'Undo restores only the classifier-owned term it replaced.' );
	$conflicted_undo = extrachill_network_restore_term_classification_effect( $undo_source['effects'][0] );
	ntc_assert_same( 'skipped', $conflicted_undo['status'], 'Undo skips taxonomies whose provenance no longer matches the applied effect.' );

	$idempotent = extrachill_network_classify_post_terms(
		array( 'site' => 'community', 'post_id' => 50 ),
		static function () use ( &$selector_calls ): array { ++$selector_calls; return array(); }
	);
	ntc_assert_same( 'identical_fingerprint', $idempotent['reason'], 'Successful identical fingerprints are no-ops.' );
	ntc_assert_same( 1, $selector_calls, 'Idempotent no-op does not invoke AI selection.' );

	$before_terms = $GLOBALS['ntc_relationships'][2][50];
	$before_meta  = $GLOBALS['ntc_meta'][2][50][EXTRACHILL_NETWORK_TERM_PROVENANCE_META];
	$original_title   = $GLOBALS['ntc_posts'][2][50]->post_title;
	$original_content = $GLOBALS['ntc_posts'][2][50]->post_content;
	$GLOBALS['ntc_posts'][2][50]->post_title   = 'Radiohead announces a surprise set';
	$GLOBALS['ntc_posts'][2][50]->post_content = 'A detailed discussion about Radiohead and the newly announced performance.';
	$dry_run = extrachill_network_classify_post_terms(
		array( 'site' => 'community', 'post_id' => 50, 'force' => true, 'dry_run' => true ),
		static fn(): array => array( array( 'taxonomy' => 'artist', 'slug' => 'radiohead', 'confidence' => 0.99 ) )
	);
	ntc_assert_same( true, $dry_run['dry_run'], 'Dry-run reports its execution mode.' );
	ntc_assert_same( array( 'radiohead' ), $dry_run['assignments']['artist'], 'Dry-run assignment identities retain missing projections without fake IDs.' );
	ntc_assert_same( 'radiohead', $dry_run['projections']['artist'][0]['slug'], 'Dry-run returns an explicit missing projection plan.' );
	ntc_assert_same( 0, $dry_run['projections']['artist'][0]['term_id'], 'Dry-run clearly reports that a missing projection has no local ID yet.' );
	ntc_assert_same( $before_terms, $GLOBALS['ntc_relationships'][2][50], 'Dry-run does not mutate relationships.' );
	ntc_assert_same( $before_meta, $GLOBALS['ntc_meta'][2][50][EXTRACHILL_NETWORK_TERM_PROVENANCE_META], 'Dry-run does not persist provenance.' );
	$GLOBALS['ntc_posts'][2][50]->post_title   = $original_title;
	$GLOBALS['ntc_posts'][2][50]->post_content = $original_content;

	$GLOBALS['ntc_posts'][2][50]->post_title   = 'General music discussion';
	$GLOBALS['ntc_posts'][2][50]->post_content = 'A sufficiently detailed conversation with no approved named entities in this particular post.';
	$no_match = extrachill_network_classify_post_terms(
		array( 'site' => 'community', 'post_id' => 50, 'force' => true ),
		static fn(): array => array()
	);
	ntc_assert_same( 0, $no_match['candidate_count'], 'No-match is a successful classification result.' );
	ntc_assert_same( array(), ntc_slugs( 2, 50, 'artist' ), 'No-match removes only prior AI-owned artist assignments.' );
	ntc_assert_same( array(), ntc_slugs( 2, 50, 'festival' ), 'No-match removes only prior AI-owned festival assignments.' );
	ntc_assert_same( array( 'charleston' ), ntc_slugs( 2, 50, 'location' ), 'No-match never removes human-owned terms.' );
	$current_fingerprint = extrachill_network_term_classification_fingerprint( $GLOBALS['ntc_posts'][2][50] );
	ntc_assert( extrachill_network_term_classification_is_current( 50, array( 'artist', 'festival', 'location' ), $current_fingerprint ), 'No-match stores successful per-taxonomy fingerprints.' );

	$effect = $result['effects'][0];
	$undo   = extrachill_network_restore_term_classification_effect( $effect );
	ntc_assert_same( 'skipped', $undo['status'], 'Undo does not overwrite a later successful classification.' );
	ntc_assert_same( array(), ntc_slugs( 2, 50, 'festival' ), 'A conflicted undo leaves later classifier state intact.' );
	ntc_assert_same( 2, get_current_blog_id(), 'Undo restores the caller blog context.' );

	// Runtime registration is checked after switching; policy alone is insufficient.
	$original_topic_taxonomies = $GLOBALS['ntc_taxonomies']['topic'];
	$GLOBALS['ntc_taxonomies']['topic'] = array();
	ntc_assert_same( array(), extrachill_network_get_eligible_term_taxonomies( 'community', 'topic' ), 'Missing runtime registrations fail closed after switch_to_blog.' );
	$GLOBALS['ntc_taxonomies']['topic'] = $original_topic_taxonomies;

	// Scheduling models Data Machine's permanent operation-key replay contract.
	$GLOBALS['ntc_posts'][2][50]->post_title   = 'Phish returns to Bonnaroo';
	$GLOBALS['ntc_posts'][2][50]->post_content = 'A meaningful published update about Phish playing Bonnaroo for Charleston fans.';
	delete_post_meta( 50, EXTRACHILL_NETWORK_TERM_PROVENANCE_META );
	ntc_reset_scheduler();
	$preview = extrachill_network_schedule_term_classification( array( 'site' => 'community', 'post_id' => 50, 'dry_run' => true ) );
	$live    = extrachill_network_schedule_term_classification( array( 'site' => 'community', 'post_id' => 50 ) );
	ntc_assert( $preview['job_id'] !== $live['job_id'], 'Preview and live execution use separate permanent operation-key lanes.' );
	ntc_assert( str_contains( \DataMachine\Engine\Tasks\TaskScheduler::$jobs[ $preview['job_id'] ]['key'], ':preview:' ), 'Preview key declares its execution mode.' );
	ntc_assert( str_contains( \DataMachine\Engine\Tasks\TaskScheduler::$jobs[ $live['job_id'] ]['key'], ':live:' ), 'Live key declares its execution mode.' );
	\DataMachine\Engine\Tasks\TaskScheduler::$jobs[ $live['job_id'] ]['status'] = 'failed';
	$retry = extrachill_network_schedule_term_classification( array( 'site' => 'community', 'post_id' => 50 ) );
	ntc_assert( $retry['job_id'] !== $live['job_id'], 'A failed terminal operation increments the attempt and schedules a real retry.' );
	$duplicate = extrachill_network_schedule_term_classification( array( 'site' => 'community', 'post_id' => 50 ) );
	ntc_assert_same( false, $duplicate['scheduled'], 'An identical pending retry is suppressed.' );
	ntc_assert_same( 'duplicate_active_job', $duplicate['reason'], 'Active duplicate suppression reports its reason.' );
	ntc_assert_same( $retry['job_id'], $duplicate['job_id'], 'Active duplicate suppression returns the queued job.' );

	// Lifecycle matrix: pending, direct publish, and changed publish schedule; exclusions do not.
	ntc_reset_scheduler();
	extrachill_network_maybe_schedule_term_classification( 'pending', 'draft', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 1, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Transition to pending schedules classification.' );
	ntc_reset_scheduler();
	extrachill_network_maybe_schedule_term_classification( 'publish', 'draft', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 1, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Direct publish schedules classification.' );
	ntc_reset_scheduler();
	extrachill_network_maybe_schedule_term_classification( 'publish', 'publish', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 1, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Meaningful published updates schedule classification.' );
	ntc_reset_scheduler();
	$GLOBALS['ntc_posts'][2][50]->post_status = 'closed';
	extrachill_network_maybe_schedule_term_classification( 'closed', 'closed', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 1, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Meaningful updates to public closed topics schedule classification.' );
	$closed_result = extrachill_network_classify_post_terms(
		array( 'site' => 'community', 'post_id' => 50, 'force' => true, 'dry_run' => true ),
		static fn(): array => array()
	);
	ntc_assert( ! is_wp_error( $closed_result ), 'Closed topics remain eligible for manual classification.' );
	$GLOBALS['ntc_posts'][2][50]->post_status = 'publish';

	// Metadata churn on an already-published post must not buy a second job.
	//
	// The prior job is marked final so duplicate-active-job suppression cannot
	// account for the result: without the no-op guard this post has no
	// provenance, so it would look unclassified and schedule again on every
	// touch. That is what a bulk post_date rewrite across 10,146 unchanged
	// events cost in one evening.
	ntc_reset_scheduler();
	extrachill_network_maybe_schedule_term_classification( 'publish', 'publish', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 1, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'First touch of unseen text schedules.' );
	foreach ( \DataMachine\Engine\Tasks\TaskScheduler::$jobs as $id => $job ) {
		\DataMachine\Engine\Tasks\TaskScheduler::$jobs[ $id ]['status'] = 'completed';
	}
	extrachill_network_maybe_schedule_term_classification( 'publish', 'publish', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 1, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Unchanged text does not reschedule on repeat publish->publish touches.' );

	// A real edit still classifies — the guard keys on text, not on frequency.
	$original_content                            = $GLOBALS['ntc_posts'][2][50]->post_content;
	$GLOBALS['ntc_posts'][2][50]->post_content .= ' An edit that genuinely changes the classified text of this topic.';
	extrachill_network_maybe_schedule_term_classification( 'publish', 'publish', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 2, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Changed text still schedules on publish->publish.' );
	$GLOBALS['ntc_posts'][2][50]->post_content = $original_content;

	foreach ( array( array( 'draft', 'draft' ), array( 'trash', 'publish' ), array( 'auto-draft', 'draft' ) ) as $transition ) {
		ntc_reset_scheduler();
		extrachill_network_maybe_schedule_term_classification( $transition[0], $transition[1], $GLOBALS['ntc_posts'][2][50] );
		ntc_assert_same( 0, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), "Excluded {$transition[1]}->{$transition[0]} transition does not schedule." );
	}

	ntc_reset_scheduler();
	$GLOBALS['ntc_autosaves'][] = 50;
	extrachill_network_maybe_schedule_term_classification( 'publish', 'publish', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 0, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Autosaves do not schedule.' );
	$GLOBALS['ntc_autosaves'] = array();
	$GLOBALS['ntc_revisions'][] = 50;
	extrachill_network_maybe_schedule_term_classification( 'publish', 'publish', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 0, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Revisions do not schedule.' );
	$GLOBALS['ntc_revisions'] = array();

	$GLOBALS['extrachill_network_term_classifier_writing'] = true;
	extrachill_network_maybe_schedule_term_classification( 'publish', 'publish', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 0, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Classifier-originated writes cannot loop.' );
	$GLOBALS['extrachill_network_term_classifier_writing'] = false;

	// A site can veto automatic classification for content it knows nobody
	// will browse — the events site skipping past shows, for instance.
	ntc_reset_scheduler();
	$GLOBALS['ntc_filters']['extrachill_network_should_classify_post'][] = static fn( $should, $post, $site ) => false;
	extrachill_network_maybe_schedule_term_classification( 'publish', 'draft', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 0, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'A vetoed post does not schedule automatically.' );

	// The veto must not reach explicit requests: a human asking for the work
	// is not what it protects against.
	$forced = extrachill_network_classify_post_terms(
		array( 'site' => 'community', 'post_id' => 50, 'force' => true, 'dry_run' => true ),
		static fn(): array => array()
	);
	ntc_assert( ! is_wp_error( $forced ), 'An explicit classification request bypasses the veto.' );
	$GLOBALS['ntc_filters']['extrachill_network_should_classify_post'] = array();

	ntc_reset_scheduler();
	extrachill_network_maybe_schedule_term_classification( 'publish', 'draft', $GLOBALS['ntc_posts'][2][50] );
	ntc_assert_same( 1, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Removing the veto restores automatic scheduling.' );
	ntc_reset_scheduler();

	$unsupported = new WP_Post( 99, 'page', 'publish', 'Long enough unsupported title', 'Long enough unsupported content for policy exclusion.' );
	extrachill_network_maybe_schedule_term_classification( 'publish', 'draft', $unsupported );
	ntc_assert_same( 0, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Unsupported post types do not schedule.' );
	$short = new WP_Post( 98, 'topic', 'publish', 'Tiny', 'Short' );
	extrachill_network_maybe_schedule_term_classification( 'publish', 'draft', $short );
	ntc_assert_same( 0, count( \DataMachine\Engine\Tasks\TaskScheduler::$jobs ), 'Insufficient content does not schedule.' );

	\DataMachine\Core\PluginSettings::$enabled = false;
	$disabled = extrachill_network_schedule_term_classification( array( 'site' => 'community', 'post_id' => 50 ) );
	ntc_assert( is_wp_error( $disabled ), 'Disabled task state prevents scheduling.' );
	\DataMachine\Core\PluginSettings::$enabled = true;

	// Task registration, AI parsing, completion, and conflict-safe undo load together.
	$tasks = apply_filters( 'datamachine_tasks', array() );
	ntc_assert_same( \ExtraChillNetwork\Taxonomy\TermClassificationTask::class, $tasks[EXTRACHILL_NETWORK_TERM_CLASSIFICATION_TASK], 'Data Machine task filter loads the concrete classifier task.' );
	$GLOBALS['ntc_posts'][2][60] = new WP_Post( 60, 'topic', 'publish', 'Phish discussion', 'A sufficiently detailed discussion focused clearly on Phish and the band history.' );
	$GLOBALS['ntc_relationships'][2][60] = array( 'artist' => array(), 'festival' => array(), 'location' => array() );
	\DataMachine\Engine\AI\RequestBuilder::$response = "```json\n[{\"taxonomy\":\"artist\",\"slug\":\"phish\",\"confidence\":0.97}]\n```";
	$task = new \ExtraChillNetwork\Taxonomy\TermClassificationTask();
	$task->executeTask( 900, array( 'site' => 'community', 'post_id' => 60 ) );
	$completed = \DataMachine\Engine\AI\System\Tasks\SystemTask::$completed[900] ?? array();
	ntc_assert_same( array( 'phish' ), $completed['selected']['artist'], 'Task parses fenced AI JSON and completes with a validated selection.' );
	ntc_assert_same( array( 'phish' ), ntc_slugs( 2, 60, 'artist' ), 'Task completion applies the approved local relationship.' );
	$task_undo = $task->undo( 900, array( 'effects' => $completed['effects'] ) );
	ntc_assert_same( true, $task_undo['success'], 'Task undo reports successful conflict-safe reversal.' );
	ntc_assert_same( array(), ntc_slugs( 2, 60, 'artist' ), 'Task undo reverses the task-owned relationship.' );

	// Ability classes publish schemas, enforce target edit permission, and route cross-site runtime needs.
	$abilities = new \ExtraChillNetwork\Abilities\NetworkTermAbilities();
	$abilities->register();
	foreach ( array( 'extrachill/search-network-terms', 'extrachill/project-network-term', 'extrachill/classify-post-terms' ) as $ability_name ) {
		ntc_assert( isset( $GLOBALS['ntc_abilities'][ $ability_name ]['input_schema'], $GLOBALS['ntc_abilities'][ $ability_name ]['output_schema'] ), "{$ability_name} registers bounded input and output schemas." );
	}
	ntc_assert( is_array( $GLOBALS['ntc_abilities']['extrachill/project-network-term']['permission_callback'] ), 'Projection publishes a real permission callback.' );
	$GLOBALS['ntc_can_edit'] = true;
	ntc_assert_same( true, $abilities->canProject( array( 'site' => 'community', 'post_id' => 50 ) ), 'Community topic edit permission authorizes trusted projection without taxonomy creation caps.' );
	$GLOBALS['ntc_can_edit'] = false;
	ntc_assert_same( false, $abilities->canProject( array( 'site' => 'community', 'post_id' => 50 ) ), 'Community projection denies callers who cannot edit the topic.' );
	$GLOBALS['ntc_can_edit'] = true;

	$GLOBALS['ntc_blog_id'] = 12;
	$GLOBALS['ntc_taxonomies']['topic'] = array();
	$GLOBALS['ntc_cross_site'] = array();
	$routed_search = $abilities->search( array( 'site' => 'community', 'post_type' => 'topic', 'taxonomy' => 'artist', 'query' => 'Phish' ) );
	ntc_assert_same( array( 'routed' => true ), $routed_search, 'Studio-origin search routes to Community instead of assuming switch_to_blog loaded topic registration.' );
	ntc_assert_same( 'GET', $GLOBALS['ntc_cross_site'][0]['method'], 'Read-only cross-site search uses the Abilities GET contract.' );
	$routed_projection = $abilities->project( array( 'site' => 'community', 'post_id' => 50, 'taxonomy' => 'artist', 'slug' => 'phish' ) );
	ntc_assert_same( array( 'routed' => true ), $routed_projection, 'Studio-origin projection routes into Community full bootstrap.' );
	ntc_assert_same( 'POST', $GLOBALS['ntc_cross_site'][1]['method'], 'Mutating cross-site projection uses the Abilities POST contract.' );
	ntc_assert( str_contains( $GLOBALS['ntc_cross_site'][1]['route'], 'extrachill/project-network-term/run' ), 'Cross-site routing targets the canonical ability route.' );
	$GLOBALS['ntc_blog_id'] = 2;
	$GLOBALS['ntc_taxonomies']['topic'] = $original_topic_taxonomies;

	ntc_assert_same( 2, get_current_blog_id(), 'All tested multisite operations restore the original context.' );
	fwrite( STDOUT, "Network term classification tests passed.\n" );
}

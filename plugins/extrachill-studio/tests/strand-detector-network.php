<?php
/**
 * Network strand-scan regression harness (#210).
 *
 * Run with: php tests/strand-detector-network.php
 *
 * Exercises ec_studio_detect_network_strandings(): main-site exclusion,
 * archived-site exclusion, per-site blog tagging, switch/restore safety
 * (including the finally when a site throws), the social-draft exclusion on
 * the Studio blog, and the bounded per-site query shape.
 */

namespace ExtraChillStudio {
	const META_PLATFORMS    = '_studio_social_platforms';
	const META_CAPTION      = '_studio_social_caption';
	const META_IMAGES       = '_studio_social_images';
	const META_DELIVERY_REF = '_studio_social_delivery_ref';
}

namespace {
	define( 'ABSPATH', __DIR__ );

	// ---------------------------------------------------------------------
	// Fixtures.
	// ---------------------------------------------------------------------

	$GLOBALS['net_blogs'] = array(
		1  => array( 'blogname' => 'Extra Chill', 'live' => true ), // main — never scanned.
		2  => array( 'blogname' => 'Extra Chill Community', 'live' => true ),
		7  => array( 'blogname' => 'Extra Chill Events', 'live' => true ),
		12 => array( 'blogname' => 'Extra Chill Studio', 'live' => true ),
		99 => array( 'blogname' => 'Archived', 'live' => false ), // archived — never scanned.
	);

	$GLOBALS['net_users'] = array(
		44  => array( 'user_login' => 'bennybertolini', 'team' => true ),
		1   => array( 'user_login' => 'chubes', 'team' => true ),
		999 => array( 'user_login' => 'randomfan', 'team' => false ),
	);

	$GLOBALS['net_current_blog'] = 12;
	$GLOBALS['net_blog_stack']   = array();
	$GLOBALS['net_switch_log']   = array();
	$GLOBALS['net_query_log']    = array();
	$GLOBALS['net_throw_on']     = 0;

	function net_post( array $fields ): WP_Post {
		return new WP_Post( array_merge(
			array(
				'post_author'   => 44,
				'post_content'  => '',
				'post_title'    => '(no title)',
				'post_status'   => 'pending',
				'post_date_gmt' => '2026-09-24 18:00:00',
				'post_type'     => 'post',
			),
			$fields
		) );
	}

	$GLOBALS['net_posts'] = array(
		1  => array(
			9001 => net_post( array( 'ID' => 9001, 'post_content' => str_repeat( 'x', 300 ) ) ),
		),
		2  => array(
			// The real #210 case: team-authored pending recap on community.
			14324 => net_post( array( 'ID' => 14324, 'post_title' => 'Photo Recap' ) ),
			// Non-team author — never a candidate.
			14330 => net_post( array( 'ID' => 14330, 'post_author' => 999, 'post_content' => str_repeat( 'x', 300 ) ) ),
			// Never-strandable statuses — the query must filter these out.
			14340 => net_post( array( 'ID' => 14340, 'post_status' => 'auto-draft' ) ),
			14350 => net_post( array( 'ID' => 14350, 'post_status' => 'trash' ) ),
		),
		12 => array(
			// Legitimate social draft on Studio — excluded by the meta query.
			500 => net_post( array( 'ID' => 500, 'post_title' => 'IG draft' ) ),
			// The original #110 scenario: editorial post stranded on Studio.
			510 => net_post( array( 'ID' => 510, 'post_title' => 'Stranded review', 'post_status' => 'draft', 'post_content' => str_repeat( 'y', 260 ) ) ),
		),
		99 => array(
			9900 => net_post( array( 'ID' => 9900, 'post_content' => str_repeat( 'x', 300 ) ) ),
		),
	);

	$GLOBALS['net_meta'] = array(
		12 => array(
			500 => array( \ExtraChillStudio\META_PLATFORMS => array( 'instagram' ) ),
		),
	);

	$GLOBALS['net_attachments'] = array(
		2 => array( 14324 => 15 ),
	);

	// ---------------------------------------------------------------------
	// WordPress stubs.
	// ---------------------------------------------------------------------

	final class WP_Post {
		public $ID;
		public $post_author;
		public $post_content;
		public $post_title;
		public $post_status;
		public $post_date_gmt;
		public $post_type;

		public function __construct( array $fields ) {
			foreach ( $fields as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}

	final class WP_Query {
		public $posts = array();

		public function __construct( array $args ) {
			$blog = $GLOBALS['net_current_blog'];
			$GLOBALS['net_query_log'][ $blog ][] = $args;

			if ( $blog === $GLOBALS['net_throw_on'] ) {
				throw new RuntimeException( 'boom on blog ' . $blog );
			}

			$statuses = (array) ( $args['post_status'] ?? array() );
			$limit    = (int) ( $args['posts_per_page'] ?? 0 );

			$excludes_platforms = false;
			foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
				if ( is_array( $clause )
					&& ( $clause['key'] ?? '' ) === \ExtraChillStudio\META_PLATFORMS
					&& ( $clause['compare'] ?? '' ) === 'NOT EXISTS' ) {
					$excludes_platforms = true;
				}
			}

			foreach ( $GLOBALS['net_posts'][ $blog ] ?? array() as $post ) {
				if ( ! in_array( $post->post_status, $statuses, true ) ) {
					continue;
				}
				if ( $excludes_platforms && metadata_exists( 'post', $post->ID, \ExtraChillStudio\META_PLATFORMS ) ) {
					continue;
				}
				$this->posts[] = $post;
				if ( $limit > 0 && count( $this->posts ) >= $limit ) {
					break;
				}
			}
		}
	}

	function ec_get_blog_id( $key ) {
		$map = array( 'main' => 1, 'studio' => 12 );
		return $map[ $key ] ?? null;
	}

	function get_sites( array $args = array() ) {
		$GLOBALS['net_get_sites_args'] = $args;

		$sites = array();
		foreach ( $GLOBALS['net_blogs'] as $blog_id => $blog ) {
			if ( empty( $blog['live'] ) ) {
				continue;
			}
			$site       = new stdClass();
			$site->blog_id = $blog_id;
			$sites[]    = $site;
		}
		return $sites;
	}

	function get_current_blog_id(): int {
		return $GLOBALS['net_current_blog'];
	}

	function switch_to_blog( int $blog_id ): bool {
		$GLOBALS['net_switch_log'][] = 'switch:' . $blog_id;
		$GLOBALS['net_blog_stack'][] = $GLOBALS['net_current_blog'];
		$GLOBALS['net_current_blog'] = $blog_id;
		return true;
	}

	function restore_current_blog(): bool {
		$GLOBALS['net_current_blog'] = array_pop( $GLOBALS['net_blog_stack'] );
		$GLOBALS['net_switch_log'][] = 'restore:' . $GLOBALS['net_current_blog'];
		return true;
	}

	function get_option( $name, $default = false ) {
		if ( 'blogname' === $name ) {
			return $GLOBALS['net_blogs'][ $GLOBALS['net_current_blog'] ]['blogname'] ?? $default;
		}
		return $default;
	}

	function metadata_exists( $type, $post_id, $key ) {
		unset( $type );
		$blog = $GLOBALS['net_current_blog'];
		return array_key_exists( $key, $GLOBALS['net_meta'][ $blog ][ $post_id ] ?? array() );
	}

	function get_post_meta( $post_id, $key, $single ) {
		unset( $single );
		$blog = $GLOBALS['net_current_blog'];
		return $GLOBALS['net_meta'][ $blog ][ $post_id ][ $key ] ?? '';
	}

	function get_post( $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['net_posts'][ $GLOBALS['net_current_blog'] ][ $id ] ?? null;
	}

	function get_userdata( $user_id ) {
		$user = $GLOBALS['net_users'][ $user_id ] ?? null;
		if ( ! $user ) {
			return false;
		}
		$u            = new stdClass();
		$u->user_login = $user['user_login'];
		return $u;
	}

	function user_can( $user_id, $capability ) {
		unset( $capability );
		return (bool) ( $GLOBALS['net_users'][ $user_id ]['team'] ?? false );
	}

	function get_the_title( $post ) {
		$post = get_post( $post );
		return $post ? $post->post_title : '';
	}

	function wp_strip_all_tags( $string ) {
		return trim( strip_tags( (string) $string ) );
	}

	function strip_shortcodes( $content ) {
		return $content;
	}

	function has_post_thumbnail( $post ) {
		unset( $post );
		return false;
	}

	function get_children( array $args ) {
		$count = $GLOBALS['net_attachments'][ $GLOBALS['net_current_blog'] ][ $args['post_parent'] ] ?? 0;
		return $count > 0 ? array_fill( 0, $count, 77000 ) : array();
	}

	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}

	// ---------------------------------------------------------------------
	// Subject under test.
	// ---------------------------------------------------------------------

	require dirname( __DIR__ ) . '/inc/compose/strand-detector.php';

	function net_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'FAIL: ' . $message );
		}
	}

	// Main resolution prefers the canonical map.
	net_assert( 1 === ec_studio_strand_main_blog_id(), 'main blog id must resolve to 1' );

	$candidates = ec_studio_detect_network_strandings();

	// Exactly the two real strandings: community 14324 + studio 510.
	net_assert(
		array( array( 2, 14324 ), array( 12, 510 ) ) === array_map(
			static fn( array $c ): array => array( $c['blog_id'], $c['id'] ),
			$candidates
		),
		'scan must flag exactly community 14324 and studio 510, got: ' . wp_json_encode( $candidates )
	);

	$community = $candidates[0];
	net_assert( 'Extra Chill Community' === $community['site_name'], 'candidates carry the site name' );
	net_assert( 'pending' === $community['status'], 'community candidate keeps its status' );
	net_assert( 'bennybertolini' === $community['author'], 'community candidate names its author' );
	net_assert( false !== strpos( $community['reason'], 'attached image' ), 'community candidate is flagged via its attachments' );

	// Non-team author, auto-draft, trash, social draft, main, and archived
	// posts must all be absent.
	net_assert( 2 === count( $candidates ), 'only two candidates overall' );

	// Scan shape: archived/deleted filtering and status list.
	net_assert( 0 === ( $GLOBALS['net_get_sites_args']['archived'] ?? -1 ), 'get_sites must exclude archived' );
	net_assert( 0 === ( $GLOBALS['net_get_sites_args']['deleted'] ?? -1 ), 'get_sites must exclude deleted' );

	$scanned_blogs = array_keys( $GLOBALS['net_query_log'] );
	sort( $scanned_blogs );
	net_assert( array( 2, 7, 12 ) === $scanned_blogs, 'only live non-main blogs are scanned, got: ' . implode( ',', $scanned_blogs ) );

	foreach ( $GLOBALS['net_query_log'] as $blog_id => $queries ) {
		foreach ( $queries as $query_args ) {
			net_assert( 'post' === $query_args['post_type'], "blog $blog_id scans post_type=post only" );
			net_assert(
				EC_STUDIO_STRAND_STATUSES === $query_args['post_status'],
				"blog $blog_id scans exactly the strandable statuses (never auto-draft/trash)"
			);
			net_assert( 500 === $query_args['posts_per_page'], "blog $blog_id scan is bounded by the default per-site limit" );
		}
	}

	// Switch/restore bookkeeping: balanced pairs, ends on the starting blog.
	$switches = array_filter( $GLOBALS['net_switch_log'], static fn( $e ): bool => str_starts_with( (string) $e, 'switch:' ) );
	$restores = array_filter( $GLOBALS['net_switch_log'], static fn( $e ): bool => str_starts_with( (string) $e, 'restore:' ) );
	net_assert( count( $switches ) === count( $restores ), 'every switch has a matching restore' );
	net_assert( 12 === $GLOBALS['net_current_blog'], 'blog context restored after the scan' );

	// The finally must restore context even when a site's scan throws.
	$GLOBALS['net_switch_log'] = array();
	$GLOBALS['net_throw_on']   = 7;
	try {
		ec_studio_detect_network_strandings();
		net_assert( false, 'a throwing site must propagate' );
	} catch ( RuntimeException $exception ) {
		net_assert( 'boom on blog 7' === $exception->getMessage(), 'the site exception propagates' );
	}
	net_assert( 12 === $GLOBALS['net_current_blog'], 'blog context restored even when a site throws' );
	net_assert( 'Extra Chill Studio' === get_option( 'blogname' ), 'stub options resolve against the restored blog' );
	$GLOBALS['net_throw_on'] = 0;

	// Custom per-site limit reaches the query.
	$GLOBALS['net_query_log'] = array();
	ec_studio_detect_network_strandings( array( 'limit' => 2 ) );
	foreach ( $GLOBALS['net_query_log'] as $blog_id => $queries ) {
		net_assert( 2 === $queries[0]['posts_per_page'], "blog $blog_id honors a custom per-site limit" );
	}

	echo "PASS: network strand scan (#210) — main/archived exclusion, blog tagging, social-draft exclusion, bounded per-site queries, switch/restore safety\n";
}

<?php
/**
 * Minimal in-memory WordPress stubs for photo-contest business-logic tests.
 *
 * Implements only the surface the photo-contest + image-voting business logic
 * touches (posts, post meta, comments + comment meta, transients, a handful
 * of formatting helpers). Each test resets state via wp_stubs_reset().
 *
 * @package ExtraChillContentBlocks
 */

// phpcs:ignoreFile -- test doubles intentionally shadow WordPress functions.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

function wp_stubs_reset(): void {
	$GLOBALS['wp_test_posts']        = array();
	$GLOBALS['wp_test_meta']         = array();
	$GLOBALS['wp_test_blocks']       = array();
	$GLOBALS['wp_test_comments']     = array();
	$GLOBALS['wp_test_comment_meta'] = array();
	$GLOBALS['wp_test_transients']   = array();
	$GLOBALS['wp_test_abilities']    = array();
	$GLOBALS['wp_test_next_id']      = 1000;
}

function wp_stubs_next_id(): int {
	return ++$GLOBALS['wp_test_next_id'];
}

/**
 * Insert a fake post. Returns its ID.
 */
function wp_stubs_insert_post( array $data ): int {
	$id = isset( $data['ID'] ) ? (int) $data['ID'] : wp_stubs_next_id();

	$GLOBALS['wp_test_posts'][ $id ] = (object) array_merge(
		array(
			'ID'           => $id,
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_parent'  => 0,
			'post_content' => '',
			'post_title'   => '',
		),
		$data,
		array( 'ID' => $id )
	);

	return $id;
}

// ─── Abilities API ──────────────────────────────────────────────────────────

/**
 * Minimal WP_Ability double. Mirrors the one behavior under test: execute()
 * enforces the registered permission_callback before the execute_callback
 * runs, exactly as WP_Ability::execute() does in core.
 */
if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		private string $name;
		private array $props;

		public function __construct( string $name, array $props ) {
			$this->name  = $name;
			$this->props = $props;
		}

		public function get_name(): string {
			return $this->name;
		}

		public function has_permission( $input = array() ): bool {
			return (bool) call_user_func( $this->props['permission_callback'], $input );
		}

		public function execute( $input = array() ) {
			if ( ! $this->has_permission( $input ) ) {
				return new WP_Error(
					'ability_invalid_permissions',
					'You do not have permission to execute this ability.'
				);
			}

			return call_user_func( $this->props['execute_callback'], $input );
		}
	}
}

function wp_register_ability( $name, array $props ) {
	$ability = new WP_Ability( (string) $name, $props );

	$GLOBALS['wp_test_abilities'][ (string) $name ] = $ability;

	return $ability;
}

function wp_get_ability( $name ) {
	return $GLOBALS['wp_test_abilities'][ (string) $name ] ?? null;
}

// ─── Hooks (no-op collectors) ────────────────────────────────────────────────

function add_action( ...$args ) {
	return true;
}

function add_filter( ...$args ) {
	return true;
}

// ─── Posts ───────────────────────────────────────────────────────────────────

function get_post( $post_id ) {
	$post_id = (int) ( is_object( $post_id ) ? $post_id->ID : $post_id );
	return $GLOBALS['wp_test_posts'][ $post_id ] ?? null;
}

/**
 * Supports the exact arg shape the photo-contest logic uses: post_type,
 * post_parent, fields=ids, meta_query (AND; '=' or NOT EXISTS), orderby ID.
 */
function get_posts( array $args ) {
	$results = array();

	foreach ( $GLOBALS['wp_test_posts'] as $post ) {
		if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
			continue;
		}
		if ( isset( $args['post_parent'] ) && (int) $post->post_parent !== (int) $args['post_parent'] ) {
			continue;
		}
		if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && $post->post_status !== $args['post_status'] ) {
			continue;
		}

		if ( ! empty( $args['meta_query'] ) ) {
			$meta = $GLOBALS['wp_test_meta'][ $post->ID ] ?? array();
			foreach ( $args['meta_query'] as $clause ) {
				if ( ! is_array( $clause ) || empty( $clause['key'] ) ) {
					continue;
				}
				$compare = $clause['compare'] ?? '=';
				if ( 'NOT EXISTS' === $compare ) {
					if ( array_key_exists( $clause['key'], $meta ) ) {
						continue 2;
					}
				} elseif ( ! array_key_exists( $clause['key'], $meta ) || (string) $meta[ $clause['key'] ] !== (string) $clause['value'] ) {
					continue 2;
				}
			}
		}

		$results[] = $post;
	}

	usort( $results, static fn( $a, $b ) => $a->ID <=> $b->ID );

	$limit = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;
	if ( $limit > 0 ) {
		$results = array_slice( $results, 0, $limit );
	}

	if ( ( $args['fields'] ?? '' ) === 'ids' ) {
		return array_map( static fn( $p ) => (int) $p->ID, $results );
	}

	return $results;
}

// ─── Post meta ───────────────────────────────────────────────────────────────

function get_post_meta( $post_id, $key, $single = false ) {
	$value = $GLOBALS['wp_test_meta'][ (int) $post_id ][ $key ] ?? '';
	return $single ? $value : ( '' === $value ? array() : array( $value ) );
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['wp_test_meta'][ (int) $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['wp_test_meta'][ (int) $post_id ][ $key ] );
	return true;
}

// ─── Blocks ──────────────────────────────────────────────────────────────────

/**
 * Posts store a "blocks:<post_id>" token as post_content; the fixture blocks
 * live in $GLOBALS['wp_test_blocks'] keyed by post id.
 */
function parse_blocks( $content ) {
	if ( preg_match( '/^blocks:(\d+)$/', (string) $content, $m ) ) {
		return $GLOBALS['wp_test_blocks'][ (int) $m[1] ] ?? array();
	}
	return array();
}

// ─── Comments (image_vote backend) ──────────────────────────────────────────

function wp_insert_comment( array $data ) {
	$id = wp_stubs_next_id();

	$GLOBALS['wp_test_comments'][ $id ] = (object) array_merge(
		array(
			'comment_ID'           => $id,
			'comment_post_ID'      => 0,
			'comment_author_email' => '',
			'comment_type'         => '',
			'comment_approved'     => 1,
			'comment_content'      => '',
		),
		$data,
		array( 'comment_ID' => $id )
	);

	return $id;
}

function add_comment_meta( $comment_id, $key, $value ) {
	$GLOBALS['wp_test_comment_meta'][ (int) $comment_id ][ $key ] = $value;
	return true;
}

/**
 * Supports: post_id, type, meta_key/meta_value, status=approve, count,
 * author_email — the exact shape the image-voting backend uses.
 */
function get_comments( array $args ) {
	$results = array();

	foreach ( $GLOBALS['wp_test_comments'] as $comment ) {
		if ( isset( $args['post_id'] ) && (int) $comment->comment_post_ID !== (int) $args['post_id'] ) {
			continue;
		}
		if ( isset( $args['type'] ) && $comment->comment_type !== $args['type'] ) {
			continue;
		}
		if ( isset( $args['author_email'] ) && strtolower( (string) $comment->comment_author_email ) !== strtolower( (string) $args['author_email'] ) ) {
			continue;
		}
		if ( isset( $args['meta_key'], $args['meta_value'] ) ) {
			$meta = $GLOBALS['wp_test_comment_meta'][ (int) $comment->comment_ID ] ?? array();
			if ( ! isset( $meta[ $args['meta_key'] ] ) || (string) $meta[ $args['meta_key'] ] !== (string) $args['meta_value'] ) {
				continue;
			}
		}
		$results[] = $comment;
	}

	if ( ! empty( $args['count'] ) ) {
		return count( $results );
	}

	return $results;
}

// ─── Transients ──────────────────────────────────────────────────────────────

function get_transient( $key ) {
	return $GLOBALS['wp_test_transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['wp_test_transients'][ $key ] = $value;
	return true;
}

// ─── Attachments / URLs ──────────────────────────────────────────────────────

function wp_get_attachment_url( $attachment_id ) {
	$post = get_post( $attachment_id );
	return $post ? 'https://example.com/uploads/entry-' . (int) $attachment_id . '.jpg' : false;
}

function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail' ) {
	$post = get_post( $attachment_id );
	return $post ? 'https://example.com/uploads/entry-' . (int) $attachment_id . '-' . $size . '.jpg' : false;
}

function get_permalink( $post_id ) {
	return 'https://example.com/?p=' . (int) ( is_object( $post_id ) ? $post_id->ID : $post_id );
}

function add_query_arg( $key, $value, $url ) {
	$separator = str_contains( (string) $url, '?' ) ? '&' : '?';
	return $url . $separator . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
}

// ─── Formatting / validation helpers ─────────────────────────────────────────

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}

function sanitize_email( $value ) {
	$value = trim( (string) $value );
	return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
}

function is_email( $value ) {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}

function absint( $value ) {
	return abs( (int) $value );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function __return_true() {
	return true;
}

/**
 * Tests model the anonymous contest voter: nobody is ever logged in.
 */
function is_user_logged_in() {
	return false;
}

function wp_check_filetype( $filename, $mimes = null ) {
	$mimes = $mimes ?? array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
	);

	$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
	foreach ( $mimes as $ext_pattern => $mime ) {
		if ( in_array( $ext, explode( '|', $ext_pattern ), true ) ) {
			return array(
				'ext'  => $ext,
				'type' => $mime,
			);
		}
	}

	return array(
		'ext'  => false,
		'type' => false,
	);
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

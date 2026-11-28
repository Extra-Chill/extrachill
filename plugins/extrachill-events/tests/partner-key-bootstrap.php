<?php
/**
 * Isolated partner credential and partner events unit harness.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden, Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.WP.AlternativeFunctions, Squiz.Commenting, Generic.Commenting, Generic.Classes.DuplicateClassName.Found -- Standalone harness bundles WordPress stubs and test doubles across namespaces.

namespace {

	define( 'ABSPATH', '/tmp/' );

	class WP_Error {
		public function __construct( public $code, public $message, public $data = array() ) {}
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function wp_salt( $scheme ) {
		return 'test-only-auth-salt';
	}

	function get_current_blog_id() {
		return $GLOBALS['partner_blog'];
	}

	function switch_to_blog( $id ) {
		$GLOBALS['partner_stack'][] = $GLOBALS['partner_blog'];
		$GLOBALS['partner_blog']    = $id;
	}

	function restore_current_blog() {
		$GLOBALS['partner_blog'] = array_pop( $GLOBALS['partner_stack'] );
	}

	function get_term( $id, $taxonomy ) {
		if ( $id <= 0 || isset( $GLOBALS['partner_deleted_terms'][ $id ] ) ) {
			return false;
		}
		return (object) array(
			'term_id'  => $id,
			'taxonomy' => $taxonomy,
			'name'     => 'Lo-Fi &amp; Brewing',
			'slug'     => 'lo-fi-brewing',
		);
	}

	// Partner events doubles.
	function add_action( $hook, $callback ) {
		$GLOBALS['partner_actions'][ $hook ][] = $callback;
	}

	function wp_register_ability( $name, $args ) {
		$GLOBALS['partner_registered'][ $name ] = $args;
	}

	function get_term_link( $term ) {
		return 'https://events.extrachill.com/venue/' . $term->slug . '/';
	}

	function add_query_arg( $key, $value, $url ) {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
	}

	function get_post( $id ) {
		return $GLOBALS['partner_posts'][ $id ] ?? null;
	}

	function get_permalink( $post ) {
		return 'https://events.extrachill.com/events/' . $post->ID . '/';
	}

	function get_the_excerpt( $post ) {
		return $post->post_excerpt;
	}

	function get_the_post_thumbnail_url( $id, $size ) {
		return $GLOBALS['partner_thumbnails'][ $id ] ?? false;
	}

	function wp_strip_all_tags( $text ) {
		return strip_tags( $text );
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function wp_timezone_string() {
		return 'UTC';
	}

	function wp_get_abilities() {
		return $GLOBALS['partner_abilities'];
	}

	function data_machine_events_parse_event_data( $post ) {
		return $GLOBALS['partner_event_data'][ $post->ID ] ?? null;
	}

	function data_machine_events_get_venue_data( $id ) {
		return $GLOBALS['partner_venue_data'][ $id ] ?? null;
	}

	/** Minimal ability double: records input, returns the configured result. */
	final class PartnerAbilityDouble {
		public array $calls = array();

		public function __construct( private $callback ) {}

		public function execute( $input ) {
			$this->calls[] = $input;
			return ( $this->callback )( $input );
		}
	}

	function get_term_meta( $id, $key, $single ) {
		return $GLOBALS['partner_meta'][ get_current_blog_id() ][ $id ][ $key ] ?? '';
	}

	function update_term_meta( $id, $key, $value, $previous = null ) {
		if ( null !== $previous && get_term_meta( $id, $key, true ) !== $previous ) {
			return false;
		}
		++$GLOBALS['partner_writes'];
		$GLOBALS['partner_meta'][ get_current_blog_id() ][ $id ][ $key ] = $value;
		return true;
	}

	function delete_term_meta( $id, $key ) {
		unset( $GLOBALS['partner_meta'][ get_current_blog_id() ][ $id ][ $key ] );
		return true;
	}
}

namespace ExtraChillEvents\Core {

	class TeamAuthorization {
		const ACTION_MANAGE_MEMBERS = 'manage_members';

		public static function effective_user_id() {
			return 38;
		}

		public function authorize( $user, $type, $id, $action ) {
			$GLOBALS['partner_authorization'] = array( $user, $type, $id, $action, get_current_blog_id() );
			return $GLOBALS['partner_allowed'] ? true : new \WP_Error( 'forbidden', 'Not a member.', array( 'status' => 403 ) );
		}
	}

	/** Booking config double (extrachill-booking). */
	class VenueBookingConfig {
		public function get( $id ) {
			return $GLOBALS['partner_booking'][ $id ] ?? array( 'enabled' => false );
		}
	}
}

namespace {

	require_once __DIR__ . '/../inc/Core/VenuePartnerKey.php';
	require_once __DIR__ . '/../inc/Core/PartnerVenueEvents.php';
	require_once __DIR__ . '/../inc/Abilities/PartnerVenueEventsAbilities.php';
}

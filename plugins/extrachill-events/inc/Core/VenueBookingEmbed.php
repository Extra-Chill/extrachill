<?php
/**
 * Secure hosted venue booking embed.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Owns admission, framing policy, and rendering for the hosted embed. */
final class VenueBookingEmbed {
	private const QUERY_FLAG   = 'booking-embed';
	private const QUERY_PARENT = 'parent-origin';

	/** @var array|null Validated request context. */
	private static $context = null;

	/** Register the embed request lifecycle. */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'authorize_request' ), 0 );
		add_filter( 'extrachill_template_archive', array( self::class, 'template' ), 100 );
	}

	/**
	 * Normalize one exact, standard-port HTTPS web origin.
	 *
	 * @param mixed $origin Candidate origin.
	 * @return string|\WP_Error
	 */
	public static function normalize_origin( $origin ) {
		return VenueBookingConfig::normalize_embed_origin( $origin );
	}

	/**
	 * Check enabled admission and exact origin membership.
	 *
	 * @param array  $config        Private venue booking config.
	 * @param string $parent_origin Normalized parent origin.
	 */
	public static function is_origin_allowed( array $config, string $parent_origin ): bool {
		return ! empty( $config['enabled'] )
			&& in_array( $parent_origin, $config['embed']['allowed_parent_origins'] ?? array(), true );
	}

	/**
	 * Return the scoped CSP frame-ancestors directive.
	 *
	 * @param string $parent_origin Validated parent origin, or empty to deny.
	 */
	public static function frame_ancestors_policy( string $parent_origin = '' ): string {
		return '' === $parent_origin ? "frame-ancestors 'none'" : "frame-ancestors 'self' " . $parent_origin;
	}

	/** Admit only enabled venue embeds bound to a configured parent origin. */
	public static function authorize_request(): void {
		if ( ! self::is_embed_request() ) {
			return;
		}

		$venue  = get_queried_object();
		$config = $venue instanceof \WP_Term ? ( new VenueBookingConfig() )->get( (int) $venue->term_id ) : null;
		$parent = self::requested_parent_origin();
		if ( ! is_array( $config ) || is_wp_error( $parent ) || ! self::is_origin_allowed( $config, $parent ) ) {
			self::$context = array( 'denied' => true );
			status_header( 403 );
			nocache_headers();
			self::send_headers();
			wp_die( esc_html__( 'This venue booking embed is not authorized for that website.', 'extrachill-events' ), esc_html__( 'Booking embed unavailable', 'extrachill-events' ), array( 'response' => 403 ) );
		}

		self::$context = array(
			'parent_origin' => $parent,
			'venue_id'      => (int) $venue->term_id,
			'venue_name'    => $venue->name,
			'booking_url'   => self::booking_url( $venue ),
		);
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		self::send_headers();
	}

	/** Emit headers only after request admission has established context. */
	public static function send_headers(): void {
		if ( ! self::is_embed_request() ) {
			return;
		}
		if ( ! empty( self::$context['parent_origin'] ) ) {
			header_remove( 'X-Frame-Options' );
			header( 'Content-Security-Policy: ' . self::frame_ancestors_policy( self::$context['parent_origin'] ) );
			header( 'Referrer-Policy: strict-origin' );
			header( 'Cache-Control: no-store, private' );
			return;
		}
		header( 'Content-Security-Policy: ' . self::frame_ancestors_policy() );
	}

	/**
	 * Select the chrome-free template for an admitted embed.
	 *
	 * @param string $template Existing archive template.
	 */
	public static function template( string $template ): string {
		return ! empty( self::$context['parent_origin'] ) ? EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/booking-embed.php' : $template;
	}

	/** Return the validated template context. */
	public static function context(): array {
		return is_array( self::$context ) ? self::$context : array();
	}

	/**
	 * Return the canonical hosted booking anchor for a venue.
	 *
	 * @param \WP_Term $venue Canonical venue term.
	 */
	public static function booking_url( \WP_Term $venue ): string {
		$url = get_term_link( $venue );
		return is_wp_error( $url ) ? '' : $url . '#booking-inquiry';
	}

	/** Detect the exact public embed selector. */
	private static function is_embed_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact scalar flag is compared below; CSP and private config authorize framing.
		return is_tax( 'venue' ) && isset( $_GET[ self::QUERY_FLAG ] ) && is_scalar( $_GET[ self::QUERY_FLAG ] ) && '1' === (string) wp_unslash( $_GET[ self::QUERY_FLAG ] );
	}

	/** Return the normalized parent-origin selector. */
	private static function requested_parent_origin() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed scalar is strictly parsed and matched to private venue config.
		$value = isset( $_GET[ self::QUERY_PARENT ] ) && is_scalar( $_GET[ self::QUERY_PARENT ] ) ? wp_unslash( (string) $_GET[ self::QUERY_PARENT ] ) : '';
		return self::normalize_origin( $value );
	}
}

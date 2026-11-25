<?php
namespace ExtraChill\Partner;

defined( 'ABSPATH' ) || exit;

/** Shared shortcode and dynamic-block presentation. */
final class Renderer {
	private static $schemas = array();

	private static function text( $value ) {
		return is_string( $value ) ? $value : '';
	}

	private static function notice( $data ) {
		return ! empty( $data['error'] ) && current_user_can( 'manage_options' ) ? '<p class="extrachill-partner-notice">' . esc_html__( 'Extra Chill is unavailable. Check the API key in Settings → Extra Chill. Previously loaded events may be shown.', 'extrachill-partner' ) . '</p>' : '';
	}

	public static function events( $attributes = array() ) {
		wp_enqueue_style( 'extrachill-partner' );
		$data = Client::get( $attributes['limit'] ?? 10 );
		$html = self::notice( $data );
		if ( empty( $data['events'] ) ) {
			return $html . ( empty( $data['error'] ) ? '<p class="extrachill-partner-empty">' . esc_html__( 'No upcoming shows.', 'extrachill-partner' ) . '</p>' : '' );
		}
		$html .= '<ul class="extrachill-partner-events">';
		foreach ( $data['events'] as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$html .= '<li class="extrachill-partner-event">';
			$image = esc_url( self::text( $event['image'] ?? '' ) );
			if ( $image ) {
				$html .= '<img src="' . $image . '" alt="" loading="lazy">';
			}
			$html .= '<h3><a href="' . esc_url( self::text( $event['url'] ?? '' ) ) . '">' . esc_html( self::text( $event['title'] ?? '' ) ) . '</a></h3>';
			try {
				$start = self::text( $event['start'] ?? '' );
				$zone = self::text( $event['timezone'] ?? '' );
				if ( $start && $zone ) {
					$date = new \DateTimeImmutable( $start );
					$date = $date->setTimezone( new \DateTimeZone( $zone ) );
					$label = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $date->getTimestamp(), $date->getTimezone() );
					$html .= '<time datetime="' . esc_attr( $date->format( DATE_ATOM ) ) . '">' . esc_html( $label ) . '</time>';
				}
			} catch ( \Exception $exception ) {
				// Malformed remote dates must never interrupt page rendering.
			}
			$performers = isset( $event['performers'] ) && is_array( $event['performers'] ) ? array_filter( $event['performers'], 'is_string' ) : array();
			$html .= '<p class="extrachill-partner-performers">' . esc_html( implode( ', ', $performers ) ) . '</p>';
			$html .= '<p class="extrachill-partner-price">' . esc_html( self::text( $event['price'] ?? '' ) ) . '</p>';
			$ticket = isset( $event['ticket'] ) && is_array( $event['ticket'] ) ? $event['ticket'] : array();
			$url = esc_url( self::text( $ticket['url'] ?? '' ) );
			if ( $url ) {
				$html .= '<a class="extrachill-partner-buy" href="' . $url . '" rel="noopener">' . esc_html( self::text( $ticket['label'] ?? '' ) ) . '</a>';
			}
			$html .= '</li>';
			$id = isset( $event['id'] ) && is_scalar( $event['id'] ) ? (string) $event['id'] : '';
			if ( $id && ! isset( self::$schemas[ $id ] ) && ! empty( $event['schema'] ) && is_array( $event['schema'] ) ) {
				$json = wp_json_encode( $event['schema'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
				if ( false !== $json ) {
					self::$schemas[ $id ] = true;
					// Safe JSON encoding above prevents remote values closing the script element.
					$html .= '<script type="application/ld+json">' . $json . '</script>';
				}
			}
		}
		return $html . '</ul>';
	}

	public static function booking() {
		$data = Client::get( 1 );
		$url = self::text( $data['venue']['booking_embed_url'] ?? '' );
		$home = wp_parse_url( home_url( '/' ) );
		// The hosted embed accepts only standard-port HTTPS origins.
		$valid = is_array( $home ) && 'https' === ( $home['scheme'] ?? '' ) && ! empty( $home['host'] ) && ( ! isset( $home['port'] ) || 443 === $home['port'] );
		if ( ! $url || ! esc_url( $url ) || ! $valid ) {
			return defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ? '<p>' . esc_html__( 'Booking is not available for this venue or this site needs HTTPS.', 'extrachill-partner' ) . '</p>' : '';
		}
		wp_enqueue_style( 'extrachill-partner' );
		$url = add_query_arg( array( 'booking-embed' => '1', 'parent-origin' => 'https://' . strtolower( $home['host'] ) ), $url );
		return '<iframe class="extrachill-partner-booking" src="' . esc_url( $url ) . '" title="' . esc_attr__( 'Venue booking inquiry', 'extrachill-partner' ) . '" loading="lazy"></iframe>';
	}
}

<?php
/**
 * Events-hosted venue booking embed document.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\VenueBookingEmbed;

defined( 'ABSPATH' ) || exit;

$context = VenueBookingEmbed::context();
$markup  = do_blocks( '<!-- wp:extrachill/venue-booking-inquiry {"venueId":' . absint( $context['venue_id'] ?? 0 ) . '} /-->' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php // translators: %s is the venue name. ?>
	<title><?php echo esc_html( sprintf( __( 'Book %s', 'extrachill-events' ), $context['venue_name'] ?? '' ) ); ?></title>
	<?php wp_head(); ?>
	<style>html,body{margin:0;background:transparent}.ec-booking-embed{padding:1rem;max-width:72rem;margin:0 auto}.ec-booking-embed__fallback{text-align:center}</style>
</head>
<body class="ec-booking-embed-document">
	<main class="ec-booking-embed">
		<?php echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted dynamic block output. ?>
		<p class="ec-booking-embed__fallback"><a href="<?php echo esc_url( $context['booking_url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open this booking form on Extra Chill', 'extrachill-events' ); ?></a></p>
	</main>
	<script>
	( function () {
		const parentOrigin = <?php echo wp_json_encode( $context['parent_origin'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
		const sendHeight = () => window.parent.postMessage( { type: 'extrachill:booking-height', height: Math.ceil( document.documentElement.scrollHeight ) }, parentOrigin );
		new ResizeObserver( sendHeight ).observe( document.documentElement );
		window.addEventListener( 'load', sendHeight );
	}() );
	</script>
	<?php wp_footer(); ?>
</body>
</html>

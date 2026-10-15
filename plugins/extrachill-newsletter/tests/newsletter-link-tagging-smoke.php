<?php
/**
 * Smoke coverage for newsletter campaign link tagging.
 *
 * Run with: php tests/newsletter-link-tagging-smoke.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['ec_filters'] = array();
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['ec_filters'][ $hook ] ) ? $GLOBALS['ec_filters'][ $hook ]( $value, ...$args ) : $value;
}

require dirname( __DIR__ ) . '/inc/core/templates/link-tagging.php';

$failures = 0;
$checks   = 0;
function check( $label, $expected, $actual ) {
	global $failures, $checks;
	++$checks;
	if ( $expected !== $actual ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n" );
	}
}

$post   = (object) array( 'ID' => 69, 'post_name' => 'learn-how-to-use-ai-well' );
$params = extrachill_newsletter_campaign_utm_params( $post );
$q      = 'utm_source=newsletter&utm_medium=email&utm_campaign=learn-how-to-use-ai-well';

check( 'params from slug', array( 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'learn-how-to-use-ai-well' ), $params );
check( 'slug fallback', 'newsletter-7', extrachill_newsletter_campaign_utm_params( (object) array( 'ID' => 7, 'post_name' => '' ) )['utm_campaign'] );

check( 'main site', "https://extrachill.com/?{$q}", extrachill_newsletter_tag_url( 'https://extrachill.com', $params ) );
check( 'subdomain path', "https://events.extrachill.com/events/x/?{$q}", extrachill_newsletter_tag_url( 'https://events.extrachill.com/events/x/', $params ) );
check( 'existing query', "https://shop.extrachill.com/?p=1&{$q}", extrachill_newsletter_tag_url( 'https://shop.extrachill.com/?p=1', $params ) );
check( 'fragment kept last', "https://extrachill.com/a/?{$q}#rsvp", extrachill_newsletter_tag_url( 'https://extrachill.com/a/#rsvp', $params ) );
check( 'link pages', "https://extrachill.link/band?{$q}", extrachill_newsletter_tag_url( 'https://extrachill.link/band', $params ) );
check( 'external untouched', 'https://automattic.com/', extrachill_newsletter_tag_url( 'https://automattic.com/', $params ) );
check( 'lookalike untouched', 'https://notextrachill.com/', extrachill_newsletter_tag_url( 'https://notextrachill.com/', $params ) );
check( 'meetup untouched', 'https://www.meetup.com/wordpress-charleston/', extrachill_newsletter_tag_url( 'https://www.meetup.com/wordpress-charleston/', $params ) );
check( 'mailto untouched', 'mailto:chubes@extrachill.com', extrachill_newsletter_tag_url( 'mailto:chubes@extrachill.com', $params ) );
check( 'hand-tagged untouched', 'https://extrachill.com/?utm_source=ig', extrachill_newsletter_tag_url( 'https://extrachill.com/?utm_source=ig', $params ) );

$html   = '<a href="https://extrachill.com" style="x"><img src="https://extrachill.com/logo.png"></a>'
	. '<a href="https://shop.extrachill.com/?a=1&#038;b=2">Shop</a>'
	. '<a href="https://automattic.com/">A8C</a>'
	. "<a class='c' href='https://events.extrachill.com/'>Events</a>"
	. '<unsubscribe>Unsubscribe</unsubscribe>';
$tagged = extrachill_newsletter_tag_email_links( $html, $params );
$qa     = str_replace( '&', '&amp;', $q );

check( 'html: main href tagged', true, false !== strpos( $tagged, "href=\"https://extrachill.com/?{$qa}\" style=\"x\"" ) );
check( 'html: img src untouched', true, false !== strpos( $tagged, 'src="https://extrachill.com/logo.png"' ) );
check( 'html: encoded query preserved', true, false !== strpos( $tagged, "href=\"https://shop.extrachill.com/?a=1&amp;b=2&amp;{$qa}\"" ) );
check( 'html: external untouched', true, false !== strpos( $tagged, 'href="https://automattic.com/"' ) );
check( 'html: single quotes', true, false !== strpos( $tagged, "href='https://events.extrachill.com/?{$qa}'" ) );
check( 'html: sendy tag untouched', true, false !== strpos( $tagged, '<unsubscribe>Unsubscribe</unsubscribe>' ) );
check( 'html: idempotent', $tagged, extrachill_newsletter_tag_email_links( $tagged, $params ) );

$GLOBALS['ec_filters']['extrachill_newsletter_campaign_utm_params'] = function () {
	return array();
};
check( 'filter disables tagging', $html, extrachill_newsletter_tag_email_links( $html, extrachill_newsletter_campaign_utm_params( $post ) ) );

if ( $failures ) {
	fwrite( STDERR, "{$failures}/{$checks} checks failed\n" );
	exit( 1 );
}
echo "newsletter-link-tagging-smoke: {$checks} checks passed\n";

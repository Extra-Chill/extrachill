<?php
/**
 * Regression coverage for share destination URLs (extrachill#97).
 *
 * A title containing "&" used to be HTML-escaped and dropped into the query
 * string unencoded, so X/Reddit/Email split the query at the ampersand and
 * Bluesky shared a literal "&amp;". Each destination must round-trip the
 * plain-text title and the page URL through its query string.
 *
 * @package ExtraChill
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone fixture: WP doubles, failure reporting, and raw URL parsing are intentional.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Double for wp_strip_all_tags().
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/** Hook registration double. */
	function add_action() {}
}

require_once dirname( __DIR__ ) . '/inc/core/templates/share.php';

/**
 * Report a failure and exit non-zero.
 *
 * @param string $message Message.
 */
function su_fail( $message ) {
	fwrite( STDERR, 'FAIL: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Parse a URL's query string into an array.
 *
 * @param string $url URL.
 * @return array<string,string>
 */
function su_query( $url ) {
	$query = (string) parse_url( $url, PHP_URL_QUERY );
	if ( 0 === strpos( $url, 'mailto:' ) ) {
		$query = (string) substr( $url, strpos( $url, '?' ) + 1 );
	}
	parse_str( $query, $args );
	return $args;
}

$su_page = 'https://events.extrachill.com/events/wordpress-meetup-charleston-october-2026';
$plain   = 'Extra Chill & WordPress Meetup: "AI" Era';

// Every encoded form a caller can hand us must share the same plain text.
$inputs = array(
	'plain'          => $plain,
	'post_title'     => 'Extra Chill &amp; WordPress Meetup: &quot;AI&quot; Era',
	'get_the_title'  => 'Extra Chill &#038; WordPress Meetup: &#8220;AI&#8221; Era',
	'with_html_tags' => 'Extra Chill &amp; <em>WordPress</em> Meetup: "AI" Era',
);

foreach ( $inputs as $label => $su_title ) {
	$su_urls  = extrachill_share_urls( $su_page, $su_title );
	$expected = 'get_the_title' === $label ? "Extra Chill & WordPress Meetup: \u{201C}AI\u{201D} Era" : $plain;

	$checks = array(
		'twitter'  => array(
			'url'  => $su_page,
			'text' => $expected,
		),
		'reddit'   => array(
			'url'   => $su_page,
			'title' => $expected,
		),
		'email'    => array(
			'subject' => $expected,
			'body'    => 'Check out this: ' . $su_page,
		),
		'bluesky'  => array( 'text' => $expected . ' ' . $su_page ),
		'facebook' => array( 'u' => $su_page ),
	);

	foreach ( $checks as $destination => $want ) {
		$got = su_query( $su_urls[ $destination ] );
		foreach ( $want as $key => $value ) {
			if ( ! isset( $got[ $key ] ) || $got[ $key ] !== $value ) {
				su_fail( "{$label}/{$destination}: expected {$key}=" . var_export( $value, true ) . ', got ' . var_export( $got, true ) );
			}
		}
		if ( false !== strpos( $su_urls[ $destination ], '&amp;' ) || false !== strpos( $su_urls[ $destination ], '&#' ) ) {
			su_fail( "{$label}/{$destination}: URL still contains HTML entities: {$su_urls[ $destination ]}" );
		}
		if ( count( $got ) !== count( $want ) ) {
			su_fail( "{$label}/{$destination}: stray query arguments " . var_export( $got, true ) );
		}
	}
}

if ( 0 !== strpos( extrachill_share_urls( $su_page, $plain )['email'], 'mailto:?' ) ) {
	su_fail( 'Email destination must stay a mailto: URL.' );
}

echo "Share URLs round-trip plain, stored, and get_the_title() titles without entities or split query strings.\n";

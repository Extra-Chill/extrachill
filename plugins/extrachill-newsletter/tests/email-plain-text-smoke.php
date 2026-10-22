<?php
/**
 * Smoke coverage for readable newsletter plain text without changing the HTML.
 *
 * Run with: php tests/email-plain-text-smoke.php
 */

define( 'ABSPATH', __DIR__ );

function apply_filters( $hook, $value, ...$args ) {
	return $value;
}

function ec_get_site_url( $site ) {
	return 'https://' . $site . '.example.test';
}

function is_multisite() {
	return false;
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/' );
}

function get_permalink( $id ) {
	return 'https://newsletter.example.test/?p=' . $id;
}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_url( $url ) {
	return $url;
}

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function wp_strip_all_tags( $text ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	return trim( strip_tags( $text ) );
}

require_once dirname( __DIR__ ) . '/inc/core/templates/email-template.php';

$post = (object) array(
	'ID'           => 64,
	'post_title'   => 'Newsletter',
	// Represents filtered post content, including wptexturize entities.
	'post_content' => "\n\n<p>I&#8217;m hosting &amp; what you&#8217;re building.</p>\n\n\n\n<p>&quot;Hello&quot; &apos;world&apos; &mdash; &#x2019; &lt;example&gt;</p>\n\n<p>One blank line.</p>\n<p>One newline.</p>\n\n",
);
$email = prepare_newsletter_email_content( $post );

$failures = 0;
$check    = static function ( $label, $ok ) use ( &$failures ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		++$failures;
	}
};

$check(
	'plain text decodes numeric, named, quote and HTML5 entities after stripping tags',
	false !== strpos( $email['plain_text'], "I’m hosting & what you’re building.\n\n\"Hello\" 'world' — ’ <example>" )
);
$check(
	'plain text collapses excess newlines, preserves shorter runs and trims edges',
	"I’m hosting & what you’re building.\n\n\"Hello\" 'world' — ’ <example>\n\nOne blank line.\nOne newline." === $email['plain_text']
);
$styled_content = str_replace( '<p>', '<p style="font-size: 16px; line-height: 1.75em;">', $post->post_content );
$check(
	'HTML retains the original entities and whitespace with existing paragraph styling',
	false !== strpos( $email['html_template'], $styled_content )
);
$check( 'subject is unchanged', $post->post_title === $email['subject'] );

if ( $failures ) {
	exit( 1 );
}
echo "Newsletter plain-text smoke checks passed.\n";

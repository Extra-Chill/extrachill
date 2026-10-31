<?php
/**
 * Every newsletter form must carry a stable analytics id.
 *
 * extrachill-analytics' delegated form_submit listener deliberately skips a
 * form with no data-ec-track, id or name: an anonymous form_submit row is
 * worse than none. Without this attribute, newsletter submits across the
 * network are invisible to the CTA funnel. The id is derived from the form's
 * context, so each placement (navigation, footer, homepage, ...) is
 * distinguishable in reports.
 */

$template = file_get_contents( dirname( __DIR__ ) . '/inc/core/templates/forms/generic-form.php' );

$failures = 0;
$check    = static function ( $label, $ok ) use ( &$failures ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		++$failures;
	}
};

$check(
	'form tag carries a data-ec-track attribute',
	1 === preg_match( '/<form\b.*?data-ec-track="/s', $template )
);
$check(
	'the tracking id is derived from the form context',
	false !== strpos( $template, "'newsletter-' . sanitize_key( \$context )" )
);
$check(
	'the existing newsletter hooks are untouched',
	false !== strpos( $template, 'data-newsletter-form' ) && false !== strpos( $template, 'data-newsletter-context=' )
);

if ( $failures ) {
	exit( 1 );
}
echo "Newsletter form tracking checks passed.\n";

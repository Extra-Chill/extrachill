<?php
/**
 * User docs voice checker smoke tests.
 *
 * Run with: php tests/user-docs-voice-smoke.php
 */

require dirname( __DIR__ ) . '/scripts/check-user-docs-voice.php';

$failures = array();
$flags    = static function ( string $text, string $label ) use ( &$failures ): void {
	$labels = array_column( ec_user_docs_voice_violations( $text ), 'label' );
	if ( ! in_array( $label, $labels, true ) ) {
		$failures[] = "expected '{$label}' for: {$text}";
	}
};
$clean    = static function ( string $text ) use ( &$failures ): void {
	$found = ec_user_docs_voice_violations( $text );
	if ( $found ) {
		$failures[] = "expected clean, got '{$found[0]['label']}' (\"{$found[0]['match']}\") for: {$text}";
	}
};

// Forbidden: software, infrastructure, code, follow feature.
$flags( 'This plugin lets you add links.', 'developer term' );
$flags( 'Built on WordPress.', 'plugin or package name' );
$flags( 'The extrachill-events calendar.', 'plugin or package name' );
$flags( 'Emails are sent through Sendy.', 'vendor or service name' );
$flags( 'Use the API to import shows.', 'protocol or api term' );
$flags( 'Select taxonomy links on an event.', 'developer term' );
$flags( 'Results are cached in the database.', 'developer term' );
$flags( 'Run `wp extrachill`.', 'code' );
$flags( "```\ncode\n```", 'code' );
$flags( 'Click "Follow" to add their posts to your feed.', 'follow feature' );
$flags( 'Visit a profile to follow users you like.', 'follow feature' );
$flags( 'Get notified about new followers.', 'follow feature' );

// Allowed: everyday language that merely resembles a forbidden term.
$clean( 'Follow these steps to submit an event.' );
$clean( 'Your link page has its own web address you can share anywhere.' );
$clean( 'When you submit an event, our team reviews it before it appears on the calendar.' );
$clean( 'Mark a show as going and we will remind you before it starts.' );
$clean( 'See [Concert History Privacy](concert-history-privacy.md) for details.' );
$clean( 'Read more at https://docs.extrachill.com/events-calendar/api-free-guide/.' );

// Line numbers are 1-based.
$lines = ec_user_docs_voice_violations( "Fine line.\nBuilt on WordPress." );
if ( 2 !== ( $lines[0]['line'] ?? 0 ) ) {
	$failures[] = 'violation line numbers are 1-based';
}

if ( $failures ) {
	fwrite( STDERR, "User docs voice smoke tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "User docs voice smoke tests passed.\n";

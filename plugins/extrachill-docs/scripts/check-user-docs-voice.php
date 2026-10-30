<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput -- Standalone CLI script run outside WordPress by docs-agent; it prints plain text to a terminal.
/**
 * Deterministic voice check for end-user help articles.
 *
 * Enforces the mechanical part of runner-configs/writing-rules.md: help
 * articles never name software, infrastructure, or code, and never describe
 * a follow feature (Extra Chill has none). Judgement-based rules (tone,
 * structure) stay with review.
 *
 * Usage: php check-user-docs-voice.php [docs-dir]   (default: docs/user)
 * Exit 0 when clean, 1 with one line per violation, 2 on bad input.
 *
 * docs-agent runs this as a drift check against its own output, fetched at
 * a pinned extrachill-docs commit. Keep it dependency-free.
 */

/**
 * Forbidden patterns: label => case-insensitive regex.
 *
 * @return array<string,string>
 */
function ec_user_docs_forbidden_patterns(): array {
	return array(
		'plugin or package name' => '/\bextrachill-[a-z0-9-]+\b|\bdata[- ]machine\b|\bwoocommerce\b|\bbbpress\b|\bwordpress\b/i',
		'vendor or service name' => '/\b(sendy|openai|anthropic|mailgun|cloudflare|redis|hetzner|discord bot|roadie)\b/i',
		'protocol or api term'   => '/\b(api|rest api|webhook|oauth|https? request|json|yaml|sql|endpoint)\b/i',
		'developer term'         => '/\b(plugin|post type|taxonomy|meta key|hook|filter hook|slug|repository|repo|developer|deploy(ed|s|ment)?|cron|database|cache|server-side|wp-cli|wp-admin|wp-content)\b/i',
		'code'                   => '/```|`[^`\n]+`/',
		'follow feature'         => '/\bfollow(ing)?\s+(users?|people|members?|artists?|venues?|someone|them|other users)\b|\bfollowers?\b|\bfollow button\b|"follow"|&quot;follow&quot;/i',
	);
}

/**
 * Check Markdown text. Pure function for tests.
 *
 * @param string $text Markdown content.
 * @return array<int,array{line:int,label:string,match:string}>
 */
function ec_user_docs_voice_violations( string $text ): array {
	$violations = array();
	foreach ( preg_split( '/\R/', $text ) as $index => $line ) {
		// Bare links are allowed to contain technical words in the URL itself.
		$scan = preg_replace( '/\]\([^)]*\)|https?:\/\/\S+/', ']', $line );
		foreach ( ec_user_docs_forbidden_patterns() as $label => $pattern ) {
			if ( preg_match( $pattern, (string) $scan, $m ) ) {
				$violations[] = array(
					'line'  => $index + 1,
					'label' => $label,
					'match' => $m[0],
				);
			}
		}
	}
	return $violations;
}

if ( PHP_SAPI === 'cli' && realpath( $argv[0] ?? '' ) === realpath( __FILE__ ) ) {
	$dir = rtrim( $argv[1] ?? 'docs/user', '/' );
	if ( ! is_dir( $dir ) ) {
		fwrite( STDERR, "No docs directory at {$dir}\n" );
		exit( 2 );
	}

	$files = glob( $dir . '/*.md' );
	$files = is_array( $files ) ? $files : array();
	$found = 0;
	foreach ( $files as $file ) {
		foreach ( ec_user_docs_voice_violations( (string) file_get_contents( $file ) ) as $v ) {
			++$found;
			printf( "%s:%d: %s (\"%s\")\n", $file, $v['line'], $v['label'], $v['match'] );
		}
	}

	if ( $found ) {
		printf( "%d writing-rule violation(s) in %d file(s). See runner-configs/writing-rules.md in Extra-Chill/extrachill-docs.\n", $found, count( $files ) );
		exit( 1 );
	}

	printf( "User docs voice check passed (%d file(s)).\n", count( $files ) );
	exit( 0 );
}

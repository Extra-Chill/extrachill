<?php
/**
 * AGENTS.md Section Generator
 *
 * @package ExtraChill\CLI
 */

namespace ExtraChill\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentsMdSection {

	/**
	 * Build the Markdown body for the Extra Chill CLI AGENTS.md section.
	 *
	 * @param string $wp The composed WP-CLI invocation prefix.
	 * @return string
	 */
	public static function render( $wp ) {
		$lines   = array();
		$lines[] = '### Extra Chill CLI';
		$lines[] = '';
		$lines[] = 'Extra Chill CLI owns the unified WP-CLI surface for Extra Chill platform operations. Feature plugins own the underlying abilities and business behavior.';
		$lines[] = '';
		$lines[] = '**Default routing**';
		$lines[] = "- Platform health and analytics: `{$wp} extrachill platform health` and `{$wp} extrachill analytics summary`";
		$lines[] = "- Events and venues: `{$wp} extrachill events --help` and `{$wp} extrachill venues --help`";
		$lines[] = "- Artists, users, and community: `{$wp} extrachill artists --help`, `{$wp} extrachill users --help`, and `{$wp} extrachill community --help`";
		$lines[] = "- Content and newsletters: `{$wp} extrachill content --help` and `{$wp} extrachill newsletter --help`";
		$lines[] = "- Diagnostics: `{$wp} extrachill analytics errors`, `{$wp} extrachill analytics 404 summary`, and `{$wp} extrachill cache status`";
		$lines[] = '';
		$lines[] = '**Multisite**';
		$lines[] = 'Keep the composed WP-CLI prefix and its site target. Use `--url=<site-url>` when a command needs data or abilities owned by another network site; without a site target, WP-CLI uses the main site.';
		$lines[] = '';
		$lines[] = '**Discovery**';
		$lines[] = "Use `{$wp} extrachill --help` and `{$wp} extrachill <namespace> --help` for the complete live command, subcommand, and options contract. Live `--help` is authoritative; this section intentionally does not enumerate every action.";

		return implode( "\n", $lines );
	}
}

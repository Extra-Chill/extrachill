<?php
/**
 * AGENTS.md Section Generator
 *
 * Generates the "Extra Chill CLI" section of the composed AGENTS.md file by
 * introspecting the real registered command tree instead of hand-maintaining
 * a heredoc. Walks every command registered in CommandRegistry, reflects over
 * each command class's public methods, and emits each namespace with its real
 * subcommands and their PHPDoc short descriptions.
 *
 * Context-safe: the section callback runs on `plugins_loaded` in web/cron
 * compose contexts where the WP-CLI runtime and the plugin's PSR-4 autoloader
 * (both guarded by `if ( WP_CLI )`) are NOT loaded. This generator therefore
 * resolves command class files directly from disk via the same PSR-4 layout
 * and reflects over the class files, never relying on the live WP-CLI runner.
 *
 * Pending Extra-Chill/data-machine#2613: once that shared
 * `CliCommandIntrospector` helper lands, this class can delegate the
 * reflection/parsing to it and keep only the CommandRegistry map local.
 *
 * @package ExtraChill\CLI
 */

namespace ExtraChill\CLI;

use ReflectionClass;
use ReflectionMethod;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentsMdSection {

	/**
	 * Build the Markdown body for the Extra Chill CLI AGENTS.md section.
	 *
	 * @param string $wp The `wp --allow-root --path=...` invocation prefix.
	 * @return string
	 */
	public static function render( $wp ) {
		$lines   = array();
		$lines[] = '### Extra Chill CLI';
		$lines[] = '';
		$lines[] = 'Platform-specific tooling wrapping common operations into unified commands.';
		$lines[] = "Discover everything: `{$wp} extrachill --help`";
		$lines[] = '';

		foreach ( self::collect_commands() as $command => $subcommands ) {
			// Strip the leading "extrachill " for the human-readable summary,
			// but keep the full invocation in the code span.
			$summary = self::summarize_subcommands( $subcommands );

			if ( '' !== $summary ) {
				$lines[] = "- `{$wp} {$command}` — {$summary}";
			} else {
				$lines[] = "- `{$wp} {$command}`";
			}

			foreach ( $subcommands as $sub ) {
				if ( '__default' === $sub['name'] ) {
					continue;
				}
				$desc = $sub['description'];
				if ( '' !== $desc ) {
					$lines[] = "  - `{$sub['name']}` — {$desc}";
				} else {
					$lines[] = "  - `{$sub['name']}`";
				}
			}
		}

		$lines[] = '';
		$lines[] = 'All commands support `--help` for full options and subcommand discovery.';

		return implode( "\n", $lines );
	}

	/**
	 * Walk the CommandRegistry and reflect each class into its subcommands.
	 *
	 * @return array<string, array<int, array{name:string, description:string}>>
	 *               command string => ordered list of subcommands.
	 */
	private static function collect_commands() {
		$out = array();

		foreach ( CommandRegistry::map() as $command => $class ) {
			$subcommands = self::reflect_subcommands( $class );
			$out[ $command ] = $subcommands;
		}

		return $out;
	}

	/**
	 * Reflect a command class into its list of public subcommands.
	 *
	 * Public methods are WP-CLI subcommands. The subcommand name is taken from
	 * the `@subcommand <name>` annotation when present, otherwise the method
	 * name with underscores converted to hyphens (WP-CLI's own convention).
	 * `__invoke` / `@subcommand __default` represent the directly-invokable
	 * namespace itself. Private, protected, static, and magic helper methods
	 * are skipped.
	 *
	 * @param class-string $class Command class.
	 * @return array<int, array{name:string, description:string}>
	 */
	private static function reflect_subcommands( $class ) {
		// The autoloader (registered before this section is composed) resolves
		// the class file and any traits it uses; class_exists triggers it.
		if ( ! class_exists( $class ) ) {
			return array();
		}

		try {
			$reflection = new ReflectionClass( $class );
		} catch ( \Throwable $e ) {
			return array();
		}

		$subcommands = array();

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			// Only methods declared on the command class itself.
			if ( $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
				continue;
			}

			if ( $method->isStatic() || $method->isConstructor() || $method->isDestructor() ) {
				continue;
			}

			$method_name = $method->getName();
			$doc         = $method->getDocComment() ?: '';

			// `__invoke` (or any method tagged @subcommand __default) maps to
			// the namespace itself, so it has no sub-word.
			$annotated = self::parse_subcommand_annotation( $doc );

			if ( '__invoke' === $method_name && '' === $annotated ) {
				$annotated = '__default';
			}

			if ( '' !== $annotated ) {
				$name = $annotated;
			} else {
				// Skip other magic methods that are not subcommands.
				if ( 0 === strpos( $method_name, '__' ) ) {
					continue;
				}
				$name = str_replace( '_', '-', $method_name );
			}

			$subcommands[] = array(
				'name'        => $name,
				'description' => self::parse_short_description( $doc ),
			);
		}

		return $subcommands;
	}

	/**
	 * Build a comma-separated summary of subcommand names for the headline.
	 *
	 * @param array<int, array{name:string, description:string}> $subcommands Subcommands.
	 * @return string
	 */
	private static function summarize_subcommands( $subcommands ) {
		$names = array();
		foreach ( $subcommands as $sub ) {
			if ( '__default' === $sub['name'] ) {
				continue;
			}
			$names[] = $sub['name'];
		}

		return implode( ', ', $names );
	}

	/**
	 * Parse the `@subcommand <name>` annotation from a docblock.
	 *
	 * @param string $doc Raw docblock.
	 * @return string Subcommand name, or '' when not annotated.
	 */
	private static function parse_subcommand_annotation( $doc ) {
		if ( preg_match( '/@subcommand\s+(\S+)/', $doc, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Parse the short description (first prose line) from a docblock.
	 *
	 * Mirrors how WP-CLI derives a command's summary for `--help`: the first
	 * non-empty content line of the docblock, before any `## SECTION` heading.
	 *
	 * @param string $doc Raw docblock.
	 * @return string
	 */
	private static function parse_short_description( $doc ) {
		if ( '' === $doc ) {
			return '';
		}

		// Strip the comment framing.
		$doc   = preg_replace( '#^/\*\*|\*/$#', '', $doc );
		$lines = preg_split( '/\r\n|\r|\n/', $doc );

		foreach ( $lines as $line ) {
			$line = preg_replace( '/^\s*\*\s?/', '', $line );
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			// Stop at structured sections / annotations.
			if ( 0 === strpos( $line, '##' ) || 0 === strpos( $line, '@' ) ) {
				return '';
			}

			// Drop a trailing period for a tighter inline summary.
			return rtrim( $line, '.' );
		}

		return '';
	}
}

<?php
/**
 * CLI Command Registration
 *
 * Registers all `wp extrachill` subcommands. Each command class wraps abilities
 * from the corresponding feature plugin.
 *
 * Architecture:
 *   extrachill-cli  →  WP-CLI surface (agents + operators)
 *   extrachill-api  →  REST surface (frontend + mobile)
 *   feature plugins →  abilities (core primitives)
 *
 * @package ExtraChill\CLI
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Register every command from the command map.
foreach ( ExtraChill\CLI\CommandRegistry::map() as $command => $command_class ) {
	WP_CLI::add_command( $command, $command_class );
}

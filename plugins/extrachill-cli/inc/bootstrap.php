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

// Analytics commands.
WP_CLI::add_command( 'extrachill analytics summary', ExtraChill\CLI\Commands\Analytics\SummaryCommand::class );
WP_CLI::add_command( 'extrachill analytics 404', ExtraChill\CLI\Commands\Analytics\FourOhFourCommand::class );

// SEO commands.
WP_CLI::add_command( 'extrachill seo redirects', ExtraChill\CLI\Commands\SEO\RedirectsCommand::class );

// Tools commands.
WP_CLI::add_command( 'extrachill tools qr', ExtraChill\CLI\Commands\Tools\QRCodeCommand::class );

// Users commands.
WP_CLI::add_command( 'extrachill users', ExtraChill\CLI\Commands\Users\BanCommand::class );

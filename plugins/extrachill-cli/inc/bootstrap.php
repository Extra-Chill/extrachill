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

// Events commands.
WP_CLI::add_command( 'extrachill events', ExtraChill\CLI\Commands\Events\LocationCommand::class );
WP_CLI::add_command( 'extrachill venues', ExtraChill\CLI\Commands\Events\VenueDiscoveryCommand::class );

// SEO commands.
WP_CLI::add_command( 'extrachill seo redirects', ExtraChill\CLI\Commands\SEO\RedirectsCommand::class );

// Tools commands.
WP_CLI::add_command( 'extrachill tools qr', ExtraChill\CLI\Commands\Tools\QRCodeCommand::class );

// Artists commands.
WP_CLI::add_command( 'extrachill artists', ExtraChill\CLI\Commands\Artists\ArtistCommand::class );

// Users commands.
WP_CLI::add_command( 'extrachill users', ExtraChill\CLI\Commands\Users\BanCommand::class );
WP_CLI::add_command( 'extrachill users access', ExtraChill\CLI\Commands\Users\ArtistAccessCommand::class );

// Community commands.
WP_CLI::add_command( 'extrachill community', ExtraChill\CLI\Commands\Community\CommunityCommand::class );

// Cache commands.
WP_CLI::add_command( 'extrachill cache', ExtraChill\CLI\Commands\Cache\WarmCommand::class );

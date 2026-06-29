<?php
/**
 * Command Registry
 *
 * Single source of truth mapping `wp extrachill ...` command strings to their
 * implementing command classes. Both the WP-CLI bootstrap (which calls
 * WP_CLI::add_command for each entry) and the AGENTS.md section generator
 * (which reflects over each class to enumerate real subcommands) read from
 * this map, so the documented CLI surface can never drift from what is
 * actually registered.
 *
 * @package ExtraChill\CLI
 */

namespace ExtraChill\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommandRegistry {

	/**
	 * Map of command string => fully-qualified command class.
	 *
	 * Keys are the exact strings passed to WP_CLI::add_command (the command
	 * namespace, e.g. "extrachill venues" or "extrachill users access").
	 * Order here determines both registration order and documentation order.
	 *
	 * @return array<string, class-string>
	 */
	public static function map() {
		return array(
			// Platform commands.
			'extrachill platform'                    => Commands\Platform\HealthCommand::class,

			// Analytics commands.
			'extrachill analytics summary'           => Commands\Analytics\SummaryCommand::class,
			'extrachill analytics 404'               => Commands\Analytics\FourOhFourCommand::class,
			'extrachill analytics attacks'           => Commands\Analytics\AttacksCommand::class,
			'extrachill analytics search-gaps'       => Commands\Analytics\SearchGapsCommand::class,
			'extrachill analytics errors'            => Commands\Analytics\ErrorsCommand::class,
			'extrachill analytics retention'         => Commands\Analytics\RetentionCommand::class,
			'extrachill analytics growth'            => Commands\Analytics\GrowthCommand::class,
			'extrachill analytics demand-drill'      => Commands\Analytics\DemandDrillCommand::class,
			'extrachill analytics conversion'        => Commands\Analytics\ConversionCommand::class,
			'extrachill analytics crosslink-targets' => Commands\Analytics\CrosslinkTargetsCommand::class,
			'extrachill analytics outbound'          => Commands\Analytics\OutboundCommand::class,
			'extrachill analytics stickiness'        => Commands\Analytics\StickinessCommand::class,
			'extrachill analytics content-audit'     => Commands\Analytics\ContentAuditCommand::class,
			'extrachill analytics content-flags'     => Commands\Analytics\ContentFlagsCommand::class,
			'extrachill analytics gsc-opportunities' => Commands\Analytics\GscOpportunitiesCommand::class,
			'extrachill analytics revenue'           => Commands\Analytics\RevenueCommand::class,

			// Events commands.
			'extrachill events'                      => Commands\Events\LocationCommand::class,
			'extrachill venues'                      => Commands\Events\VenueDiscoveryCommand::class,
			'extrachill shows'                       => Commands\Events\ConcertTrackingCommand::class,

			// SEO commands.
			'extrachill seo redirects'               => Commands\SEO\RedirectsCommand::class,

			// Tools commands.
			'extrachill tools qr'                    => Commands\Tools\QRCodeCommand::class,

			// Artists commands.
			'extrachill artists'                     => Commands\Artists\ArtistCommand::class,

			// Users commands.
			'extrachill users'                       => Commands\Users\BanCommand::class,
			'extrachill users access'                => Commands\Users\ArtistAccessCommand::class,
			'extrachill users team'                  => Commands\Users\TeamCommand::class,
			'extrachill users settings'              => Commands\Users\SettingsCommand::class,
			'extrachill users profile'               => Commands\Users\ProfileCommand::class,

			// Community commands.
			'extrachill community'                   => Commands\Community\CommunityCommand::class,

			// Cache commands.
			'extrachill cache'                       => Commands\Cache\WarmCommand::class,

			// Giveaway commands.
			'extrachill giveaway'                    => Commands\Giveaway\GiveawayCommand::class,

			// Newsletter commands.
			'extrachill newsletter'                  => Commands\Newsletter\NewsletterCommand::class,

			// Roadie commands — talk to the Extra Chill platform chat agent.
			'extrachill roadie'                      => Commands\Roadie\RoadieCommand::class,
		);
	}
}

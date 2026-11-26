<?php
/**
 * Command Registry
 *
 * Maps `wp extrachill ...` command strings to their implementing command
 * classes. The WP-CLI bootstrap registers every entry in this map.
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
	 * Order here determines registration order.
	 *
	 * @return array<string, class-string>
	 */
	public static function map() {
		return array(
			// Platform commands.
			'extrachill platform'                         => Commands\Platform\HealthCommand::class,
			'extrachill experiments'                      => Commands\Experiments\ExperimentsCommand::class,

			// Network commands — thin wrappers over extrachill-multisite/extrachill-network primitives.
			'extrachill network'                          => Commands\Network\MigratePostCommand::class,
			'extrachill network regenerate-og-card'       => Commands\Network\RegenerateOgCardCommand::class,

			// Analytics commands.
			'extrachill analytics summary'                => Commands\Analytics\SummaryCommand::class,
			'extrachill analytics 404'                    => Commands\Analytics\FourOhFourCommand::class,
			'extrachill analytics attacks'                => Commands\Analytics\AttacksCommand::class,
			'extrachill analytics search-gaps'            => Commands\Analytics\SearchGapsCommand::class,
			'extrachill analytics bot-filter-impact'      => Commands\Analytics\BotFilterImpactCommand::class,
			'extrachill analytics errors'                 => Commands\Analytics\ErrorsCommand::class,
			'extrachill analytics retention'              => Commands\Analytics\RetentionCommand::class,
			'extrachill analytics display-preferences'    => Commands\Analytics\DisplayPreferencesCommand::class,
			'extrachill analytics growth'                 => Commands\Analytics\GrowthCommand::class,
			'extrachill analytics demand-drill'           => Commands\Analytics\DemandDrillCommand::class,
			'extrachill analytics conversion'             => Commands\Analytics\ConversionCommand::class,
			'extrachill analytics route-transitions'      => Commands\Analytics\RouteTransitionsCommand::class,
			'extrachill analytics crosslink-targets'      => Commands\Analytics\CrosslinkTargetsCommand::class,
			'extrachill analytics crosslink-clicks'       => Commands\Analytics\CrosslinkClicksCommand::class,
			'extrachill analytics outbound'               => Commands\Analytics\OutboundCommand::class,
			'extrachill analytics stickiness'             => Commands\Analytics\StickinessCommand::class,
			'extrachill analytics content-audit'          => Commands\Analytics\ContentAuditCommand::class,
			'extrachill analytics content-flags'          => Commands\Analytics\ContentFlagsCommand::class,
			'extrachill analytics gsc-opportunities'      => Commands\Analytics\GscOpportunitiesCommand::class,
			'extrachill analytics revenue'                => Commands\Analytics\RevenueCommand::class,
			'extrachill analytics link-page-tables'       => Commands\Analytics\LinkPageTablesCommand::class,

			// Events commands.
			'extrachill events'                           => Commands\Events\LocationCommand::class,
			'extrachill events artist-coverage'           => Commands\Events\ArtistCoverageCommand::class,
			'extrachill events guest-list'                => Commands\Events\GuestListCommand::class,
			'extrachill events door-access'               => Commands\Events\DoorAccessCommand::class,
			'extrachill events add-city'                  => Commands\Events\AddCityCommand::class,
			'extrachill venues'                           => Commands\Events\VenueDiscoveryCommand::class,
			'extrachill venues expand'                    => Commands\Events\VenueExpandCommand::class,
			'extrachill venues partner-key'               => Commands\Events\VenuePartnerKeyCommand::class,
			'extrachill venues expand-report'             => Commands\Events\VenueExpansionReportCommand::class,
			'extrachill shows'                            => Commands\Events\ConcertTrackingCommand::class,
			'extrachill events ramp'                      => Commands\Events\EventSourceRampCommand::class,
			'extrachill events flows repair-locations'    => Commands\Events\RepairFlowLocationsCommand::class,
			'extrachill events locations audit-integrity' => Commands\Events\AuditLocationIntegrityCommand::class,
			'extrachill events locations reconcile-roots' => Commands\Events\ReconcileRootLocationsCommand::class,
			'extrachill events sync-genres'               => Commands\Events\SyncGenresCommand::class,
			'extrachill venues qualify-stats'             => Commands\Events\QualifyStatsCommand::class,
			'extrachill venues requalify-pending'         => Commands\Events\RequalifyPendingCommand::class,
			'extrachill venues requalify-flow'            => Commands\Events\RequalifyFlowCommand::class,
			'extrachill venues unqualifiable-flows'       => Commands\Events\UnqualifiableFlowsCommand::class,
			'extrachill venues classify-tier'             => Commands\Events\ClassifyVenueTierCommand::class,

			// SEO commands.
			'extrachill seo redirects'                    => Commands\SEO\RedirectsCommand::class,

			// Documentation migration.
			'extrachill docs migrate-source-keys'         => Commands\Docs\MigrateSourceKeysCommand::class,

			// Content commands.
			'extrachill content'                          => Commands\Content\TriviaCommand::class,
			'extrachill content photo-contest'            => Commands\Content\PhotoContestCommand::class,

			// Tools commands.
			'extrachill tools qr'                         => Commands\Tools\QRCodeCommand::class,

			// Artists commands.
			'extrachill artists'                          => Commands\Artists\ArtistCommand::class,
			'extrachill artists classify-genre'           => Commands\Artists\ClassifyGenreCommand::class,

			// Users commands.
			'extrachill users'                            => Commands\Users\BanCommand::class,
			'extrachill users access'                     => Commands\Users\ArtistAccessCommand::class,
			'extrachill users team'                       => Commands\Users\TeamCommand::class,
			'extrachill users wp-admin'                   => Commands\Users\WpAdminCommand::class,
			'extrachill users settings'                   => Commands\Users\SettingsCommand::class,
			'extrachill users profile'                    => Commands\Users\ProfileCommand::class,
			'extrachill users avatar-snapshot'            => Commands\Users\AvatarSnapshotCommand::class,

			// Community commands.
			'extrachill community'                        => Commands\Community\CommunityCommand::class,

			// Cache commands.
			'extrachill cache'                            => Commands\Cache\WarmCommand::class,

			// Giveaway commands.
			'extrachill giveaway'                         => Commands\Giveaway\GiveawayCommand::class,

			// Newsletter commands.
			'extrachill newsletter'                       => Commands\Newsletter\NewsletterCommand::class,

			// Shop commands — operator adapters over extrachill-shop abilities (run with --url=<shop site>).
			'extrachill shop sellers'                     => Commands\Shop\SellersCommand::class,
			'extrachill shop seller'                      => Commands\Shop\SellerCommand::class,
			'extrachill shop orders'                      => Commands\Shop\OrdersCommand::class,
			'extrachill shop order'                       => Commands\Shop\OrderCommand::class,
			'extrachill shop webhook'                     => Commands\Shop\WebhookCommand::class,
			'extrachill shop boost'                       => Commands\Shop\BoostCommand::class,
			'extrachill shop fees'                        => Commands\Shop\FeesCommand::class,

			// Roadie commands — talk to the Extra Chill platform chat agent.
		);
	}
}

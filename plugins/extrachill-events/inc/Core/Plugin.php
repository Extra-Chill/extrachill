<?php
/**
 * Extra Chill Events composition facade.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

/** Composes domain-local providers while preserving the historical plugin facade. */
final class ExtraChillEvents {

	/**
	 * Singleton plugin facade.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Registered integrations retained for the historical inspection contract.
	 *
	 * @var array
	 */
	private $integrations = array();

	/** Return the singleton plugin facade. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register each feature independently in its required order. */
	private function __construct() {
		try {
			\ExtraChillEvents\Providers\CliProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'cli', $error );
		}

		try {
			\ExtraChillEvents\Providers\IngestionProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'ingestion', $error );
		}

		try {
			\ExtraChillEvents\Providers\AdministrationProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'administration', $error );
		}

		try {
			\ExtraChillEvents\Providers\LifecycleProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'lifecycle', $error );
		}

		try {
			\ExtraChillEvents\Providers\CoreRuntimeProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'core-runtime', $error );
		}

		try {
			\ExtraChillEvents\Providers\ArtistUrlImportProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'artist-url-import', $error );
		}

		try {
			\ExtraChillEvents\Providers\PromoterAuthorityProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'promoter-authority', $error );
		}

		try {
			\ExtraChillEvents\Providers\VenueBookingProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'venue-booking', $error );
		}

		try {
			\ExtraChillEvents\Providers\VenueLinkPagesProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'venue-link-pages', $error );
		}

		try {
			\ExtraChillEvents\Providers\PromoterLinkPagesProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'promoter-link-pages', $error );
		}

		try {
			\ExtraChillEvents\Providers\PublicExperienceProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'public-experience', $error );
		}

		try {
			\ExtraChillEvents\Providers\AbilitiesProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'abilities', $error );
		}

		try {
			\ExtraChillEvents\Providers\DataMachineEventsProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'data-machine-events', $error );
		}
	}

	/**
	 * Report one isolated provider failure without suppressing later features.
	 *
	 * @param string     $provider Provider identifier.
	 * @param \Throwable $error    Registration failure.
	 */
	private function provider_failed( string $provider, \Throwable $error ): void {
		error_log( sprintf( 'Extra Chill Events provider "%s" failed: %s', $provider, $error->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Bootstrap failures must remain observable while unrelated providers continue.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'extrachill_events_provider_failed', $provider, $error );
		}
	}

	/** Preserve the historical integrations inspection contract. */
	public function get_integrations(): array {
		return $this->integrations;
	}

	/** Preserve the historical lifecycle facade. */
	public function activate(): void {
		\ExtraChillEvents\Providers\LifecycleProvider::activate();
	}

	/** Preserve the historical lifecycle facade. */
	public function deactivate(): void {
		\ExtraChillEvents\Providers\LifecycleProvider::deactivate();
	}

	/** Preserve the historical schema facade. */
	public function maybe_install_schema(): void {
		\ExtraChillEvents\Providers\LifecycleProvider::maybe_install_schema();
	}

	/**
	 * Preserve the historical feature-ceiling facade.
	 *
	 * @param array $ceilings Existing feature ceilings.
	 * @return array
	 */
	public function register_feature_ceilings( array $ceilings ): array {
		return \ExtraChillEvents\Providers\LifecycleProvider::register_feature_ceilings( $ceilings );
	}

	/** Preserve the historical localization facade. */
	public function load_textdomain(): void {
		\ExtraChillEvents\Providers\LifecycleProvider::load_textdomain();
	}

	/** Preserve the historical moderation-admin facade. */
	public function init_artist_url_admin(): void {
		\ExtraChillEvents\Providers\AdministrationProvider::register_artist_url_admin();
	}

	/** Preserve the historical ability facade. */
	public function init_abilities(): void {
		\ExtraChillEvents\Providers\AbilitiesProvider::initialize();
	}
}

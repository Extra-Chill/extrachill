<?php
/**
 * Extra Chill Events composition facade.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

/** Composes domain-local providers. */
final class ExtraChillEvents {

	/**
	 * Singleton plugin facade.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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
			\ExtraChillEvents\Providers\EventSourceIntakeProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'artist-url-import', $error );
		}

		try {
			\ExtraChillEvents\Providers\PromoterAuthorityProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'promoter-authority', $error );
		}

		try {
			\ExtraChillEvents\Providers\VenueProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'venue', $error );
		}

		try {
			\ExtraChillEvents\Providers\LinkPagesProvider::register();
		} catch ( \Throwable $error ) {
			$this->provider_failed( 'link-pages', $error );
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
}

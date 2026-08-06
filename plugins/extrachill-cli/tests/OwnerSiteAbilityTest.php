<?php
/**
 * Contract tests for owner-site ability resolution.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class OwnerSiteAbilityTestError extends \RuntimeException {}

	class WP_CLI {
		public static function error( $message ) {
			throw new OwnerSiteAbilityTestError( $message );
		}
	}

	$owner_site_current_blog_id = 7;
	$owner_site_registry_ready  = false;
	$owner_site_registry_inits  = 0;
	$owner_site_registry_reads  = 0;
	$owner_site_abilities       = array(
		'extrachill/market-report'               => (object) array( 'name' => 'market-report' ),
		'extrachill/reconcile-event-locations'   => (object) array( 'name' => 'reconcile-event-locations' ),
	);

	function ec_get_blog_id( $site_key ) {
		return array(
			'events' => 7,
			'artist' => 4,
		)[ $site_key ] ?? 0;
	}

	function ec_get_site_url( $site_key ) {
		return 'https://' . $site_key . '.extrachill.com';
	}

	function get_current_blog_id() {
		global $owner_site_current_blog_id;
		return $owner_site_current_blog_id;
	}

	function get_home_url( $blog_id ) {
		return 'https://site-' . $blog_id . '.example.com';
	}

	function wp_get_ability( $name ) {
		global $owner_site_abilities, $owner_site_registry_inits, $owner_site_registry_reads, $owner_site_registry_ready;

		++$owner_site_registry_reads;
		if ( ! $owner_site_registry_ready ) {
			$owner_site_registry_ready = true;
			++$owner_site_registry_inits;
		}

		return $owner_site_abilities[ $name ] ?? null;
	}

	function owner_site_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}

	function owner_site_assert_contains( $needle, $haystack, $message ) {
		if ( false === strpos( $haystack, $needle ) ) {
			throw new \RuntimeException( $message . '\nMissing: ' . $needle . '\nActual: ' . $haystack );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/OwnerSiteAbility.php';

	use ExtraChill\CLI\OwnerSiteAbility;

	// The first lookup may initialize the lazy registry after owner callbacks are composed.
	$ability = OwnerSiteAbility::get( 'events', 'Events', 'extrachill/market-report' );
	owner_site_assert_same( 'market-report', $ability->name, 'Correct owner-site lookup must return the registered ability.' );
	owner_site_assert_same( 1, $owner_site_registry_inits, 'The first lookup must initialize the registry exactly once.' );

	// A lookup after another ability initialized the registry must remain a read only operation.
	$ability = OwnerSiteAbility::get( 'events', 'Events', 'extrachill/reconcile-event-locations' );
	owner_site_assert_same( 'reconcile-event-locations', $ability->name, 'Lookup after registry initialization must succeed.' );
	OwnerSiteAbility::get( 'events', 'Events', 'extrachill/market-report' );
	owner_site_assert_same( 1, $owner_site_registry_inits, 'Repeated lookups must not initialize or register abilities again.' );
	owner_site_assert_same( 3, $owner_site_registry_reads, 'Each resolution must perform only its public registry read.' );

	$owner_site_current_blog_id = 1;
	try {
		OwnerSiteAbility::get( 'events', 'Events', 'extrachill/market-report' );
		throw new \RuntimeException( 'Wrong-site lookup should fail.' );
	} catch ( OwnerSiteAbilityTestError $error ) {
		owner_site_assert_contains( '--url=https://events.extrachill.com', $error->getMessage(), 'Wrong-site errors must provide the owner URL.' );
	}
	owner_site_assert_same( 3, $owner_site_registry_reads, 'Wrong-site resolution must not touch the ability registry.' );

	$owner_site_current_blog_id = 4;
	try {
		OwnerSiteAbility::get( 'artist', 'Artist Platform', 'extrachill/missing-owner-ability' );
		throw new \RuntimeException( 'Missing owner ability should fail.' );
	} catch ( OwnerSiteAbilityTestError $error ) {
		owner_site_assert_contains( 'Ensure its owner plugin is active', $error->getMessage(), 'Missing-owner errors must explain how to restore composition.' );
		owner_site_assert_contains( 'https://artist.extrachill.com', $error->getMessage(), 'Missing-owner errors must identify the owner URL.' );
	}

	$events_source = file_get_contents( dirname( __DIR__ ) . '/inc/Commands/Events/LocationCommand.php' );
	$artist_source = file_get_contents( dirname( __DIR__ ) . '/inc/Commands/Artists/ArtistCommand.php' );
	$source        = $events_source . $artist_source;
	owner_site_assert_same( false, strpos( $source, 'WP_PLUGIN_DIR' ), 'Commands must not define sibling plugin paths.' );
	owner_site_assert_same( false, strpos( $source, 'require_once' ), 'Commands must not require sibling plugin files.' );
	owner_site_assert_same( false, strpos( $source, 'ExtraChillEvents\\Abilities' ), 'Commands must not name sibling ability classes.' );
	owner_site_assert_same( false, strpos( $source, 'extrachill_artist_platform_register_abilities' ), 'Commands must not call sibling registration functions.' );
	owner_site_assert_same( false, strpos( $source, 'switch_to_blog' ), 'Commands must use native owner-site bootstrap instead of switching database context.' );

	echo "OwnerSiteAbilityTest passed\n";
}

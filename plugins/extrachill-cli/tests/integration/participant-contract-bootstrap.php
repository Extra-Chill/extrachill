<?php
/** Fault-inject the Analytics participant contract in a disposable CLI process. */

WP_CLI::add_wp_hook(
	'plugins_loaded',
	static function () {
		$mode = getenv( 'EC_ANALYTICS_PARTICIPANT_FAULT' );
		if ( ! in_array( $mode, array( 'missing', 'outdated' ), true ) || ! function_exists( 'ec_link_page_migration_participant_registry' ) ) {
			return;
		}
		$registry = ec_link_page_migration_participant_registry();
		$property = new ReflectionProperty( $registry, 'participants' );
		$property->setAccessible( true );
		$participants = $property->getValue( $registry );
		if ( 'missing' === $mode ) {
			unset( $participants['analytics'] );
		} elseif ( isset( $participants['analytics'] ) ) {
			$participants['analytics']['contract_version'] = '0';
		}
		$property->setValue( $registry, $participants );
	},
	PHP_INT_MAX
);

<?php
/** Docs source migration CLI adapter. */
namespace ExtraChill\CLI\Commands\Docs;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

class MigrateSourceKeysCommand {
	/**
	 * Migrate documentation keys and retire legacy posts on the docs site.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write changes. Default is dry-run.
	 *
	 * [--dry-run]
	 * : Explicitly request a read-only plan.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		if ( $args || array_diff( array_keys( $assoc_args ), array( 'apply', 'dry-run' ) ) ) {
			WP_CLI::error( 'Only --apply or --dry-run is supported.' );
		}
		if ( isset( $assoc_args['apply'], $assoc_args['dry-run'] ) ) {
			WP_CLI::error( '--apply and --dry-run are mutually exclusive.' );
		}
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill-docs/migrate-source-keys' ) : null;
		if ( ! $ability ) { WP_CLI::error( 'Docs migration ability unavailable. Run on the docs site with Extra Chill Docs active.' ); }
		$result = $ability->execute( array( 'apply' => isset( $assoc_args['apply'] ) ) );
		if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
		WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( empty( $result['success'] ) ) { WP_CLI::error( 'Migration failed; inspect the report.' ); }
	}
}

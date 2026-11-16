<?php
/**
 * Explicit cleanup of legacy artist taxonomy terms on Shop products.
 *
 * The Stripe-record and paid-boost branches were removed (#1401): production
 * had no legacy artist Stripe meta and no paid orders.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the explicit dry-run migration command.
 */
function extrachill_shop_register_owned_state_migration_command() {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}

	WP_CLI::add_command(
		'extrachill-shop migrate-owned-commerce-state',
		function ( $args, $assoc_args ) {
			unset( $args );
			$apply  = ! empty( $assoc_args['apply'] );
			$report = extrachill_shop_run_owned_state_migration( $apply );
			WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
			if ( ! empty( $report['error'] ) ) {
				WP_CLI::warning( 'Migration completed with unresolved records.' );
			} else {
				WP_CLI::success( $apply ? 'Migration applied.' : 'Dry run complete. Pass --apply to persist changes.' );
			}
		},
		array(
			'shortdesc' => 'Remove legacy artist taxonomy terms from Shop products.',
			'synopsis'  => array(
				array(
					'type'        => 'flag',
					'name'        => 'apply',
					'optional'    => true,
					'description' => 'Persist the migration. Omit for a dry run.',
				),
			),
		)
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	extrachill_shop_register_owned_state_migration_command();
}

/**
 * Discover and optionally remove legacy artist terms on products.
 *
 * @param bool $apply Whether to persist changes.
 * @return array
 */
function extrachill_shop_run_owned_state_migration( $apply = false ) {
	$report = array_fill_keys( array( 'error', 'taxonomy_pending', 'taxonomy_removed' ), 0 );

	$product_ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_key'       => '_artist_profile_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicit one-time legacy migration.
			'fields'         => 'ids',
		)
	);
	foreach ( $product_ids as $product_id ) {
		$terms = wp_get_object_terms( $product_id, 'artist', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}
		if ( ! $apply ) {
			++$report['taxonomy_pending'];
			continue;
		}
		$removed = wp_set_object_terms( $product_id, array(), 'artist' );
		if ( is_wp_error( $removed ) ) {
			++$report['error'];
		} else {
			++$report['taxonomy_removed'];
		}
	}

	return $report;
}

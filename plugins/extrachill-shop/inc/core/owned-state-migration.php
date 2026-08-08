<?php
/**
 * Explicit migration into Shop-owned commerce storage.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import one legacy Artist-owned Stripe projection without overwriting conflicts.
 *
 * @param int   $artist_id Canonical artist ID.
 * @param array $legacy Legacy record.
 * @param bool  $apply Whether to persist the import.
 * @return string imported, unchanged, pending, or conflict.
 */
function extrachill_shop_migrate_stripe_record( $artist_id, array $legacy, $apply = false ) {
	$current = extrachill_shop_get_stripe_account_record( $artist_id );
	if ( $current ) {
		return hash_equals( (string) $current['account_id'], (string) ( $legacy['account_id'] ?? '' ) ) ? 'unchanged' : 'conflict';
	}

	if ( ! $apply ) {
		return 'pending';
	}

	return extrachill_shop_set_stripe_account_record( $artist_id, $legacy ) ? 'imported' : 'conflict';
}

/**
 * Migrate a paid boost through the Events owner ability.
 *
 * @param WC_Order $order Order object.
 * @param object   $item Order item.
 * @param bool     $apply Whether to execute the owner mutation.
 * @return string migrated, unchanged, pending, conflict, or error.
 */
function extrachill_shop_migrate_priority_boost_item( $order, $item, $apply = false ) {
	$receipt_key = '_extrachill_priority_boost_receipt_' . $item->get_id();
	if ( $order->get_meta( $receipt_key, true ) ) {
		return 'unchanged';
	}

	if ( ! $apply ) {
		return 'pending';
	}

	$result = extrachill_shop_grant_event_priority_boost( $order, $item );
	if ( is_wp_error( $result ) ) {
		return 'priority_boost_idempotency_conflict' === $result->get_error_code() ? 'conflict' : 'error';
	}

	$order->update_meta_data( $receipt_key, $result['receipt'] ?? array( 'migrated' => true ) );
	$order->save();
	return 'migrated';
}

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
			if ( ! empty( $report['conflict'] ) || ! empty( $report['error'] ) ) {
				WP_CLI::warning( 'Migration completed with unresolved records.' );
			} else {
				WP_CLI::success( $apply ? 'Migration applied.' : 'Dry run complete. Pass --apply to persist changes.' );
			}
		},
		array(
			'shortdesc' => 'Migrate legacy Stripe and paid boost state into owner-bound storage.',
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
 * Discover and optionally migrate legacy records.
 *
 * @param bool $apply Whether to persist changes.
 * @return array
 */
function extrachill_shop_run_owned_state_migration( $apply = false ) {
	$report = array_fill_keys( array( 'pending', 'imported', 'migrated', 'unchanged', 'conflict', 'error', 'taxonomy_pending', 'taxonomy_removed' ), 0 );

	if ( function_exists( 'ec_get_blog_id' ) ) {
		$artist_blog_id = absint( ec_get_blog_id( 'artist' ) );
		if ( $artist_blog_id ) {
			switch_to_blog( $artist_blog_id );
			try {
				$legacy_artists = get_posts(
					array(
						'post_type'      => 'artist_profile',
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'meta_key'       => '_stripe_connect_account_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicit one-time legacy migration.
						'fields'         => 'ids',
					)
				);
				$stripe_records = array();
				foreach ( $legacy_artists as $artist_id ) {
					$stripe_records[ $artist_id ] = array(
						'account_id'          => (string) get_post_meta( $artist_id, '_stripe_connect_account_id', true ),
						'status'              => (string) get_post_meta( $artist_id, '_stripe_connect_status', true ),
						'onboarding_complete' => (bool) get_post_meta( $artist_id, '_stripe_connect_onboarding_complete', true ),
					);
				}
			} finally {
				restore_current_blog();
			}

			foreach ( $stripe_records as $artist_id => $record ) {
				++$report[ extrachill_shop_migrate_stripe_record( $artist_id, $record, $apply ) ];
			}
		}
	}

	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders(
			array(
				'limit'  => -1,
				'status' => array( 'wc-processing', 'wc-completed' ),
			)
		);
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! extrachill_shop_is_priority_boost_product_id( $item->get_product_id() ) || ! absint( $item->get_meta( 'priority_boost_event_id', true ) ) ) {
					continue;
				}
				++$report[ extrachill_shop_migrate_priority_boost_item( $order, $item, $apply ) ];
			}
		}
	}

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

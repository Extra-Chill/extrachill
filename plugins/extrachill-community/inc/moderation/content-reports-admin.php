<?php
/**
 * Content Report Moderation Queue (wp-admin)
 *
 * Minimal moderator queue on the `ec_report` list table: target, reason,
 * reporter, and status columns, with Resolve / Dismiss / Reopen row actions.
 * Status views (Open / Resolved / Dismissed) come from the registered report
 * post statuses. Everything is gated on the bbPress `moderate` capability.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report list table columns.
 *
 * @return array<string,string>
 */
function extrachill_community_report_admin_columns() {
	return array(
		'cb'        => '<input type="checkbox" />',
		'ec_target' => __( 'Target', 'extra-chill-community' ),
		'ec_reason' => __( 'Reason', 'extra-chill-community' ),
		'ec_rep'    => __( 'Reporter', 'extra-chill-community' ),
		'ec_status' => __( 'Status', 'extra-chill-community' ),
		'date'      => __( 'Reported', 'extra-chill-community' ),
	);
}
add_filter( 'manage_' . EXTRACHILL_COMMUNITY_REPORT_POST_TYPE . '_posts_columns', 'extrachill_community_report_admin_columns' );

/**
 * Make the target column primary so row actions render there.
 *
 * @param string $default_column Default primary column.
 * @param string $screen_id      Screen ID.
 * @return string
 */
function extrachill_community_report_admin_primary_column( $default_column, $screen_id ) {
	return 'edit-' . EXTRACHILL_COMMUNITY_REPORT_POST_TYPE === $screen_id ? 'ec_target' : $default_column;
}
add_filter( 'list_table_primary_column', 'extrachill_community_report_admin_primary_column', 10, 2 );

/**
 * Render report list table cells.
 *
 * @param string $column  Column key.
 * @param int    $post_id Report ID.
 */
function extrachill_community_report_admin_column( $column, $post_id ) {
	$report = get_post( $post_id );
	if ( ! $report ) {
		return;
	}

	switch ( $column ) {
		case 'ec_target':
			$type = (string) get_post_meta( $post_id, '_ec_report_target_type', true );
			$url  = (string) get_post_meta( $post_id, '_ec_report_target_url', true );
			$text = sprintf( '%s #%d', ucfirst( $type ), (int) get_post_meta( $post_id, '_ec_report_target_id', true ) );

			$author = get_userdata( (int) get_post_meta( $post_id, '_ec_report_target_author', true ) );
			if ( $author && 'user' !== $type ) {
				$text .= ' ' . sprintf( 'by %s', $author->display_name );
			} elseif ( $author ) {
				$text = sprintf( 'Member %s (#%d)', $author->display_name, $author->ID );
			}

			echo '<strong>';
			if ( $url ) {
				printf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $url ), esc_html( $text ) );
			} else {
				echo esc_html( $text );
			}
			echo '</strong>';

			if ( '' !== $report->post_content ) {
				printf( '<p>%s</p>', esc_html( $report->post_content ) );
			}
			break;

		case 'ec_reason':
			$reason  = (string) get_post_meta( $post_id, '_ec_report_reason', true );
			$reasons = extrachill_community_report_reasons();
			echo esc_html( $reasons[ $reason ] ?? $reason );
			break;

		case 'ec_rep':
			$reporter = get_userdata( (int) $report->post_author );
			echo esc_html( $reporter ? $reporter->display_name : '#' . (int) $report->post_author );
			break;

		case 'ec_status':
			$statuses = extrachill_community_report_statuses();
			echo esc_html( $statuses[ $report->post_status ] ?? $report->post_status );
			$resolved_by = get_userdata( (int) get_post_meta( $post_id, '_ec_report_resolved_by', true ) );
			if ( $resolved_by && EXTRACHILL_COMMUNITY_REPORT_OPEN !== $report->post_status ) {
				printf( '<br /><small>%s</small>', esc_html( sprintf( 'by %s', $resolved_by->display_name ) ) );
			}
			break;
	}
}
add_action( 'manage_' . EXTRACHILL_COMMUNITY_REPORT_POST_TYPE . '_posts_custom_column', 'extrachill_community_report_admin_column', 10, 2 );

/**
 * URL for a report status change.
 *
 * @param int    $report_id Report ID.
 * @param string $status    Target status.
 * @return string
 */
function extrachill_community_report_status_url( $report_id, $status ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'    => 'ec_report_set_status',
				'report_id' => (int) $report_id,
				'status'    => $status,
			),
			admin_url( 'admin-post.php' )
		),
		'ec_report_set_status_' . (int) $report_id
	);
}

/**
 * Replace default row actions with Resolve / Dismiss / Reopen.
 *
 * @param array $actions Row actions.
 * @param mixed $post    Report (filter argument; validated before use).
 * @return array
 */
function extrachill_community_report_row_actions( $actions, $post ) {
	if ( ! $post instanceof WP_Post || EXTRACHILL_COMMUNITY_REPORT_POST_TYPE !== $post->post_type ) {
		return $actions;
	}

	$actions = array();
	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- `moderate` is bbPress's forum moderator capability.
	if ( ! current_user_can( 'moderate' ) ) {
		return $actions;
	}

	if ( EXTRACHILL_COMMUNITY_REPORT_OPEN === $post->post_status ) {
		$actions['ec_resolve'] = sprintf( '<a href="%s">%s</a>', esc_url( extrachill_community_report_status_url( $post->ID, EXTRACHILL_COMMUNITY_REPORT_RESOLVED ) ), esc_html__( 'Resolve', 'extra-chill-community' ) );
		$actions['ec_dismiss'] = sprintf( '<a href="%s">%s</a>', esc_url( extrachill_community_report_status_url( $post->ID, EXTRACHILL_COMMUNITY_REPORT_DISMISSED ) ), esc_html__( 'Dismiss', 'extra-chill-community' ) );
	} else {
		$actions['ec_reopen'] = sprintf( '<a href="%s">%s</a>', esc_url( extrachill_community_report_status_url( $post->ID, EXTRACHILL_COMMUNITY_REPORT_OPEN ) ), esc_html__( 'Reopen', 'extra-chill-community' ) );
	}

	return $actions;
}
add_filter( 'post_row_actions', 'extrachill_community_report_row_actions', 10, 2 );

/**
 * Change a report's status. Returns true on success.
 *
 * @param int    $report_id    Report ID.
 * @param string $status       Target status.
 * @param int    $moderator_id Acting moderator.
 * @return bool
 */
function extrachill_community_set_report_status( $report_id, $status, $moderator_id ) {
	$report = get_post( (int) $report_id );
	if ( ! $report || EXTRACHILL_COMMUNITY_REPORT_POST_TYPE !== $report->post_type || ! array_key_exists( $status, extrachill_community_report_statuses() ) ) {
		return false;
	}

	$updated = wp_update_post(
		array(
			'ID'          => (int) $report_id,
			'post_status' => $status,
		),
		true
	);
	if ( is_wp_error( $updated ) ) {
		return false;
	}

	if ( EXTRACHILL_COMMUNITY_REPORT_OPEN === $status ) {
		delete_post_meta( (int) $report_id, '_ec_report_resolved_by' );
		delete_post_meta( (int) $report_id, '_ec_report_resolved_at' );
	} else {
		update_post_meta( (int) $report_id, '_ec_report_resolved_by', (int) $moderator_id );
		update_post_meta( (int) $report_id, '_ec_report_resolved_at', time() );
	}

	return true;
}

/**
 * Handle Resolve / Dismiss / Reopen row actions.
 */
function extrachill_community_handle_report_status_action() {
	$report_id = isset( $_GET['report_id'] ) ? absint( $_GET['report_id'] ) : 0;
	$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- `moderate` is bbPress's forum moderator capability.
	if ( ! current_user_can( 'moderate' ) ) {
		wp_die( esc_html__( 'You are not allowed to moderate reports.', 'extra-chill-community' ), 403 );
	}
	check_admin_referer( 'ec_report_set_status_' . $report_id );

	extrachill_community_set_report_status( $report_id, $status, get_current_user_id() );

	$redirect = wp_get_referer();
	if ( ! $redirect ) {
		$redirect = admin_url( 'edit.php?post_type=' . EXTRACHILL_COMMUNITY_REPORT_POST_TYPE );
	}
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_post_ec_report_set_status', 'extrachill_community_handle_report_status_action' );

/**
 * Reports are reviewed from the list table only; send the edit screen back there.
 */
function extrachill_community_report_block_edit_screen() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect.
	$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect.
	if ( $post_id && 'edit' === $action && EXTRACHILL_COMMUNITY_REPORT_POST_TYPE === get_post_type( $post_id ) ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . EXTRACHILL_COMMUNITY_REPORT_POST_TYPE ) );
		exit;
	}
}
add_action( 'load-post.php', 'extrachill_community_report_block_edit_screen' );

/**
 * Hide bulk Edit for reports (it would bypass review statuses).
 *
 * @param array $actions Bulk actions.
 * @return array
 */
function extrachill_community_report_bulk_actions( $actions ) {
	unset( $actions['edit'] );
	return $actions;
}
add_filter( 'bulk_actions-edit-' . EXTRACHILL_COMMUNITY_REPORT_POST_TYPE, 'extrachill_community_report_bulk_actions' );

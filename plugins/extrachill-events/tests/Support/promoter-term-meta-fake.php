<?php
/** Term-meta API backed by the transaction-aware test database snapshot. */
function ec_promoter_fake_table() { return 'wp_7_promoter_term_meta_fixture'; }
if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( $type, $id, $key ) { unset( $type ); return '' !== get_term_meta( $id, $key, true ); }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $id, $group ) { unset( $id, $group ); return true; }
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $id, $key, $single = true ) { unset( $single ); return ec_promoter_fake_get_meta( $id, $key ); }
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $id, $key, $value ) { return ec_promoter_fake_update_meta( $id, $key, $value ); }
}
function ec_promoter_fake_get_meta( $id, $key ) {
	foreach ( $GLOBALS['wpdb']->rows[ ec_promoter_fake_table() ] ?? array() as $row ) {
		if ( (int) $row['promoter_term_id'] !== (int) $id ) {
			continue;
		}
		return '_ec_promoter_status' === $key ? $row['status'] ?? '' : array_intersect_key( $row, array_flip( array( 'version', 'verified_by_user_id', 'verified_at', 'updated_at', 'revoked_by_user_id', 'revoked_at' ) ) );
	}
	return '';
}
function ec_promoter_fake_update_meta( $id, $key, $value ) {
	$db    = $GLOBALS['wpdb'];
	$table = ec_promoter_fake_table();
	if ( '_ec_promoter_verification' === $key ) {
		foreach ( $db->rows[ $table ] ?? array() as $index => $row ) {
			if ( (int) $row['promoter_term_id'] === (int) $id ) {
				$db->rows[ $table ][ $index ] = array_merge( $row, $value );
				return true;
			}
		}
		return $db->insert( $table, array_merge( array(
			'promoter_term_id' => $id,
			'status'           => 'active',
		), $value ) );
	}
	foreach ( $db->rows[ $table ] ?? array() as $index => $row ) {
		if ( (int) $row['promoter_term_id'] === (int) $id ) {
			$db->rows[ $table ][ $index ]['status'] = $value;
			return true;
		}
	}
	return false;
}

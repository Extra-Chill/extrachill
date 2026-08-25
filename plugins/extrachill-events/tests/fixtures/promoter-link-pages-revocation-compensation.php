<?php
/** Deterministic promoter revocation owner-audit compensation fixture. */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $data;
	public function __construct( $code, $message = '', $data = null ) {
		unset( $message );
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_code() {
		return $this->code; }
	public function get_error_data() {
		return $this->data; }
}

$GLOBALS['promoter_revocation_fixture'] = array(
	'audit'        => array( 'state' => 'before' ),
	'restore_fail' => false,
);

function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function __( $text ) {
	return $text; }
function absint( $value ) {
	return abs( (int) $value ); }
function ec_get_blog_id( $site ) {
	return 'events' === $site ? 7 : 0; }
function ec_normalize_link_page_owner_reference( $owner ) {
	return sprintf( 'term:%d:%s:%d', $owner['blog_id'], $owner['subtype'], $owner['object_id'] ); }
function ec_get_link_page_id_for_owner( $reference ) {
	unset( $reference );
	return 40; }
function ec_with_link_page_storage_blog( $callback ) {
	return $callback(); }
function ec_get_stored_link_page_owner_references( $link_page_id ) {
	unset( $link_page_id );
	return array( 'term:7:promoter:30' ); }
function ec_snapshot_link_page_meta( $link_page_id, $meta_key ) {
	unset( $link_page_id, $meta_key );
	return array(
		'exists' => true,
		'value'  => $GLOBALS['promoter_revocation_fixture']['audit'],
	); }
function ec_write_link_page_meta( $link_page_id, $meta_key, $value, $delete = false ) {
	unset( $link_page_id, $meta_key, $delete );
	$GLOBALS['promoter_revocation_fixture']['audit'] = $value;
	return true;
}
function ec_restore_link_page_meta_snapshots( $link_page_id, $snapshots ) {
	unset( $link_page_id );
	if ( $GLOBALS['promoter_revocation_fixture']['restore_fail'] ) {
		return false;
	}
	$GLOBALS['promoter_revocation_fixture']['audit'] = $snapshots['_extrachill_events_promoter_link_page_orphaned']['value'];
	return true;
}
function wp_update_post( $data, $wp_error = false ) {
	unset( $data, $wp_error );
	return new WP_Error( 'simulated_draft_failure' ); }
function get_post_field( $field, $post_id ) {
	unset( $field, $post_id );
	return 'publish'; }

require_once dirname( __DIR__, 2 ) . '/inc/Core/PromoterLinkPages.php';

$restored = ExtraChillEvents\Core\PromoterLinkPages::authority_precommit( true, 30, 'organization_revoked' );
$restored_audit = $GLOBALS['promoter_revocation_fixture']['audit'];

$GLOBALS['promoter_revocation_fixture']['audit']        = array( 'state' => 'before' );
$GLOBALS['promoter_revocation_fixture']['restore_fail'] = true;
$restore_failed = ExtraChillEvents\Core\PromoterLinkPages::authority_precommit( true, 30, 'organization_revoked' );

echo json_encode(
	array(
		'restored'       => array(
			'error' => is_wp_error( $restored ) ? $restored->get_error_code() : '',
			'audit' => $restored_audit,
		),
		'restore_failed' => array(
			'error' => is_wp_error( $restore_failed ) ? $restore_failed->get_error_code() : '',
			'cause' => is_wp_error( $restore_failed ) ? ( $restore_failed->get_error_data()['cause'] ?? '' ) : '',
		),
	)
);

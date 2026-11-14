<?php
/**
 * Legal documents and seller consent records.
 *
 * One source of truth for the network legal document URLs and versions the
 * Shop asks buyers and sellers to accept, plus the Shop-owned record of each
 * seller's Seller Agreement acceptance.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legal documents the Shop links to and records acceptance of.
 *
 * Bump a document's `version` when its text changes materially: buyers then
 * record the new version on their next order, and sellers must accept the new
 * Seller Agreement before their next Stripe onboarding link.
 *
 * @return array{
 *     terms: array{url: string, version: string},
 *     seller_agreement: array{url: string, version: string},
 *     privacy: array{url: string}
 * }
 */
function extrachill_shop_legal_documents(): array {
	$defaults = array(
		'terms'            => array(
			'url'     => 'https://extrachill.com/terms-of-service/',
			'version' => '2026-11-14',
		),
		'seller_agreement' => array(
			'url'     => 'https://extrachill.com/seller-agreement/',
			'version' => '2026-11-14',
		),
		'privacy'          => array(
			'url' => 'https://extrachill.com/privacy-policy/',
		),
	);

	/**
	 * Filter the Shop's legal document URLs and versions.
	 *
	 * @param array $documents Documents keyed by terms, seller_agreement, privacy.
	 */
	$documents = apply_filters( 'extrachill_shop_legal_documents', $defaults );
	if ( ! is_array( $documents ) ) {
		return $defaults;
	}

	foreach ( $defaults as $key => $document ) {
		$documents[ $key ] = is_array( $documents[ $key ] ?? null )
			? array_merge( $document, $documents[ $key ] )
			: $document;
	}
	return $documents;
}

/**
 * Read one legal document field.
 *
 * @param string $document Document key: terms, seller_agreement, or privacy.
 * @param string $field    Field: url or version.
 * @return string
 */
function extrachill_shop_legal_document( string $document, string $field ): string {
	$documents = extrachill_shop_legal_documents();
	return (string) ( $documents[ $document ][ $field ] ?? '' );
}

/**
 * Option name holding a seller's Seller Agreement acceptances.
 *
 * Acceptances are keyed per seller, then by the accepting user, because a
 * venue or promoter can have several finance managers.
 *
 * @param array $seller Validated seller reference.
 * @return string
 */
function extrachill_shop_seller_agreement_option_name( array $seller ): string {
	return 'extrachill_shop_seller_agreement_' . $seller['type'] . ':' . (int) $seller['id'];
}

/**
 * Read a user's recorded Seller Agreement acceptance for a seller.
 *
 * @param mixed $seller  Seller reference.
 * @param int   $user_id User ID.
 * @return array|false { user_id, seller, version, accepted_at } or false.
 */
function extrachill_shop_get_seller_agreement_acceptance( $seller, int $user_id ) {
	$seller = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) || $user_id < 1 ) {
		return false;
	}
	$records = get_option( extrachill_shop_seller_agreement_option_name( $seller ), array() );
	$record  = is_array( $records ) ? ( $records[ $user_id ] ?? false ) : false;
	return is_array( $record ) ? $record : false;
}

/**
 * Whether a user has accepted the current Seller Agreement for a seller.
 *
 * @param mixed $seller  Seller reference.
 * @param int   $user_id User ID.
 * @return bool
 */
function extrachill_shop_has_accepted_seller_agreement( $seller, int $user_id ): bool {
	$record = extrachill_shop_get_seller_agreement_acceptance( $seller, $user_id );
	return $record && extrachill_shop_legal_document( 'seller_agreement', 'version' ) === ( $record['version'] ?? '' );
}

/**
 * Record a user's acceptance of the current Seller Agreement for a seller.
 *
 * @param mixed $seller  Seller reference.
 * @param int   $user_id Accepting user ID.
 * @return array|WP_Error Stored acceptance record.
 */
function extrachill_shop_record_seller_agreement_acceptance( $seller, int $user_id ) {
	$seller = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	if ( $user_id < 1 ) {
		return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in to accept the Seller Agreement.', 'extrachill-shop' ), array( 'status' => 401 ) );
	}

	$option  = extrachill_shop_seller_agreement_option_name( $seller );
	$records = get_option( $option, array() );
	$records = is_array( $records ) ? $records : array();

	$record              = array(
		'user_id'     => $user_id,
		'seller'      => $seller,
		'version'     => extrachill_shop_legal_document( 'seller_agreement', 'version' ),
		'accepted_at' => gmdate( 'c' ),
	);
	$records[ $user_id ] = $record;

	if ( ! update_option( $option, $records, false ) && get_option( $option, array() ) !== $records ) {
		return new WP_Error( 'seller_agreement_not_recorded', __( 'Your Seller Agreement acceptance could not be saved.', 'extrachill-shop' ), array( 'status' => 500 ) );
	}
	return $record;
}

/**
 * Error returned when Stripe onboarding needs Seller Agreement acceptance.
 *
 * @return WP_Error
 */
function extrachill_shop_seller_agreement_required_error(): WP_Error {
	$url     = extrachill_shop_legal_document( 'seller_agreement', 'url' );
	$version = extrachill_shop_legal_document( 'seller_agreement', 'version' );
	return new WP_Error(
		'seller_agreement_required',
		sprintf(
			/* translators: %s: Seller Agreement URL. */
			__( 'Please accept the Seller Agreement (%s) before connecting Stripe.', 'extrachill-shop' ),
			$url
		),
		array(
			'status'                   => 403,
			'seller_agreement_url'     => $url,
			'seller_agreement_version' => $version,
		)
	);
}

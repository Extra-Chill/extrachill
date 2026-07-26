<?php
/**
 * Operation-specific delegated campaign record receipts.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

/** Build the immutable source/policy identity option name. */
function extrachill_newsletter_delegated_campaign_source_option( array $source, $policy ) {
	return 'extrachill_newsletter_delegated_source_' . extrachill_newsletter_delegated_campaign_source_hash( $source, $policy );
}

/** Hash one canonical source/policy identity. */
function extrachill_newsletter_delegated_campaign_source_hash( array $source, $policy ) {
	return hash( 'sha256', $source['site_id'] . ':' . $source['post_id'] . ':' . $policy );
}

/** Persist the source identity index before any external effect. */
function extrachill_newsletter_bind_delegated_campaign_source( $post_id, array $source, $policy ) {
	if ( ! is_int( $post_id ) || $post_id <= 0 || (string) get_post_meta( $post_id, '_extrachill_newsletter_delegated_campaign_identity', true ) !== extrachill_newsletter_delegated_campaign_source_hash( $source, $policy ) ) {
		return false;
	}
	$receipt     = array(
		'newsletter_post_id' => $post_id,
		'source'             => $source,
		'policy'             => $policy,
	);
	$post_option = 'extrachill_newsletter_delegated_post_' . get_current_blog_id() . '_' . $post_id;
	add_site_option( $post_option, $receipt );
	if ( get_site_option( $post_option, null ) !== $receipt ) {
		return false;
	}

	$option = extrachill_newsletter_delegated_campaign_source_option( $source, $policy );
	add_site_option( $option, $receipt );

	return get_site_option( $option, null ) === $receipt;
}

/** Read the immutable source receipt attached to one Newsletter record. */
function extrachill_newsletter_get_delegated_campaign_post_source( $post_id ) {
	if ( ! is_int( $post_id ) || $post_id <= 0 ) {
		return null;
	}
	$receipt = get_site_option( 'extrachill_newsletter_delegated_post_' . get_current_blog_id() . '_' . $post_id, null );
	return is_array( $receipt ) ? $receipt : null;
}

/** Claim one Newsletter record's external effect for an operation. */
function extrachill_newsletter_claim_delegated_campaign_effect( $post_id, $operation_ref ) {
	if ( ! is_int( $post_id ) || $post_id <= 0 || ! is_string( $operation_ref ) || ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return false;
	}

	$receipt = array(
		'newsletter_post_id' => $post_id,
		'operation_ref'      => $operation_ref,
		'state'              => 'claimed',
	);
	$option  = 'extrachill_newsletter_delegated_effect_' . get_current_blog_id() . '_' . $post_id;
	add_site_option( $option, $receipt );
	if ( get_site_option( $option, null ) !== $receipt ) {
		return false;
	}

	update_post_meta( $post_id, '_extrachill_newsletter_delegated_campaign_operation_ref', $operation_ref );
	return true;
}

/** Advance the durable effect state without weakening terminal fences. */
function extrachill_newsletter_record_delegated_campaign_effect_state( $post_id, $operation_ref, $state ) {
	if ( ! is_int( $post_id ) || $post_id <= 0 || ! in_array( $state, array( 'creating', 'indeterminate', 'completed' ), true ) ) {
		return false;
	}
	$option  = 'extrachill_newsletter_delegated_effect_' . get_current_blog_id() . '_' . $post_id;
	$receipt = get_site_option( $option, null );
	if ( ! is_array( $receipt ) || ( $receipt['operation_ref'] ?? null ) !== $operation_ref || ( $receipt['newsletter_post_id'] ?? null ) !== $post_id ) {
		return false;
	}
	$current = $receipt['state'] ?? '';
	if ( in_array( $current, array( 'indeterminate', 'completed' ), true ) ) {
		return $current === $state;
	}
	if ( 'creating' !== $state && 'creating' !== $current ) {
		return false;
	}
	$receipt['state'] = $state;
	update_site_option( $option, $receipt );
	return get_site_option( $option, null ) === $receipt;
}

/** Read one exact durable effect receipt. */
function extrachill_newsletter_get_delegated_campaign_effect( $post_id ) {
	if ( ! is_int( $post_id ) || $post_id <= 0 ) {
		return null;
	}
	$receipt = get_site_option( 'extrachill_newsletter_delegated_effect_' . get_current_blog_id() . '_' . $post_id, null );
	return is_array( $receipt ) && ( $receipt['newsletter_post_id'] ?? null ) === $post_id ? $receipt : null;
}

/** Read the immutable operation that owns one Newsletter record's effect. */
function extrachill_newsletter_get_delegated_campaign_effect_owner( $post_id ) {
	$receipt = extrachill_newsletter_get_delegated_campaign_effect( $post_id );
	if ( ! is_array( $receipt ) ) {
		return '';
	}
	$operation_ref = $receipt['operation_ref'] ?? '';
	return is_string( $operation_ref ) && preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ? $operation_ref : '';
}

/** Read an exact source identity index without depending on mutable post fields. */
function extrachill_newsletter_get_bound_delegated_campaign_source( array $source, $policy ) {
	$receipt = get_site_option( extrachill_newsletter_delegated_campaign_source_option( $source, $policy ), null );
	if ( ! is_array( $receipt ) || ( $receipt['source'] ?? null ) !== $source || ( $receipt['policy'] ?? null ) !== $policy ) {
		return 0;
	}

	$post_id = $receipt['newsletter_post_id'] ?? 0;
	if ( ! is_int( $post_id ) || $post_id <= 0 ) {
		return 0;
	}
	$post = get_post( $post_id );
	return $post && 'newsletter' === $post->post_type ? $post_id : 0;
}

/** Bind one delegated operation to its exact Newsletter-owned record. */
function extrachill_newsletter_bind_delegated_campaign_record( $operation_ref, $post_id, array $input ) {
	if ( ! is_string( $operation_ref ) || ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) || ! is_int( $post_id ) || $post_id <= 0 ) {
		return false;
	}

	$receipt = array(
		'operation_ref'      => $operation_ref,
		'newsletter_post_id' => $post_id,
		'source'             => $input['source'],
		'policy'             => $input['policy'],
	);
	$option  = 'extrachill_newsletter_delegated_record_' . substr( $operation_ref, 4 );
	add_site_option( $option, $receipt );

	return get_site_option( $option, null ) === $receipt;
}

/** Read one operation-bound record after verifying its durable source identity. */
function extrachill_newsletter_get_operation_campaign_record( $operation_ref, array $input ) {
	if ( ! is_string( $operation_ref ) || ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return null;
	}

	$receipt = get_site_option( 'extrachill_newsletter_delegated_record_' . substr( $operation_ref, 4 ), null );
	if ( ! is_array( $receipt ) || ( $receipt['operation_ref'] ?? null ) !== $operation_ref || ( $receipt['source'] ?? null ) !== ( $input['source'] ?? null ) || ( $receipt['policy'] ?? null ) !== ( $input['policy'] ?? null ) ) {
		return null;
	}

	$post_id            = $receipt['newsletter_post_id'] ?? 0;
	$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'newsletter' ) ) : 0;
	if ( ! is_int( $post_id ) || $post_id <= 0 || ! $newsletter_blog_id ) {
		return null;
	}

	switch_to_blog( $newsletter_blog_id );
	try {
		$post = get_post( $post_id );
		if ( ! $post || 'newsletter' !== $post->post_type ) {
			return null;
		}
		$post_source = extrachill_newsletter_get_delegated_campaign_post_source( $post_id );
		if ( ! is_array( $post_source ) || ( $post_source['newsletter_post_id'] ?? null ) !== $post_id || ( $post_source['source'] ?? null ) !== $input['source'] || ( $post_source['policy'] ?? null ) !== $input['policy'] ) {
			return null;
		}

		$campaign_id          = get_post_meta( $post_id, '_sendy_campaign_id', true );
		$effect               = extrachill_newsletter_get_delegated_campaign_effect( $post_id );
		$effect_operation_ref = is_array( $effect ) ? (string) ( $effect['operation_ref'] ?? '' ) : '';
		if ( $operation_ref !== $effect_operation_ref ) {
			$campaign_id = null;
		}
		$validated = extrachill_newsletter_sanitize_delegated_campaign_result(
			array(
				'schema'     => 'extrachill-newsletter.delegated-campaign-result.v1',
				'status'     => 'executed',
				'record'     => array(
					'newsletter_post_id' => $post_id,
					'campaign_id'        => $campaign_id,
				),
				'error_code' => null,
			)
		);
		return array(
			'newsletter_post_id' => $post_id,
			'campaign_id'        => $validated['record']['campaign_id'] ?? null,
			'state'              => is_array( $effect ) ? (string) ( $effect['state'] ?? '' ) : (string) get_post_meta( $post_id, '_extrachill_newsletter_delegated_campaign_state', true ),
			'owns_effect'        => $operation_ref === $effect_operation_ref,
		);
	} finally {
		restore_current_blog();
	}
}

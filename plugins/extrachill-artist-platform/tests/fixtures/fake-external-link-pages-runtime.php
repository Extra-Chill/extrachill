<?php

if ( empty( $GLOBALS['smoke']['omit_api_version'] ) ) {
	define( 'EC_LINK_PAGES_RUNTIME_API_VERSION', $GLOBALS['smoke']['api_version'] ?? '1' );
}
define( 'EC_LINK_PAGE_POST_TYPE', $GLOBALS['smoke']['post_type_constant'] ?? 'artist_link_page' );
define( 'EC_LINK_PAGE_OWNER_META_KEY', $GLOBALS['smoke']['owner_meta_constant'] ?? '_ec_link_page_owner_reference' );

function ec_link_page_owner_compatibility_registry() {
	return (object) array();
}

function ec_register_link_page_owner_compatibility_provider( $name, $callback, $priority = 10 ) {
	if ( isset( $GLOBALS['smoke']['owner_providers'][ $name ] ) ) {
		return new WP_Error( 'duplicate_link_page_owner_provider', 'Duplicate provider.' );
	}
	$GLOBALS['smoke']['owner_providers'][ $name ] = $callback;
	return true;
}

function ec_register_link_page_operation_provider( $name, $callback, $priority = 10 ) {
	if ( isset( $GLOBALS['smoke']['operation_providers'][ $name ] ) ) {
		return new WP_Error( 'duplicate_link_page_operation_provider', 'Duplicate provider.' );
	}
	$GLOBALS['smoke']['operation_providers'][ $name ] = $callback;
	return true;
}

function ec_format_link_page_owner_reference( $owner ) {
	return sprintf( '%s:%d:%s:%d', $owner['kind'], $owner['blog_id'], $owner['subtype'], $owner['object_id'] );
}

function ec_parse_link_page_owner_reference( $reference ) {
	list( $kind, $blog_id, $subtype, $object_id ) = explode( ':', $reference );
	return array(
		'kind'      => $kind,
		'blog_id'   => (int) $blog_id,
		'subtype'   => $subtype,
		'object_id' => (int) $object_id,
		'reference' => $reference,
	);
}

function ec_normalize_link_page_owner_reference( $owner ) {
	return is_array( $owner ) ? ec_format_link_page_owner_reference( $owner ) : $owner;
}

function ec_get_stored_link_page_owner_references( $link_page_id ) {
	return array();
}

function ec_validate_link_page_owner_compatibility_claim( $claim, $operation, $context ) {
	return $claim;
}

function ec_restore_link_page_owner_provider_context( $blog_id, $stack, $switched ) {
	return true;
}

function ec_invoke_link_page_owner_compatibility_provider( $provider, $operation, $context ) {
	return call_user_func( $provider['callback'], $operation, $context );
}

function ec_collect_raw_link_page_owner_compatibility_claims( $operation, $context ) {
	return array();
}

function ec_reconcile_link_page_owner_candidate( $link_page_id, $owner_reference ) {
	return true;
}

function ec_collect_link_page_owner_compatibility_claims( $operation, $context ) {
	return array();
}

function ec_get_link_page_owner( $link_page_id ) {
	$provider = $GLOBALS['smoke']['owner_providers']['artist-platform'];
	$claims   = $provider( 'page_owner', array( 'link_page_id' => $link_page_id ) );
	return ec_parse_link_page_owner_reference( $claims[0]['owner_reference'] );
}

function ec_get_link_page_id_for_owner( $owner, $allowed_link_pages = array() ) {
	return 40;
}

function ec_validate_link_page_owner_candidate_ids( $candidate_ids ) {
	return $candidate_ids;
}

function ec_assign_link_page_owner( $link_page_id, $owner, $replace_link_page_id = 0 ) {
	return true;
}

function ec_compensate_link_page_owner_assignment( $link_page_id, $reference, $owner_meta_id, $error ) {
	return $error;
}

function ec_halt_link_page_owner_backfill( $result, $link_page_id, $error_code, $offset ) {
	return $result;
}

function ec_backfill_link_page_owner_references( $limit = 100, $offset = 0 ) {
	return array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'next_offset' => $offset );
}

function ec_link_page_operation_provider_registry() {
	return (object) array();
}

function ec_resolve_link_page_operation_target( $target ) {
	return array( 'link_page_id' => 40, 'owner' => ec_get_link_page_owner( 40 ), 'owner_reference' => 'post:4:artist_profile:20' );
}

function ec_invoke_link_page_operation_callback( $callback, $arguments ) {
	return call_user_func_array( $callback, $arguments );
}

function ec_get_link_page_operation_provider( $resolved ) {
	return array();
}

function ec_prepare_link_page_operation( $target, $operation ) {
	return array();
}

function ec_read_link_page( $target ) {
	return array();
}

if ( ! empty( $GLOBALS['smoke']['wrong_save_arity'] ) ) {
	function ec_save_link_page( $target ) {
		return array();
	}
} elseif ( ! empty( $GLOBALS['smoke']['wrong_save_required_arity'] ) ) {
	function ec_save_link_page( $target, $data = array() ) {
		return $data;
	}
} else {
	function ec_save_link_page( $target, $data ) {
		return $data;
	}
}

if ( empty( $GLOBALS['smoke']['omit_readiness'] ) ) {
	if ( ! empty( $GLOBALS['smoke']['wrong_readiness_arity'] ) ) {
		function ec_link_pages_runtime_ready( $context ) {
			return true;
		}
	} else {
		function ec_link_pages_runtime_ready() {
			return $GLOBALS['smoke']['runtime_ready'] ?? true;
		}
	}
}

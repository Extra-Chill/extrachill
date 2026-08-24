<?php
/**
 * Typed owner references for Link Pages.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Return the private compatibility registry. */
function ec_link_page_owner_compatibility_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new class() {
			private $providers = array();

			public function can_register( $name, $callback, $priority ) {
				if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) || ! is_callable( $callback ) || ! is_int( $priority ) ) {
					return new WP_Error( 'invalid_link_page_owner_provider', 'The Link Page owner compatibility provider registration is invalid.' );
				}
				return isset( $this->providers[ $name ] ) ? new WP_Error( 'duplicate_link_page_owner_provider', 'The Link Page owner compatibility provider is already registered.' ) : true;
			}

			public function register( $name, $callback, $priority ) {
				$valid = $this->can_register( $name, $callback, $priority );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				$this->providers[ $name ] = array(
					'name'     => $name,
					'callback' => $callback,
					'priority' => $priority,
				);
				return true;
			}

			public function snapshot() {
				$providers = array_values( $this->providers );
				usort(
					$providers,
					static function ( $left, $right ) {
						$priority = (int) $left['priority'] <=> (int) $right['priority'];
						return 0 !== $priority ? $priority : strcmp( $left['name'], $right['name'] );
					}
				);
				return $providers;
			}
		};
	}
	return $registry;
}

/** Register an append-only owner compatibility provider. */
function ec_register_link_page_owner_compatibility_provider( $name, $callback, $priority = 10 ) {
	return ec_link_page_owner_compatibility_registry()->register( $name, $callback, $priority );
}

/** Preflight an owner provider registration without mutating the registry. */
function ec_can_register_link_page_owner_compatibility_provider( $name, $callback, $priority = 10 ) {
	return ec_link_page_owner_compatibility_registry()->can_register( $name, $callback, $priority );
}

/** Parse an opaque owner reference. */
function ec_parse_link_page_owner_reference( $reference ) {
	if ( ! is_string( $reference ) || 1 !== preg_match( '/^(post|term):([1-9][0-9]*):([a-z0-9_-]+):([1-9][0-9]*)$/', $reference, $matches ) ) {
		return new WP_Error( 'invalid_link_page_owner_reference', 'The Link Page owner reference is malformed.' );
	}
	return array(
		'kind'      => $matches[1],
		'blog_id'   => (int) $matches[2],
		'subtype'   => $matches[3],
		'object_id' => (int) $matches[4],
		'reference' => $reference,
	);
}

/** Format owner fields as an opaque reference. */
function ec_format_link_page_owner_reference( $owner ) {
	if ( ! is_array( $owner ) ) {
		return new WP_Error( 'invalid_link_page_owner', 'Link Page owner fields must be an array.' );
	}
	$reference = sprintf(
		'%s:%d:%s:%d',
		isset( $owner['kind'] ) && is_string( $owner['kind'] ) ? $owner['kind'] : '',
		isset( $owner['blog_id'] ) ? absint( $owner['blog_id'] ) : 0,
		isset( $owner['subtype'] ) && is_string( $owner['subtype'] ) ? $owner['subtype'] : '',
		isset( $owner['object_id'] ) ? absint( $owner['object_id'] ) : 0
	);
	$parsed    = ec_parse_link_page_owner_reference( $reference );
	return is_wp_error( $parsed ) ? $parsed : $reference;
}

/** Normalize and validate a reference against its WordPress object. */
function ec_normalize_link_page_owner_reference( $owner ) {
	global $wpdb;
	$reference = is_array( $owner ) ? ec_format_link_page_owner_reference( $owner ) : $owner;
	if ( is_wp_error( $reference ) ) {
		return $reference;
	}
	$parsed = ec_parse_link_page_owner_reference( $reference );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}
	$site = get_site( $parsed['blog_id'] );
	if ( ! $site || ! empty( $site->deleted ) || ! empty( $site->archived ) || ! empty( $site->spam ) ) {
		return new WP_Error( 'invalid_link_page_owner_blog', 'The Link Page owner blog is unavailable.' );
	}
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_blog_prefix' ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return new WP_Error( 'link_page_owner_validation_unavailable', 'The Link Page owner database identity could not be validated.', array( 'status' => 500 ) );
	}
	$prefix = $wpdb->get_blog_prefix( $parsed['blog_id'] );
	if ( 'post' === $parsed['kind'] ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status FROM {$prefix}posts WHERE ID = %d LIMIT 1", $parsed['object_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct canonical row identity deliberately bypasses remote plugin registration and object caches.
		if ( ! $row ) {
			return new WP_Error( 'invalid_link_page_owner_object', 'The Link Page post owner no longer exists.', array( 'status' => 404 ) );
		}
		if ( (string) $row->post_type !== $parsed['subtype'] || '' === (string) $row->post_status ) {
			return new WP_Error( 'invalid_link_page_owner_object', 'The Link Page post owner does not match its declared type.' );
		}
	} else {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT term_id, taxonomy FROM {$prefix}term_taxonomy WHERE term_id = %d AND taxonomy = %s LIMIT 1", $parsed['object_id'], $parsed['subtype'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct canonical row identity deliberately bypasses remote plugin registration and object caches.
		if ( ! $row ) {
			$other_taxonomy = $wpdb->get_row( $wpdb->prepare( "SELECT term_id, taxonomy FROM {$prefix}term_taxonomy WHERE term_id = %d LIMIT 1", $parsed['object_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Second direct identity check distinguishes a deleted term from a taxonomy mismatch.
			if ( $other_taxonomy ) {
				return new WP_Error( 'invalid_link_page_owner_object', 'The Link Page term owner does not match its declared taxonomy.' );
			}
			return new WP_Error( 'invalid_link_page_owner_object', 'The Link Page term owner no longer exists.', array( 'status' => 404 ) );
		}
	}
	return ec_format_link_page_owner_reference( $parsed );
}

/** Return every stored canonical value. */
function ec_get_stored_link_page_owner_references( $link_page_id ) {
	$values = get_post_meta( $link_page_id, EC_LINK_PAGE_OWNER_META_KEY, false );
	return array_values( is_array( $values ) ? $values : array( $values ) );
}

/** Validate one structured compatibility claim. */
function ec_validate_link_page_owner_compatibility_claim( $claim, $operation, $context ) {
	if ( ! is_array( $claim ) || ! isset( $claim['link_page_id'], $claim['owner_reference'] ) || 2 !== count( $claim ) ) {
		return new WP_Error( 'invalid_link_page_owner_claim', 'A Link Page owner compatibility provider returned a malformed claim.' );
	}
	$link_page_id = $claim['link_page_id'];
	if ( ! is_int( $link_page_id ) || $link_page_id <= 0 || EC_LINK_PAGE_POST_TYPE !== get_post_type( $link_page_id ) ) {
		return new WP_Error( 'invalid_link_page_owner_candidate', 'A Link Page owner compatibility provider returned an invalid storage candidate.' );
	}
	$reference = ec_normalize_link_page_owner_reference( $claim['owner_reference'] );
	if ( is_wp_error( $reference ) ) {
		return $reference;
	}
	if ( 'page_owner' === $operation && (int) $context['link_page_id'] !== $link_page_id ) {
		return new WP_Error( 'invalid_link_page_owner_claim', 'A compatibility provider claimed the wrong Link Page.' );
	}
	if ( 'owner_pages' === $operation && $context['owner_reference'] !== $reference ) {
		return new WP_Error( 'link_page_owner_claim_mismatch', 'A compatibility provider returned a claim for a different owner.' );
	}
	$stored = ec_get_stored_link_page_owner_references( $link_page_id );
	if ( count( $stored ) > 1 ) {
		return new WP_Error( 'duplicate_link_page_owner_references', 'A claimed Link Page has duplicate canonical owner references.' );
	}
	if ( $stored ) {
		$canonical = ec_normalize_link_page_owner_reference( $stored[0] );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}
		if ( $canonical !== $reference ) {
			return new WP_Error( 'link_page_owner_divergence', 'A compatibility claim conflicts with the canonical Link Page owner.' );
		}
	}
	return array(
		'link_page_id'    => $link_page_id,
		'owner_reference' => $reference,
	);
}

/** Restore the exact multisite context captured before provider execution. */
function ec_restore_link_page_owner_provider_context( $blog_id, $stack, $switched ) {
	$current_stack = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$attempts      = 0;
	$current_depth = count( $current_stack );
	$target_depth  = count( $stack );
	while ( $current_depth > $target_depth && $attempts < 100 ) {
		restore_current_blog();
		$current_stack = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
		$current_depth = count( $current_stack );
		++$attempts;
	}
	if ( get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
	}
	$GLOBALS['_wp_switched_stack'] = $stack;
	$GLOBALS['switched']           = $switched;
	return get_current_blog_id() === $blog_id && $GLOBALS['_wp_switched_stack'] === $stack;
}

/** Invoke one provider without allowing context leakage. */
function ec_invoke_link_page_owner_compatibility_provider( $provider, $operation, $context ) {
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	$result   = null;
	$error    = null;
	try {
		$result = call_user_func( $provider['callback'], $operation, $context );
	} catch ( Throwable $throwable ) {
		$error = new WP_Error( 'link_page_owner_provider_exception', 'A Link Page owner compatibility provider failed.' );
	} finally {
		$restored = ec_restore_link_page_owner_provider_context( $blog_id, $stack, $switched );
	}
	if ( ! $restored ) {
		return new WP_Error( 'link_page_owner_provider_context_leak', 'A Link Page owner compatibility provider did not restore the storage context.' );
	}
	return $error ? $error : $result;
}

/** Collect raw immutable claims from every provider snapshot. */
function ec_collect_raw_link_page_owner_compatibility_claims( $operation, $context ) {
	if ( ! in_array( $operation, array( 'page_owner', 'owner_pages' ), true ) || ! is_array( $context ) ) {
		return new WP_Error( 'invalid_link_page_owner_provider_context', 'The Link Page owner provider context is invalid.' );
	}
	static $active = array();
	$key           = $operation . '|' . md5( (string) wp_json_encode( $context ) );
	if ( isset( $active[ $key ] ) ) {
		return new WP_Error( 'link_page_owner_provider_reentrancy', 'A Link Page owner compatibility provider recursively requested the same ownership context.' );
	}
	$active[ $key ] = true;
	try {
		$claims = array();
		$errors = array();
		foreach ( ec_link_page_owner_compatibility_registry()->snapshot() as $provider ) {
			$result = ec_invoke_link_page_owner_compatibility_provider( $provider, $operation, $context );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result;
				continue;
			}
			if ( ! is_array( $result ) ) {
				$errors[] = new WP_Error( 'invalid_link_page_owner_provider_result', 'A Link Page owner compatibility provider returned an invalid result.' );
				continue;
			}
			foreach ( $result as $claim ) {
				$claim = ec_validate_link_page_owner_compatibility_claim( $claim, $operation, $context );
				if ( is_wp_error( $claim ) ) {
					$errors[] = $claim;
					continue;
				}
				$claim_key = $claim['link_page_id'] . '|' . $claim['owner_reference'];
				if ( isset( $claims[ $claim_key ] ) ) {
					$errors[] = new WP_Error( 'duplicate_link_page_owner_claim', 'Multiple compatibility providers returned the same owner claim.' );
					continue;
				}
				$claims[ $claim_key ] = $claim;
			}
		}
		return $errors ? $errors[0] : array_values( $claims );
	} finally {
		unset( $active[ $key ] );
	}
}

/** Reconcile one candidate against page-owner claims. */
function ec_reconcile_link_page_owner_candidate( $link_page_id, $owner_reference ) {
	$claims = ec_collect_raw_link_page_owner_compatibility_claims( 'page_owner', array( 'link_page_id' => $link_page_id ) );
	if ( is_wp_error( $claims ) ) {
		return $claims;
	}
	foreach ( $claims as $claim ) {
		if ( $claim['owner_reference'] !== $owner_reference ) {
			return new WP_Error( 'link_page_owner_divergence', 'Owner compatibility providers disagree about the Link Page owner.' );
		}
	}
	return true;
}

/** Collect claims and reconcile reverse claims. */
function ec_collect_link_page_owner_compatibility_claims( $operation, $context ) {
	$claims = ec_collect_raw_link_page_owner_compatibility_claims( $operation, $context );
	if ( is_wp_error( $claims ) || 'owner_pages' !== $operation ) {
		return $claims;
	}
	foreach ( $claims as $claim ) {
		$reconciled = ec_reconcile_link_page_owner_candidate( $claim['link_page_id'], $context['owner_reference'] );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}
	}
	return $claims;
}

/** Resolve the normalized owner fields for a Link Page. */
function ec_get_link_page_owner( $link_page_id ) {
	$storage_blog_id = function_exists( 'ec_get_link_page_storage_blog_id' ) ? ec_get_link_page_storage_blog_id() : get_current_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( $storage_blog_id && get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id ) {
				return ec_get_link_page_owner( $link_page_id );
			}
		);
	}
	$link_page_id = absint( $link_page_id );
	if ( ! $link_page_id || EC_LINK_PAGE_POST_TYPE !== get_post_type( $link_page_id ) ) {
		return new WP_Error( 'invalid_link_page', 'The Link Page does not exist.' );
	}
	$stored = ec_get_stored_link_page_owner_references( $link_page_id );
	if ( count( $stored ) > 1 ) {
		return new WP_Error( 'duplicate_link_page_owner_references', 'The Link Page has duplicate stored owner references.' );
	}
	$references = array();
	if ( $stored ) {
		$normalized = ec_normalize_link_page_owner_reference( $stored[0] );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$references[] = $normalized;
	}
	$claims = ec_collect_link_page_owner_compatibility_claims( 'page_owner', array( 'link_page_id' => $link_page_id ) );
	if ( is_wp_error( $claims ) ) {
		return $claims;
	}
	foreach ( $claims as $claim ) {
		$references[] = $claim['owner_reference'];
	}
	$references = array_values( array_unique( $references ) );
	if ( ! $references ) {
		return new WP_Error( 'link_page_owner_not_found', 'The Link Page has no owner association.' );
	}
	if ( count( $references ) > 1 ) {
		return new WP_Error( 'multiple_link_page_owner_claims', 'Multiple owners are claimed for the Link Page.' );
	}
	return ec_parse_link_page_owner_reference( $references[0] );
}

/** Find the unique Link Page assigned to an owner. */
function ec_get_link_page_id_for_owner( $owner, $allowed_link_pages = array() ) {
	$storage_blog_id = function_exists( 'ec_get_link_page_storage_blog_id' ) ? ec_get_link_page_storage_blog_id() : get_current_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( $storage_blog_id && get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $owner, $allowed_link_pages ) {
				return ec_get_link_page_id_for_owner( $owner, $allowed_link_pages );
			}
		);
	}
	$reference = ec_normalize_link_page_owner_reference( $owner );
	if ( is_wp_error( $reference ) ) {
		return $reference;
	}
	// Owner uniqueness requires an exact lookup on the canonical metadata value.
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	$link_page_ids = get_posts(
		array(
			'post_type'      => EC_LINK_PAGE_POST_TYPE,
			'post_status'    => 'any',
			'meta_key'       => EC_LINK_PAGE_OWNER_META_KEY,
			'meta_value'     => $reference,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	foreach ( $link_page_ids as $link_page_id ) {
		$reconciled = ec_reconcile_link_page_owner_candidate( (int) $link_page_id, $reference );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}
	}
	$claims = ec_collect_link_page_owner_compatibility_claims( 'owner_pages', array( 'owner_reference' => $reference ) );
	if ( is_wp_error( $claims ) ) {
		return $claims;
	}
	foreach ( $claims as $claim ) {
		$link_page_ids[] = $claim['link_page_id'];
	}
	$candidates = ec_validate_link_page_owner_candidate_ids( $link_page_ids );
	if ( is_wp_error( $candidates ) ) {
		return $candidates;
	}
	$allowed = array_values( array_filter( array_unique( array_map( 'absint', $allowed_link_pages ) ) ) );
	sort( $candidates, SORT_NUMERIC );
	sort( $allowed, SORT_NUMERIC );
	if ( count( $candidates ) > 1 && $allowed !== $candidates ) {
		return new WP_Error( 'duplicate_link_pages_for_owner', 'Multiple Link Pages resolve to the same owner.' );
	}
	return $candidates ? (int) $candidates[0] : 0;
}

/** Validate candidate IDs in the current storage context. */
function ec_validate_link_page_owner_candidate_ids( $candidate_ids ) {
	$validated = array();
	foreach ( $candidate_ids as $candidate_id ) {
		if ( ! is_int( $candidate_id ) || $candidate_id <= 0 || EC_LINK_PAGE_POST_TYPE !== get_post_type( $candidate_id ) ) {
			return new WP_Error( 'invalid_link_page_owner_candidate', 'A Link Page owner provider returned an invalid storage candidate.' );
		}
		$validated[] = $candidate_id;
	}
	return array_values( array_unique( $validated ) );
}

/** Assign the unique normalized owner of a Link Page. */
function ec_assign_link_page_owner( $link_page_id, $owner, $replace_link_page_id = 0 ) {
	$storage_blog_id = function_exists( 'ec_get_link_page_storage_blog_id' ) ? ec_get_link_page_storage_blog_id() : get_current_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( $storage_blog_id && get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $owner, $replace_link_page_id ) {
				return ec_assign_link_page_owner( $link_page_id, $owner, $replace_link_page_id );
			}
		);
	}
	$link_page_id = absint( $link_page_id );
	if ( ! $link_page_id || EC_LINK_PAGE_POST_TYPE !== get_post_type( $link_page_id ) ) {
		return new WP_Error( 'invalid_link_page', 'The Link Page does not exist.' );
	}
	$reference = ec_normalize_link_page_owner_reference( $owner );
	if ( is_wp_error( $reference ) ) {
		return $reference;
	}
	$stored = ec_get_stored_link_page_owner_references( $link_page_id );
	if ( count( $stored ) > 1 ) {
		return new WP_Error( 'duplicate_link_page_owner_references', 'The Link Page has duplicate stored owner references.' );
	}
	if ( $stored && $stored[0] !== $reference ) {
		return new WP_Error( 'link_page_owner_conflict', 'The Link Page is already assigned to another owner.' );
	}
	$allowed  = $replace_link_page_id ? array( $link_page_id, $replace_link_page_id ) : array();
	$existing = ec_get_link_page_id_for_owner( $reference, $allowed );
	if ( is_wp_error( $existing ) ) {
		return $existing;
	}
	if ( $existing && $link_page_id !== $existing && absint( $replace_link_page_id ) !== $existing ) {
		return new WP_Error( 'link_page_owner_conflict', 'The owner is already assigned to another Link Page.' );
	}
	if ( 1 === count( $stored ) && $stored[0] === $reference ) {
		return true;
	}
	$meta_id = add_post_meta( $link_page_id, EC_LINK_PAGE_OWNER_META_KEY, $reference, true );
	if ( ! $meta_id ) {
		$stored = ec_get_stored_link_page_owner_references( $link_page_id );
		if ( 1 === count( $stored ) && $reference === $stored[0] ) {
			$persisted = ec_get_link_page_id_for_owner( $reference, $allowed );
			return is_wp_error( $persisted ) ? $persisted : true;
		}
		return $stored ? new WP_Error( 'link_page_owner_conflict', 'A different owner was assigned before this Link Page could be claimed.' ) : new WP_Error( 'link_page_owner_assignment_failed', 'The Link Page owner could not be persisted.' );
	}
	$stored = ec_get_stored_link_page_owner_references( $link_page_id );
	if ( 1 !== count( $stored ) || $reference !== $stored[0] ) {
		return ec_compensate_link_page_owner_assignment( $link_page_id, $reference, $meta_id, new WP_Error( 'link_page_owner_assignment_failed', 'The Link Page owner could not be persisted.' ) );
	}
	$persisted = ec_get_link_page_id_for_owner( $reference, $allowed );
	return is_wp_error( $persisted ) ? ec_compensate_link_page_owner_assignment( $link_page_id, $reference, $meta_id, $persisted ) : true;
}

/** Remove and verify metadata written by a failed assignment. */
function ec_compensate_link_page_owner_assignment( $link_page_id, $reference, $owner_meta_id, $error ) {
	$metadata = get_metadata_by_mid( 'post', $owner_meta_id );
	if ( ! $metadata ) {
		return $error;
	}
	if ( (int) $metadata->post_id !== (int) $link_page_id || EC_LINK_PAGE_OWNER_META_KEY !== $metadata->meta_key || $reference !== $metadata->meta_value ) {
		return new WP_Error( 'link_page_owner_compensation_failed', 'The metadata created by a failed owner assignment changed before compensation. Manual reconciliation is required.', array( 'retryable' => false ) );
	}
	delete_metadata_by_mid( 'post', $owner_meta_id );
	if ( get_metadata_by_mid( 'post', $owner_meta_id ) ) {
		return new WP_Error( 'link_page_owner_compensation_failed', 'A failed owner assignment could not be compensated. Manual reconciliation is required.', array( 'retryable' => false ) );
	}
	return $error;
}

/** Return a halted backfill result. */
function ec_halt_link_page_owner_backfill( $result, $link_page_id, $error_code, $offset ) {
	$result['errors'][ (int) $link_page_id ] = $error_code;
	return array_merge( $result, array( 'next_offset' => $offset + $result['processed'] - 1 ) );
}

/** Backfill a bounded page of compatibility owner associations. */
function ec_backfill_link_page_owner_references( $limit = 100, $offset = 0 ) {
	$storage_blog_id = function_exists( 'ec_get_link_page_storage_blog_id' ) ? ec_get_link_page_storage_blog_id() : get_current_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $limit, $offset ) {
				return ec_backfill_link_page_owner_references( $limit, $offset );
			}
		);
	}
	$limit  = min( 500, max( 1, absint( $limit ) ) );
	$offset = absint( $offset );
	$ids    = get_posts(
		array(
			'post_type'      => EC_LINK_PAGE_POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	$result = array(
		'processed'   => 0,
		'updated'     => 0,
		'skipped'     => 0,
		'errors'      => array(),
		'next_offset' => $offset,
	);
	foreach ( $ids as $link_page_id ) {
		++$result['processed'];
		$stored = ec_get_stored_link_page_owner_references( $link_page_id );
		$owner  = ec_get_link_page_owner( $link_page_id );
		if ( is_wp_error( $owner ) ) {
			return ec_halt_link_page_owner_backfill( $result, $link_page_id, $owner->get_error_code(), $offset );
		}
		if ( $stored ) {
			$resolved = ec_get_link_page_id_for_owner( $owner );
			if ( is_wp_error( $resolved ) || (int) $link_page_id !== (int) $resolved ) {
				$code = is_wp_error( $resolved ) ? $resolved->get_error_code() : 'link_page_owner_resolution_failed';
				return ec_halt_link_page_owner_backfill( $result, $link_page_id, $code, $offset );
			}
			++$result['skipped'];
			continue;
		}
		$assigned = ec_assign_link_page_owner( $link_page_id, $owner );
		if ( is_wp_error( $assigned ) ) {
			return ec_halt_link_page_owner_backfill( $result, $link_page_id, $assigned->get_error_code(), $offset );
		}
		++$result['updated'];
	}
	$result['next_offset'] = $offset + $result['processed'];
	return $result;
}

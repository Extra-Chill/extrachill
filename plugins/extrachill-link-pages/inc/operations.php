<?php
/**
 * Owner-neutral Link Page operations.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Return the private operation-provider registry. */
function ec_link_page_operation_provider_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new class() {
			private $providers = array();

			public function register( $name, $callback, $priority ) {
				if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) || ! is_callable( $callback ) || ! is_int( $priority ) ) {
					return new WP_Error( 'invalid_link_page_operation_provider', 'The Link Page operation provider registration is invalid.' );
				}
				if ( isset( $this->providers[ $name ] ) ) {
					return new WP_Error( 'duplicate_link_page_operation_provider', 'The Link Page operation provider is already registered.' );
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

/** Register an append-only operation provider. */
function ec_register_link_page_operation_provider( $name, $callback, $priority = 10 ) {
	return ec_link_page_operation_provider_registry()->register( $name, $callback, $priority );
}

/** Resolve a page ID, owner reference, or exact pair to one target. */
function ec_resolve_link_page_operation_target( $target ) {
	$link_page_id  = 0;
	$reference     = '';
	$has_page      = false;
	$has_reference = false;
	if ( is_int( $target ) ) {
		$link_page_id = $target;
		$has_page     = true;
	} elseif ( is_string( $target ) ) {
		$reference     = $target;
		$has_reference = true;
	} elseif ( is_array( $target ) ) {
		if ( array_diff( array_keys( $target ), array( 'link_page_id', 'owner_reference' ) ) ) {
			return new WP_Error( 'invalid_link_page_operation_target', 'The Link Page operation target is malformed.' );
		}
		if ( array_key_exists( 'link_page_id', $target ) ) {
			if ( ! is_int( $target['link_page_id'] ) ) {
				return new WP_Error( 'invalid_link_page_operation_target', 'The Link Page operation target is malformed.' );
			}
			$link_page_id = $target['link_page_id'];
			$has_page     = true;
		}
		if ( array_key_exists( 'owner_reference', $target ) ) {
			if ( ! is_string( $target['owner_reference'] ) ) {
				return new WP_Error( 'invalid_link_page_operation_target', 'The Link Page operation target is malformed.' );
			}
			$reference     = $target['owner_reference'];
			$has_reference = true;
		}
	} else {
		return new WP_Error( 'invalid_link_page_operation_target', 'The Link Page operation target is malformed.' );
	}
	if ( ! $has_page && ! $has_reference ) {
		return new WP_Error( 'invalid_link_page_operation_target', 'The Link Page operation target is empty.' );
	}
	if ( $has_reference ) {
		$reference = ec_normalize_link_page_owner_reference( $reference );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		$resolved_id = ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $resolved_id ) ) {
			return $resolved_id;
		}
		if ( ! $resolved_id ) {
			return new WP_Error( 'link_page_not_found', 'No Link Page exists for the owner.' );
		}
		if ( $has_page && $resolved_id !== $link_page_id ) {
			return new WP_Error( 'link_page_operation_target_divergence', 'The Link Page ID and owner reference do not resolve to the same record.' );
		}
		$link_page_id = $resolved_id;
	}
	$owner = ec_get_link_page_owner( $link_page_id );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}
	if ( $has_reference && $owner['reference'] !== $reference ) {
		return new WP_Error( 'link_page_operation_target_divergence', 'The Link Page owner does not match the requested owner.' );
	}
	$resolved_id = ec_get_link_page_id_for_owner( $owner['reference'] );
	if ( is_wp_error( $resolved_id ) ) {
		return $resolved_id;
	}
	if ( $resolved_id !== $link_page_id ) {
		return new WP_Error( 'link_page_operation_target_divergence', 'The Link Page owner does not resolve back to the requested record.' );
	}
	return array(
		'link_page_id'    => $link_page_id,
		'owner'           => $owner,
		'owner_reference' => $owner['reference'],
	);
}

/** Invoke a callback without allowing context leakage. */
function ec_invoke_link_page_operation_callback( $callback, $arguments ) {
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	$result   = null;
	$error    = null;
	try {
		$result = call_user_func_array( $callback, $arguments );
	} catch ( Throwable $throwable ) {
		$error = new WP_Error( 'link_page_operation_provider_exception', 'A Link Page operation provider failed.' );
	} finally {
		$restored = ec_restore_link_page_owner_provider_context( $blog_id, $stack, $switched );
	}
	if ( ! $restored ) {
		return new WP_Error( 'link_page_operation_provider_context_leak', 'A Link Page operation provider did not restore the storage context.' );
	}
	return $error ? $error : $result;
}

/** Resolve exactly one provider descriptor. */
function ec_get_link_page_operation_provider( $resolved ) {
	$matches = array();
	foreach ( ec_link_page_operation_provider_registry()->snapshot() as $provider ) {
		$descriptor = ec_invoke_link_page_operation_callback( $provider['callback'], array( $resolved ) );
		if ( is_wp_error( $descriptor ) ) {
			return $descriptor;
		}
		if ( null === $descriptor ) {
			continue;
		}
		if ( ! is_array( $descriptor ) || 3 !== count( $descriptor ) || array_diff( array( 'authorize', 'read', 'save' ), array_keys( $descriptor ) ) ) {
			return new WP_Error( 'invalid_link_page_operation_provider_result', 'A Link Page operation provider returned an invalid descriptor.' );
		}
		foreach ( $descriptor as $callback ) {
			if ( ! is_callable( $callback ) ) {
				return new WP_Error( 'invalid_link_page_operation_provider_result', 'A Link Page operation provider returned an invalid descriptor.' );
			}
		}
		$matches[] = array(
			'authorize' => $descriptor['authorize'],
			'read'      => $descriptor['read'],
			'save'      => $descriptor['save'],
		);
	}
	if ( ! $matches ) {
		return new WP_Error( 'link_page_operation_provider_missing', 'No operation provider is available for the Link Page owner.' );
	}
	if ( count( $matches ) > 1 ) {
		return new WP_Error( 'multiple_link_page_operation_providers', 'Multiple operation providers claimed the Link Page owner.' );
	}
	return $matches[0];
}

/** Resolve and authorize one operation. */
function ec_prepare_link_page_operation( $target, $operation ) {
	$resolved = ec_resolve_link_page_operation_target( $target );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	$provider = ec_get_link_page_operation_provider( $resolved );
	if ( is_wp_error( $provider ) ) {
		return $provider;
	}
	$authorized = ec_invoke_link_page_operation_callback( $provider['authorize'], array( $resolved, $operation ) );
	if ( is_wp_error( $authorized ) ) {
		return $authorized;
	}
	if ( true !== $authorized ) {
		return new WP_Error( 'link_page_operation_forbidden', 'You are not allowed to manage this Link Page.' );
	}
	$confirmed = ec_resolve_link_page_operation_target(
		array(
			'link_page_id'    => $resolved['link_page_id'],
			'owner_reference' => $resolved['owner_reference'],
		)
	);
	if ( is_wp_error( $confirmed ) ) {
		return $confirmed;
	}
	if ( $confirmed !== $resolved ) {
		return new WP_Error( 'link_page_operation_target_changed', 'The Link Page owner changed during authorization.' );
	}
	return array(
		'resolved' => $resolved,
		'provider' => $provider,
	);
}

/** Read Link Page data through its owner provider. */
function ec_read_link_page( $target ) {
	$prepared = ec_prepare_link_page_operation( $target, 'read' );
	if ( is_wp_error( $prepared ) ) {
		return $prepared;
	}
	$result = ec_invoke_link_page_operation_callback( $prepared['provider']['read'], array( $prepared['resolved'] ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return is_array( $result ) ? $result : new WP_Error( 'invalid_link_page_operation_result', 'The Link Page read operation returned invalid data.' );
}

/** Save Link Page data through its owner provider. */
function ec_save_link_page( $target, $data ) {
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'invalid_link_page_operation_data', 'The Link Page save data must be an array.' );
	}
	$prepared = ec_prepare_link_page_operation( $target, 'save' );
	if ( is_wp_error( $prepared ) ) {
		return $prepared;
	}
	$result = ec_invoke_link_page_operation_callback( $prepared['provider']['save'], array( $prepared['resolved'], $data ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return is_array( $result ) ? $result : new WP_Error( 'invalid_link_page_operation_result', 'The Link Page save operation returned invalid data.' );
}

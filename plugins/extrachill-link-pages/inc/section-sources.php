<?php
/**
 * Push-based section source registry and managed-section write operations.
 *
 * A section source is registered by any owning plugin and resolves links at
 * write time only. Rendering never invokes a source and never depends on one
 * being loaded (extrachill-link-pages#37).
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Return the append-only section source registry. */
function ec_link_page_section_source_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new class() {
			private $sources = array();

			public function can_register( $id, $args ) {
				if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $id ) || 'manual' === $id ) {
					return new WP_Error( 'invalid_link_page_section_source', 'The Link Page section source id is invalid.' );
				}
				if ( ! is_array( $args ) || ! isset( $args['label'], $args['resolve'] ) || ! is_string( $args['label'] ) || '' === $args['label'] || ! is_callable( $args['resolve'] ) || ( isset( $args['config_schema'] ) && ! is_array( $args['config_schema'] ) ) ) {
					return new WP_Error( 'invalid_link_page_section_source', 'The Link Page section source registration is invalid.' );
				}
				return isset( $this->sources[ $id ] ) ? new WP_Error( 'duplicate_link_page_section_source', 'The Link Page section source is already registered.' ) : true;
			}

			public function register( $id, $args ) {
				$valid = $this->can_register( $id, $args );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				$this->sources[ $id ] = array(
					'id'            => $id,
					'label'         => $args['label'],
					'config_schema' => is_array( $args['config_schema'] ?? null ) ? $args['config_schema'] : array(),
					'resolve'       => $args['resolve'],
				);
				return true;
			}

			public function get( $id ) {
				return $this->sources[ $id ] ?? null;
			}

			public function snapshot() {
				$sources = array_values( $this->sources );
				usort(
					$sources,
					static function ( $left, $right ) {
						return strcmp( $left['id'], $right['id'] );
					}
				);
				return $sources;
			}
		};
	}
	return $registry;
}

/** Register a push-based section source. */
function ec_register_link_page_section_source( $id, $args ) {
	return ec_link_page_section_source_registry()->register( $id, $args );
}

/** Preflight a section source registration without mutating the registry. */
function ec_can_register_link_page_section_source( $id, $args ) {
	return ec_link_page_section_source_registry()->can_register( $id, $args );
}

/** Validate and sanitize one section's source configuration against its registered schema. */
function ec_sanitize_link_page_section_source_config( $type, $raw_config ) {
	if ( ! is_array( $raw_config ) ) {
		return new WP_Error( 'invalid_link_page_section_source_config', 'A Link Page section source configuration must be an array.' );
	}
	$source = ec_link_page_section_source_registry()->get( $type );
	$schema = is_array( $source['config_schema'] ?? null ) ? $source['config_schema'] : array();
	if ( ! $schema ) {
		// Unregistered (not yet loaded) or schema-less source: accept
		// bounded scalar values only, so rendering an unknown source's
		// stored config never depends on that source being loaded.
		$sanitized = array();
		foreach ( $raw_config as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_]+$/', $key ) || ! is_scalar( $value ) ) {
				return new WP_Error( 'invalid_link_page_section_source_config', 'A Link Page section source configuration value is invalid.' );
			}
			$sanitized[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}
		return $sanitized;
	}
	$sanitized = array();
	foreach ( $schema as $field => $rules ) {
		$rules = is_array( $rules ) ? $rules : array();
		if ( ! array_key_exists( $field, $raw_config ) ) {
			if ( ! empty( $rules['required'] ) ) {
				return new WP_Error( 'invalid_link_page_section_source_config', 'A required Link Page section source configuration field is missing.', array( 'field' => $field ) );
			}
			continue;
		}
		$value      = $raw_config[ $field ];
		$field_type = $rules['type'] ?? 'string';
		switch ( $field_type ) {
			case 'int':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error( 'invalid_link_page_section_source_config', 'A Link Page section source configuration field must be numeric.', array( 'field' => $field ) );
				}
				$sanitized[ $field ] = (int) $value;
				break;
			case 'bool':
				$sanitized[ $field ] = (bool) $value;
				break;
			case 'url':
				if ( ! is_scalar( $value ) ) {
					return new WP_Error( 'invalid_link_page_section_source_config', 'A Link Page section source configuration field must be a URL.', array( 'field' => $field ) );
				}
				$url = esc_url_raw( (string) $value, array( 'http', 'https' ) );
				if ( '' !== (string) $value && '' === $url ) {
					return new WP_Error( 'invalid_link_page_section_source_config', 'A Link Page section source configuration field must use HTTP or HTTPS.', array( 'field' => $field ) );
				}
				$sanitized[ $field ] = $url;
				break;
			case 'enum':
				$options = is_array( $rules['options'] ?? null ) ? $rules['options'] : array();
				if ( ! in_array( $value, $options, true ) ) {
					return new WP_Error( 'invalid_link_page_section_source_config', 'A Link Page section source configuration field is not a supported value.', array( 'field' => $field ) );
				}
				$sanitized[ $field ] = $value;
				break;
			case 'string':
			default:
				if ( ! is_scalar( $value ) ) {
					return new WP_Error( 'invalid_link_page_section_source_config', 'A Link Page section source configuration field must be a string.', array( 'field' => $field ) );
				}
				$sanitized[ $field ] = sanitize_text_field( (string) $value );
				break;
		}
	}
	$unknown = array_diff( array_keys( $raw_config ), array_keys( $schema ) );
	if ( $unknown ) {
		return new WP_Error( 'invalid_link_page_section_source_config', 'A Link Page section source configuration field is not recognized.', array( 'fields' => array_values( $unknown ) ) );
	}
	return $sanitized;
}

/** Invoke a section source resolve callback without allowing context leakage. */
function ec_invoke_link_page_section_source_callback( $callback, $arguments ) {
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	$result   = null;
	$error    = null;
	try {
		$result = call_user_func_array( $callback, $arguments );
	} catch ( Throwable $throwable ) {
		$error = new WP_Error( 'link_page_section_source_exception', 'A Link Page section source failed.' );
	}
	$restored = ec_restore_link_page_owner_provider_context( $blog_id, $stack, $switched );
	if ( ! $restored ) {
		return new WP_Error( 'link_page_section_source_context_leak', 'A Link Page section source did not restore the storage context.' );
	}
	return $error ? $error : $result;
}

/** Sanitize the plain links a section source resolved, allocating fresh persistent IDs. */
function ec_sanitize_link_page_section_source_links( $links, $link_page_id ) {
	if ( ! is_array( $links ) ) {
		return new WP_Error( 'invalid_link_page_section_source_result', 'A Link Page section source must resolve to an array of links.' );
	}
	$sanitized = array();
	foreach ( $links as $link ) {
		if ( ! is_array( $link ) ) {
			return new WP_Error( 'invalid_link_page_section_source_result', 'A Link Page section source link is malformed.' );
		}
		$link_id = ec_link_page_next_element_id( $link_page_id, 'link' );
		if ( is_wp_error( $link_id ) ) {
			return $link_id;
		}
		$item = array(
			'id'        => $link_id,
			'link_text' => isset( $link['link_text'] ) ? sanitize_text_field( (string) $link['link_text'] ) : '',
			'link_url'  => isset( $link['link_url'] ) ? esc_url_raw( (string) $link['link_url'] ) : '',
		);
		if ( ! empty( $link['expires_at'] ) ) {
			$item['expires_at'] = sanitize_text_field( (string) $link['expires_at'] );
		}
		$sanitized[] = $item;
	}
	return $sanitized;
}

/** Resolve one managed section's source and write its links through the locked save path. */
function ec_refresh_link_page_section( $link_page_id, $section_id ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $section_id ) {
				return ec_refresh_link_page_section( $link_page_id, $section_id );
			}
		);
	}
	$link_page_id = absint( $link_page_id );
	$section_id   = is_string( $section_id ) ? sanitize_text_field( $section_id ) : '';
	if ( ! $link_page_id || ec_link_page_post_type() !== get_post_type( $link_page_id ) || '' === $section_id ) {
		return new WP_Error( 'invalid_link_page_section_refresh', 'The Link Page section refresh request is invalid.' );
	}
	return ec_with_link_page_lock_scope(
		$link_page_id,
		static function () use ( $link_page_id, $section_id ) {
			return ec_refresh_link_page_section_locked( $link_page_id, $section_id );
		}
	);
}

/** Resolve and persist one managed section's links while the page lock is held. */
function ec_refresh_link_page_section_locked( $link_page_id, $section_id ) {
	$stored   = get_post_meta( $link_page_id, '_link_page_links', true );
	$sections = is_array( $stored ) ? $stored : array();
	$index    = null;
	foreach ( $sections as $candidate_index => $section ) {
		if ( is_array( $section ) && ( $section['id'] ?? '' ) === $section_id ) {
			$index = $candidate_index;
			break;
		}
	}
	if ( null === $index ) {
		return new WP_Error( 'link_page_section_not_found', 'The Link Page section does not exist.' );
	}
	$section = $sections[ $index ];
	$type    = isset( $section['type'] ) && '' !== $section['type'] ? (string) $section['type'] : 'manual';
	if ( 'manual' === $type ) {
		return new WP_Error( 'link_page_section_not_managed', 'Only a managed Link Page section can be refreshed.' );
	}
	$source = ec_link_page_section_source_registry()->get( $type );
	if ( ! $source ) {
		return new WP_Error( 'link_page_section_source_missing', 'No source is registered for this Link Page section type.' );
	}
	$owner = ec_get_link_page_owner( $link_page_id );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}
	$context = array(
		'link_page_id'    => $link_page_id,
		'owner_reference' => $owner['reference'],
		'owner'           => $owner,
		'section'         => $section,
	);
	$result  = ec_invoke_link_page_section_source_callback( $source['resolve'], array( $context ) );
	if ( is_wp_error( $result ) ) {
		// A failed resolve keeps the last stored links and records the
		// error; it never blanks the section (#37).
		do_action( 'ec_link_page_section_source_refresh_failed', $link_page_id, $section_id, $type, $result );
		return $result;
	}
	$links = ec_sanitize_link_page_section_source_links( $result, $link_page_id );
	if ( is_wp_error( $links ) ) {
		do_action( 'ec_link_page_section_source_refresh_failed', $link_page_id, $section_id, $type, $links );
		return $links;
	}
	$sections[ $index ]['links']        = $links;
	$sections[ $index ]['refreshed_at'] = gmdate( 'Y-m-d H:i:s' );
	if ( ! ec_write_link_page_meta( $link_page_id, '_link_page_links', $sections ) ) {
		return new WP_Error( 'link_page_section_refresh_failed', 'The Link Page section could not be refreshed.' );
	}
	do_action( 'ec_link_page_persistence_saved', $link_page_id, array( '_link_page_links' ) );
	ec_purge_link_page_after_mutation( $link_page_id );
	return ec_read_link_page_persistence( $link_page_id );
}

/** Refresh every managed section for one source, across one owner or the whole storage site. */
function ec_refresh_link_page_sections_for_source( $source_id, $owner_reference = null ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $source_id, $owner_reference ) {
				return ec_refresh_link_page_sections_for_source( $source_id, $owner_reference );
			}
		);
	}
	if ( ! is_string( $source_id ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $source_id ) ) {
		return new WP_Error( 'invalid_link_page_section_source', 'The Link Page section source id is invalid.' );
	}
	if ( null !== $owner_reference ) {
		if ( ! is_string( $owner_reference ) ) {
			return new WP_Error( 'invalid_link_page_owner_reference', 'The Link Page owner reference is invalid.' );
		}
		$resolved_id = ec_get_link_page_id_for_owner( $owner_reference );
		if ( is_wp_error( $resolved_id ) ) {
			return $resolved_id;
		}
		$link_page_ids = $resolved_id ? array( $resolved_id ) : array();
	} else {
		$link_page_ids = get_posts(
			array(
				'post_type'      => ec_link_page_post_type(),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}
	$result = array(
		'processed' => 0,
		'refreshed' => 0,
		'errors'    => array(),
	);
	foreach ( $link_page_ids as $link_page_id ) {
		$stored = get_post_meta( $link_page_id, '_link_page_links', true );
		foreach ( is_array( $stored ) ? $stored : array() as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$type = isset( $section['type'] ) && '' !== $section['type'] ? (string) $section['type'] : 'manual';
			if ( $type !== $source_id ) {
				continue;
			}
			++$result['processed'];
			$refreshed = ec_refresh_link_page_section( $link_page_id, (string) ( $section['id'] ?? '' ) );
			if ( is_wp_error( $refreshed ) ) {
				$result['errors'][ $link_page_id . ':' . ( $section['id'] ?? '' ) ] = $refreshed->get_error_code();
				continue;
			}
			++$result['refreshed'];
		}
	}
	return $result;
}

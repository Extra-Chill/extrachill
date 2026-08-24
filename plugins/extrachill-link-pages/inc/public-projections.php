<?php
/**
 * Owner-neutral public projection providers.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

defined( 'EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY' ) || define( 'EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY', '_ec_link_page_public_projection_snapshot' );
defined( 'EC_LINK_PAGE_PUBLIC_SNAPSHOT_VERSION' ) || define( 'EC_LINK_PAGE_PUBLIC_SNAPSHOT_VERSION', 1 );

/** Return the append-only public projection registry. */
function ec_link_page_public_projection_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new class() {
			/**
			 * Registered public projection providers.
			 *
			 * @var array<string,array{name:string,callback:callable,priority:int}>
			 */
			private $providers = array();
			public function can_register( $name, $callback, $priority ) {
				if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) || ! is_callable( $callback ) || ! is_int( $priority ) ) {
					return new WP_Error( 'invalid_link_page_public_projection_provider', 'The Link Page public projection provider registration is invalid.' );
				}
				return isset( $this->providers[ $name ] ) ? new WP_Error( 'duplicate_link_page_public_projection_provider', 'The Link Page public projection provider is already registered.' ) : true;
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
						$order = $left['priority'] <=> $right['priority'];
						return 0 !== $order ? $order : strcmp( $left['name'], $right['name'] );
					}
				);
				return $providers;
			}
		};
	}
	return $registry;
}

/** Register one public projection provider. */
function ec_register_link_page_public_projection_provider( $name, $callback, $priority = 10 ) {
	return ec_link_page_public_projection_registry()->register( $name, $callback, $priority );
}

/** Preflight a public projection provider registration without mutation. */
function ec_can_register_link_page_public_projection_provider( $name, $callback, $priority = 10 ) {
	return ec_link_page_public_projection_registry()->can_register( $name, $callback, $priority );
}

/** Invoke public provider code without storage-context leakage. */
function ec_invoke_link_page_public_projection_callback( $callback, $arguments ) {
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	$result   = null;
	$error    = null;
	try {
		$result = call_user_func_array( $callback, $arguments );
	} catch ( Throwable $throwable ) {
		$error = new WP_Error( 'link_page_public_projection_provider_exception', 'A Link Page public projection provider failed.' );
	}
	$leaked   = get_current_blog_id() !== $blog_id || ( $GLOBALS['_wp_switched_stack'] ?? array() ) !== $stack || ! empty( $GLOBALS['switched'] ) !== $switched;
	$restored = ec_restore_link_page_owner_provider_context( $blog_id, $stack, $switched );
	if ( $leaked || ! $restored ) {
		return new WP_Error( 'link_page_public_projection_provider_context_leak', 'A Link Page public projection provider leaked its storage context.' );
	}
	return $error ? $error : $result;
}

/** Validate one provider projection. */
function ec_validate_link_page_public_projection( $projection ) {
	$allowed = array( 'display_title', 'bio', 'profile_img_url', 'social_links', 'social_renderer', 'management_url', 'body_attributes', 'seo', 'tracking_url', 'components', 'assets', 'legacy_head_arguments', 'css_vars', 'body_style' );
	if ( ! is_array( $projection ) || array_diff( array_keys( $projection ), $allowed ) || ! isset( $projection['display_title'] ) || ! is_string( $projection['display_title'] ) ) {
		return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
	}
	foreach ( array( 'bio', 'profile_img_url', 'management_url', 'tracking_url', 'body_style' ) as $key ) {
		if ( isset( $projection[ $key ] ) && ! is_string( $projection[ $key ] ) ) {
			return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
		}
	}
	foreach ( array( 'social_links', 'body_attributes', 'seo', 'components', 'css_vars' ) as $key ) {
		if ( isset( $projection[ $key ] ) && ! is_array( $projection[ $key ] ) ) {
			return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
		}
	}
	if ( isset( $projection['legacy_head_arguments'] ) && ! is_array( $projection['legacy_head_arguments'] ) ) {
		return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
	}
	foreach ( array( 'social_renderer', 'assets' ) as $key ) {
		if ( isset( $projection[ $key ] ) && ! is_callable( $projection[ $key ] ) ) {
			return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
		}
	}
	foreach ( $projection['components'] ?? array() as $slot => $callbacks ) {
		if ( ! in_array( $slot, array( 'head', 'body_start', 'before_header', 'header_actions', 'after_header', 'after_links', 'footer' ), true ) || ! is_array( $callbacks ) ) {
			return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid components.' );
		}
		foreach ( $callbacks as $callback ) {
			if ( ! is_callable( $callback ) ) {
				return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid components.' );
			}
		}
	}
	return $projection;
}

/** Sanitize one serializable public projection for durable storage. */
function ec_sanitize_link_page_public_projection_snapshot( $projection ) {
	$projection = ec_validate_link_page_public_projection( $projection );
	if ( is_wp_error( $projection ) ) {
		return $projection;
	}
	if ( ! empty( $projection['assets'] ) || ! empty( $projection['legacy_head_arguments'] ) || ! empty( $projection['body_style'] ) ) {
		return new WP_Error( 'invalid_link_page_public_snapshot', 'Stored Link Page projections cannot persist executable assets, legacy arguments, or body styles.' );
	}
	foreach ( $projection['components'] ?? array() as $callbacks ) {
		if ( ! empty( $callbacks ) ) {
			return new WP_Error( 'invalid_link_page_public_snapshot', 'Stored Link Page projections cannot persist executable components.' );
		}
	}
	if ( count( $projection['social_links'] ?? array() ) > 20 || count( $projection['body_attributes'] ?? array() ) > 20 ) {
		return new WP_Error( 'invalid_link_page_public_snapshot', 'Stored public projection collection limits were exceeded.' );
	}
	$social_links = array();
	foreach ( $projection['social_links'] ?? array() as $social ) {
		if ( ! is_array( $social ) || array_diff( array_keys( $social ), array( 'type', 'url' ) ) ) {
			return new WP_Error( 'invalid_link_page_public_snapshot', 'A stored public social link is malformed.' );
		}
		$type = sanitize_key( (string) ( $social['type'] ?? '' ) );
		$url  = esc_url_raw( (string) ( $social['url'] ?? '' ), array( 'http', 'https' ) );
		if ( ! $type || ! $url ) {
			return new WP_Error( 'invalid_link_page_public_snapshot', 'A stored public social link is invalid.' );
		}
		$social_links[] = array(
			'type' => $type,
			'url'  => $url,
		);
	}
	$attributes = array();
	foreach ( $projection['body_attributes'] ?? array() as $key => $value ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^data-[a-z0-9_-]+$/', $key ) || ! is_scalar( $value ) ) {
			return new WP_Error( 'invalid_link_page_public_snapshot', 'A stored public body attribute is invalid.' );
		}
		$attributes[ $key ] = sanitize_text_field( (string) $value );
	}
	$seo = is_array( $projection['seo'] ?? null ) ? $projection['seo'] : array();
	if ( array_diff( array_keys( $seo ), array( 'title', 'description', 'canonical', 'image', 'image_alt', 'og_type', 'schema' ) ) ) {
		return new WP_Error( 'invalid_link_page_public_snapshot', 'Stored public SEO data contains unsupported fields.' );
	}
	foreach ( array( 'title', 'description', 'image_alt', 'og_type' ) as $key ) {
		if ( isset( $seo[ $key ] ) ) {
			$seo[ $key ] = sanitize_text_field( (string) $seo[ $key ] );
		}
	}
	foreach ( array( 'canonical', 'image' ) as $key ) {
		if ( isset( $seo[ $key ] ) ) {
			$raw_url     = (string) $seo[ $key ];
			$seo[ $key ] = esc_url_raw( $raw_url, array( 'http', 'https' ) );
			if ( '' !== $raw_url && '' === $seo[ $key ] ) {
				return new WP_Error( 'invalid_link_page_public_snapshot', 'Stored public SEO URLs must use HTTP or HTTPS.' );
			}
		}
	}
	if ( isset( $seo['schema'] ) && ( ! is_array( $seo['schema'] ) || ! ec_validate_link_page_public_schema_value( $seo['schema'] ) || strlen( (string) wp_json_encode( $seo['schema'] ) ) > 32768 ) ) {
		return new WP_Error( 'invalid_link_page_public_snapshot', 'Stored public schema data is invalid or too large.' );
	}
	$css_vars = ec_sanitize_link_page_css_vars( $projection['css_vars'] ?? array() );
	if ( is_wp_error( $css_vars ) ) {
		return $css_vars;
	}
	return array(
		'display_title'   => sanitize_text_field( $projection['display_title'] ),
		'bio'             => sanitize_textarea_field( (string) ( $projection['bio'] ?? '' ) ),
		'profile_img_url' => esc_url_raw( (string) ( $projection['profile_img_url'] ?? '' ), array( 'http', 'https' ) ),
		'social_links'    => $social_links,
		'management_url'  => esc_url_raw( (string) ( $projection['management_url'] ?? '' ), array( 'http', 'https' ) ),
		'body_attributes' => $attributes,
		'seo'             => $seo,
		'tracking_url'    => esc_url_raw( (string) ( $projection['tracking_url'] ?? '' ), array( 'http', 'https' ) ),
		'components'      => array(),
		'css_vars'        => $css_vars,
	);
}

/** Validate bounded JSON-only schema values without knowing owner semantics. */
function ec_validate_link_page_public_schema_value( $value, $depth = 0 ) {
	if ( $depth > 8 ) {
		return false;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $child ) {
			if ( ! is_int( $key ) && ( strlen( $key ) > 128 || 1 !== preg_match( '//u', $key ) || preg_match( '/[\x00-\x1F\x7F]/', $key ) ) ) {
				return false;
			}
			if ( ! ec_validate_link_page_public_schema_value( $child, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}
	if ( is_string( $value ) ) {
		return strlen( $value ) <= 2048 && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value );
	}
	return null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value );
}

/** Save a versioned owner-bound public projection under the page lock. */
function ec_save_link_page_public_projection_snapshot( $link_page_id, $owner_reference, $projection ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $owner_reference, $projection ) {
				return ec_save_link_page_public_projection_snapshot( $link_page_id, $owner_reference, $projection );
			}
		);
	}
	$link_page_id  = absint( $link_page_id );
	$normalized    = ec_normalize_link_page_owner_reference( $owner_reference );
	$current_owner = $link_page_id ? ec_get_link_page_owner( $link_page_id ) : null;
	$clean         = ec_sanitize_link_page_public_projection_snapshot( $projection );
	if ( is_wp_error( $normalized ) || is_wp_error( $current_owner ) || ! $link_page_id || $current_owner['reference'] !== $normalized ) {
		return new WP_Error( 'link_page_public_snapshot_owner_mismatch', 'The stored public projection does not match the Link Page owner.' );
	}
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	return ec_with_link_page_lock_scope(
		$link_page_id,
		static function () use ( $link_page_id, $normalized, $clean ) {
			$record = array(
				'version'         => EC_LINK_PAGE_PUBLIC_SNAPSHOT_VERSION,
				'owner_reference' => $normalized,
				'owner_checksum'  => hash( 'sha256', $normalized ),
				'projection'      => $clean,
				'updated_at'      => gmdate( 'c' ),
			);
			return ec_write_link_page_meta( $link_page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY, $record ) ? $record : new WP_Error( 'link_page_public_snapshot_save_failed', 'The stored public projection could not be persisted.' );
		}
	);
}

/** Read and validate one owner-bound stored public projection. */
function ec_read_link_page_public_projection_snapshot( $link_page_id, $owner_reference = '' ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $owner_reference ) {
				return ec_read_link_page_public_projection_snapshot( $link_page_id, $owner_reference );
			}
		);
	}
	$link_page_id = absint( $link_page_id );
	$record       = get_post_meta( $link_page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY, true );
	if ( ! is_array( $record ) || ! $record ) {
		return new WP_Error( 'link_page_public_snapshot_missing', 'No stored public projection is available for this Link Page.', array( 'status' => 500 ) );
	}
	$reference = $owner_reference ? ec_normalize_link_page_owner_reference( $owner_reference ) : ( $record['owner_reference'] ?? '' );
	if ( is_wp_error( $reference ) || EC_LINK_PAGE_PUBLIC_SNAPSHOT_VERSION !== (int) ( $record['version'] ?? 0 ) || ( $record['owner_reference'] ?? '' ) !== $reference || ! hash_equals( hash( 'sha256', $reference ), (string) ( $record['owner_checksum'] ?? '' ) ) ) {
		return new WP_Error( 'link_page_public_snapshot_corrupt', 'The stored public projection owner binding is corrupt.', array( 'status' => 500 ) );
	}
	$projection = ec_sanitize_link_page_public_projection_snapshot( $record['projection'] ?? null );
	return is_wp_error( $projection ) ? new WP_Error(
		'link_page_public_snapshot_corrupt',
		'The stored public projection is corrupt.',
		array(
			'status' => 500,
			'cause'  => $projection->get_error_code(),
		)
	) : $projection;
}

/** Render stored social links without requiring an owner plugin. */
function ec_render_stored_link_page_social_links( $social_links ) {
	$output = '<nav class="extrch-link-page-socials" aria-label="Public links">';
	foreach ( is_array( $social_links ) ? $social_links : array() as $social ) {
		$output .= '<a href="' . esc_url( $social['url'] ?? '' ) . '" rel="noopener noreferrer" aria-label="' . esc_attr( ucfirst( (string) ( $social['type'] ?? 'link' ) ) ) . '">' . esc_html( ucfirst( (string) ( $social['type'] ?? 'link' ) ) ) . '</a>';
	}
	return $output . '</nav>';
}

/** Resolve exactly one owner projection. */
function ec_get_link_page_public_projection( $link_page_id, $request = array() ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $request ) {
				return ec_get_link_page_public_projection( $link_page_id, $request );
			}
		);
	}
	$owner = ec_get_link_page_owner( absint( $link_page_id ) );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}
	$context = array(
		'link_page_id'    => absint( $link_page_id ),
		'owner'           => $owner,
		'owner_reference' => $owner['reference'],
		'public_url'      => ec_get_link_page_public_url( $link_page_id ),
		'request'         => is_array( $request ) ? $request : array(),
	);
	$matches = array();
	foreach ( ec_link_page_public_projection_registry()->snapshot() as $provider ) {
		$result = ec_invoke_link_page_public_projection_callback( $provider['callback'], array( $context ) );
		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
			return new WP_Error(
				$result->get_error_code(),
				'A live public projection provider failed.',
				array(
					'status' => $status > 0 ? $status : 500,
					'cause'  => $result->get_error_code(),
				)
			);
		}
		if ( null !== $result ) {
			$result = ec_validate_link_page_public_projection( $result );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$matches[] = $result;
		}
	}
	if ( count( $matches ) > 1 ) {
		return new WP_Error( 'multiple_link_page_public_projections', 'Multiple public projection providers claimed the Link Page owner.', array( 'status' => 500 ) );
	}
	if ( ! $matches ) {
		$stored = ec_read_link_page_public_projection_snapshot( $link_page_id, $context['owner_reference'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$stored['social_renderer'] = ! empty( $stored['social_links'] ) ? 'ec_render_stored_link_page_social_links' : null;
		$matches[]                 = $stored;
	}
	$projection = array_merge(
		array(
			'bio'                   => '',
			'profile_img_url'       => '',
			'social_links'          => array(),
			'management_url'        => '',
			'body_attributes'       => array(),
			'seo'                   => array(),
			'tracking_url'          => '',
			'components'            => array(),
			'social_renderer'       => null,
			'assets'                => null,
			'legacy_head_arguments' => array( $context['owner'] ),
			'css_vars'              => array(),
			'body_style'            => '',
		),
		$matches[0]
	);
	if ( $projection['assets'] ) {
		$loaded = ec_invoke_link_page_public_projection_callback( $projection['assets'], array( $context, $projection ) );
		if ( is_wp_error( $loaded ) || false === $loaded ) {
			return is_wp_error( $loaded ) ? $loaded : new WP_Error( 'link_page_public_projection_assets_failed', 'The Link Page owner assets could not be loaded.' );
		}
	}
	$projection['_context'] = $context;
	return $projection;
}

/** Render one validated owner component slot. */
function ec_render_link_page_public_components( $projection, $slot ) {
	$output = '';
	foreach ( $projection['components'][ $slot ] ?? array() as $callback ) {
		$result = ec_invoke_link_page_public_projection_callback( $callback, array( $projection['_context'], $projection ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_string( $result ) ) {
			return new WP_Error( 'invalid_link_page_public_component_result', 'A Link Page public component returned invalid output.' );
		}
		$output .= $result;
	}
	return $output;
}

/** Execute every dynamic public callback before response output begins. */
function ec_prepare_link_page_public_render( $projection, $data ) {
	foreach ( array( 'head', 'body_start', 'before_header', 'header_actions', 'after_header', 'after_links', 'footer' ) as $slot ) {
		$output = ec_render_link_page_public_components( $projection, $slot );
		if ( is_wp_error( $output ) ) {
			return $output;
		}
		$projection['_rendered_components'][ $slot ] = $output;
	}
	foreach ( array( 'above', 'below' ) as $position ) {
		$output = '';
		if ( $projection['social_renderer'] && $projection['social_links'] && $position === $data['settings']['social_icons_position'] ) {
			$output = ec_invoke_link_page_public_projection_callback( $projection['social_renderer'], array( $projection['social_links'], $position, $projection['_context'] ) );
			if ( is_wp_error( $output ) ) {
				return $output;
			}
			if ( ! is_string( $output ) ) {
				return new WP_Error( 'invalid_link_page_public_social_result', 'A Link Page public social renderer returned invalid output.' );
			}
		}
		$projection[ '_rendered_social_' . $position ] = $output;
	}
	return $projection;
}

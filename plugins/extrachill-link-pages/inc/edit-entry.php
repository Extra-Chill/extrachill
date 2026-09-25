<?php
/**
 * Owner-neutral editing entry on the public Link Page host (/edit).
 *
 * The public host does not share the owner sites' auth cookies, so /edit
 * serves a static shell that authenticates with a bearer token from the host
 * site's handoff, fetches the editor configuration for that user, points
 * wp.apiFetch at the host API, loads the owner adapter scripts the
 * configuration lists, and mounts the existing editor. Which pages a user may
 * edit is answered by owner adapters through `ec_link_page_editor_configuration`.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Host-supplied endpoints for the /edit shell.
 *
 * @return array{configuration_url:string,handoff_url:string,login_url:string}
 */
function ec_get_link_page_edit_endpoints() {
	/**
	 * Endpoints for the /edit shell. With no configuration_url, /edit
	 * reports that editing is unavailable.
	 *
	 * @param array $endpoints `configuration_url` (GET, bearer token, returns
	 *                         the editor configuration), `handoff_url` (mints
	 *                         a token and returns it in the URL fragment),
	 *                         `login_url` (sign-in page accepting redirect_to).
	 */
	$endpoints = apply_filters( 'ec_link_page_edit_endpoints', array() );
	$endpoints = is_array( $endpoints ) ? $endpoints : array();
	$clean     = array();
	foreach ( array( 'configuration_url', 'handoff_url', 'login_url' ) as $key ) {
		$clean[ $key ] = esc_url_raw( (string) ( $endpoints[ $key ] ?? '' ), array( 'https', 'http' ) );
	}
	return $clean;
}

/** Whether the current request is the /edit shell. */
function ec_is_link_page_edit_request() {
	return ! empty( $GLOBALS['ec_link_page_edit_request'] );
}

/**
 * Build the editor configuration for the current user.
 *
 * Owner adapters answer `ec_link_page_editor_configuration`; they may list
 * `scripts` (adapter bundles to load, in order) for hosts that are not the
 * owner's own site. `apiRoot` is the REST root that serves the adapter's
 * calls: the site answering this request.
 *
 * @param mixed $input Ability input: optional `link_page_id`.
 * @return array
 */
function ec_get_link_page_editor_configuration_for_user( $input ) {
	$link_page_id  = is_array( $input ) ? absint( $input['link_page_id'] ?? 0 ) : 0;
	$configuration = apply_filters( 'ec_link_page_editor_configuration', null, array( 'link_page_id' => $link_page_id ), null );
	if ( ! is_array( $configuration ) || empty( $configuration['adapter'] ) || empty( $configuration['identities'] ) ) {
		return array( 'available' => false );
	}
	$scripts = array();
	foreach ( (array) ( $configuration['scripts'] ?? array() ) as $script ) {
		$src = is_array( $script ) ? esc_url_raw( (string) ( $script['src'] ?? '' ), array( 'https', 'http' ) ) : '';
		if ( '' !== $src ) {
			$scripts[] = array( 'src' => $src );
		}
	}
	$configuration['scripts']   = $scripts;
	$configuration['apiRoot']   = esc_url_raw( rest_url() );
	$configuration['available'] = true;
	return $configuration;
}

/** Register the editor configuration ability. */
function ec_register_link_page_editor_configuration_ability() {
	wp_register_ability(
		'extrachill/get-link-page-editor-configuration',
		array(
			'label'               => 'Get Link Page editor configuration',
			'description'         => 'Return the Link Page editor configuration (owner adapter, editable identities, adapter scripts) for the current user.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'link_page_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'execute_callback'    => 'ec_get_link_page_editor_configuration_for_user',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array( 'show_in_rest' => false ),
		)
	);
}
add_action( 'wp_abilities_api_init', 'ec_register_link_page_editor_configuration_ability' );

/** Enqueue the /edit shell assets. */
function ec_enqueue_link_page_edit_shell() {
	if ( ! ec_enqueue_link_page_editor() ) {
		return false;
	}
	wp_enqueue_script( 'wp-api-fetch' );
	$path = 'assets/js/link-page-edit-shell.js';
	$file = EXTRACHILL_LINK_PAGES_PLUGIN_DIR . $path;
	wp_enqueue_script( 'extrachill-link-page-edit-shell', plugins_url( $path, EXTRACHILL_LINK_PAGES_PLUGIN_FILE ), array( 'wp-api-fetch', 'extrachill-link-page-editor-view-script' ), file_exists( $file ) ? (string) filemtime( $file ) : EXTRACHILL_LINK_PAGES_VERSION, true );
	$endpoints = ec_get_link_page_edit_endpoints();
	wp_add_inline_script(
		'extrachill-link-page-edit-shell',
		'window.ecLinkPageEditShell = ' . wp_json_encode(
			array(
				'configurationUrl' => $endpoints['configuration_url'],
				'handoffUrl'       => $endpoints['handoff_url'],
				'loginUrl'         => $endpoints['login_url'],
				'joinUrl'          => ec_link_page_public_base_url() . 'join/',
			)
		) . ';',
		'before'
	);
	return true;
}

/**
 * Title for the /edit shell.
 *
 * @param string $title Existing title.
 * @return string
 */
function ec_link_page_edit_document_title( $title ) {
	return ec_is_link_page_edit_request() ? 'Edit your link page' : $title;
}

/**
 * Keep the /edit shell out of search results.
 *
 * @param array $robots Robots directives.
 * @return array
 */
function ec_link_page_edit_robots( $robots ) {
	if ( ec_is_link_page_edit_request() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
	}
	return $robots;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'pre_get_document_title', 'ec_link_page_edit_document_title', 20 );
	add_filter( 'wp_robots', 'ec_link_page_edit_robots' );
}

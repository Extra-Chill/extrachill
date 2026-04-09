<?php
/**
 * REST routes: /wp-json/extrachill/v1/newsletter/campaigns
 *
 * Campaign management endpoints. Wraps newsletter abilities.
 *
 * @package ExtraChill\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_newsletter_campaign_management_routes' );

function extrachill_api_register_newsletter_campaign_management_routes() {

	// GET /newsletter/campaigns — List campaigns.
	register_rest_route( 'extrachill/v1', '/newsletter/campaigns', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_newsletter_list_campaigns_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'per_page' => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 20,
			),
			'offset'   => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			),
			'status'   => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );

	// GET /newsletter/campaigns/<id> — Get campaign.
	register_rest_route( 'extrachill/v1', '/newsletter/campaigns/(?P<id>\d+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_newsletter_get_campaign_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	// DELETE /newsletter/campaigns/<id> — Delete campaign.
	register_rest_route( 'extrachill/v1', '/newsletter/campaigns/(?P<id>\d+)', array(
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'extrachill_api_newsletter_delete_campaign_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	// GET /newsletter/settings — Get settings.
	register_rest_route( 'extrachill/v1', '/newsletter/settings', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_newsletter_get_settings_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );

	// POST /newsletter/settings — Update settings.
	register_rest_route( 'extrachill/v1', '/newsletter/settings', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_newsletter_update_settings_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'sendy_api_key' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sendy_url'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'from_name'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'from_email'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			),
			'reply_to'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			),
			'brand_id'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'list_ids'      => array(
				'type'              => 'object',
			),
		),
	) );

	// GET /newsletter/subscriber-status — Check subscriber.
	register_rest_route( 'extrachill/v1', '/newsletter/subscriber-status', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_newsletter_subscriber_status_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'email'   => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			),
			'list_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );

	// POST /newsletter/sync — Bulk sync.
	register_rest_route( 'extrachill/v1', '/newsletter/sync', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_newsletter_sync_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'integration' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'emails'      => array(
				'type'              => 'array',
			),
			'since'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'dry_run'     => array(
				'type'              => 'boolean',
				'default'           => false,
			),
		),
	) );
}

// ─── Handlers ────────────────────────────────────────────────────────────────

function extrachill_api_newsletter_list_campaigns_handler( $request ) {
	$result = extrachill_newsletter_ability_list_campaigns( array(
		'per_page' => $request->get_param( 'per_page' ),
		'offset'   => $request->get_param( 'offset' ),
		'status'   => $request->get_param( 'status' ),
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_newsletter_get_campaign_handler( $request ) {
	$result = extrachill_newsletter_ability_get_campaign( array(
		'campaign_id' => $request->get_param( 'id' ),
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_newsletter_delete_campaign_handler( $request ) {
	$result = extrachill_newsletter_ability_delete_campaign( array(
		'campaign_id' => $request->get_param( 'id' ),
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_newsletter_get_settings_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/get-newsletter-settings' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_available', 'Settings ability not available.', array( 'status' => 500 ) );
	}

	$result = extrachill_newsletter_ability_get_settings( array() );

	return rest_ensure_response( $result );
}

function extrachill_api_newsletter_update_settings_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/update-newsletter-settings' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_available', 'Settings ability not available.', array( 'status' => 500 ) );
	}

	$input = array();

	$core_keys = array( 'sendy_api_key', 'sendy_url', 'from_name', 'from_email', 'reply_to', 'brand_id' );
	foreach ( $core_keys as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value ) {
			$input[ $key ] = $value;
		}
	}

	$list_ids = $request->get_param( 'list_ids' );
	if ( null !== $list_ids && is_array( $list_ids ) ) {
		$input['list_ids'] = $list_ids;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_newsletter_subscriber_status_handler( $request ) {
	$result = extrachill_newsletter_ability_subscriber_status( array(
		'email'   => $request->get_param( 'email' ),
		'list_id' => $request->get_param( 'list_id' ),
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_newsletter_sync_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/sync-subscribers' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_available', 'Sync ability not available.', array( 'status' => 500 ) );
	}

	$input = array(
		'context' => $request->get_param( 'integration' ),
	);

	$emails = $request->get_param( 'emails' );
	if ( null !== $emails ) {
		$input['emails'] = $emails;
	}

	$since = $request->get_param( 'since' );
	if ( null !== $since ) {
		$input['since'] = $since;
	}

	$dry_run = $request->get_param( 'dry_run' );
	if ( null !== $dry_run ) {
		$input['dry_run'] = (bool) $dry_run;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

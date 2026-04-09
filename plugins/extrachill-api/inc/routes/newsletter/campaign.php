<?php
/**
 * REST route: POST /wp-json/extrachill/v1/newsletter/campaign/push
 *
 * Pushes newsletter post to Sendy as email campaign.
 * Wraps the extrachill/push-campaign ability from extrachill-newsletter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_newsletter_campaign_route' );

function extrachill_api_register_newsletter_campaign_route() {
	register_rest_route( 'extrachill/v1', '/newsletter/campaign/push', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_newsletter_campaign_push_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );
}

function extrachill_api_newsletter_campaign_push_handler( $request ) {
	$post_id = $request->get_param( 'post_id' );

	$ability = wp_get_ability( 'extrachill/push-campaign' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_not_available',
			'Campaign ability not available. Ensure extrachill-newsletter plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute( array( 'post_id' => $post_id ) );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'campaign_failed',
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array(
		'success'     => $result['success'],
		'message'     => $result['message'],
		'campaign_id' => $result['campaign_id'],
	) );
}

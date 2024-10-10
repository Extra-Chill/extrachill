<?php

/**
 * WooCommerce API Manager API Key Class
 *
 * @package Update API Manager/Key Handler
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.3
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Api_Manager_theme_Colormag_Key {

	// API Key URL
	public function create_software_api_url( $args ) {

		$api_url = add_query_arg( 'wc-api', 'am-software-api', AMET()->upgrade_url );

		return $api_url . '&' . http_build_query( $args, '', '&' );
	}

	public function activate( $args ) {

		$defaults = array(
			'request' 			=> 'activation',
			'product_id' 		=> AMET()->ame_product_id,
			'instance' 			=> AMET()->ame_instance_id,
			'platform' 			=> AMET()->ame_domain,
			'software_version' 	=> AMET()->ame_software_version
			);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );

		$request = wp_remote_get( $target_url );

		//$request = wp_remote_post( AMET()->upgrade_url . 'wc-api/am-software-api/', array( 'body' => $args ) );

		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
		// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	public function deactivate( $args ) {

		$defaults = array(
			'request' 		=> 'deactivation',
			'product_id' 	=> AMET()->ame_product_id,
			'instance' 		=> AMET()->ame_instance_id,
			'platform' 		=> AMET()->ame_domain
			);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );

		$request = wp_remote_get( $target_url );

		//$request = wp_remote_post( AMET()->upgrade_url . 'wc-api/am-software-api/', array( 'body' => $args ) );

		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
		// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

	/**
	 * Checks if the software is activated or deactivated
	 * @param  array $args
	 * @return array
	 */
	public function status( $args ) {

		$defaults = array(
			'request' 		=> 'status',
			'product_id' 	=> AMET()->ame_product_id,
			'instance' 		=> AMET()->ame_instance_id,
			'platform' 		=> AMET()->ame_domain
			);

		$args = wp_parse_args( $defaults, $args );

		$target_url = self::create_software_api_url( $args );

		$request = wp_remote_get( $target_url );

		//$request = wp_remote_post( AMET()->upgrade_url . 'wc-api/am-software-api/', array( 'body' => $args ) );

		if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
		// Request failed
			return false;
		}

		$response = wp_remote_retrieve_body( $request );

		return $response;
	}

}

// Class is instantiated as an object by other classes on-demand

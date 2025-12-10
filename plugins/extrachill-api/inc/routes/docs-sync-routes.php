<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __DIR__ ) . 'controllers/class-docs-sync-controller.php';

add_action( 'rest_api_init', function() {
	register_rest_route( 'extrachill/v1', '/sync/doc', [
		'methods'             => 'POST',
		'callback'            => [ 'ExtraChill_Docs_Sync_Controller', 'sync_doc' ],
		'permission_callback' => function() {
			return current_user_can( 'edit_posts' );
		},
		'args'                => [
			'source_file'   => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'title'         => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'content'       => [
				'required' => true,
				'type'     => 'string',
			],
			'platform_slug' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'slug'          => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			],
			'filesize'      => [
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'timestamp'     => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'force'         => [
				'default' => false,
				'type'    => 'boolean',
			],
		],
	] );
} );

<?php
/**
 * Ability registration.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register all artist platform abilities.
 */
function extrachill_artist_platform_register_abilities() {

	// --- Read abilities ---

	wp_register_ability(
		'extrachill/get-artist-data',
		array(
			'label'               => __( 'Get Artist Data', 'extrachill-artist-platform' ),
			'description'         => __( 'Retrieve core artist profile data including name, bio, images, and link page ID.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ),
					),
				),
				'required'   => array( 'artist_id' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Artist profile data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_get_artist_data',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/get-link-page-data',
		array(
			'label'               => __( 'Get Link Page Data', 'extrachill-artist-platform' ),
			'description'         => __( 'Retrieve complete link page data including links, styles, settings, and socials.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ),
					),
					'link_page_id' => array(
						'type'        => 'integer',
						'description' => __( 'Link page post ID. Resolved automatically if omitted.', 'extrachill-artist-platform' ),
					),
				),
				'required'   => array( 'artist_id' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Complete link page data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_get_link_page_data',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// --- Write abilities ---

	wp_register_ability(
		'extrachill/create-artist',
		array(
			'label'               => __( 'Create Artist', 'extrachill-artist-platform' ),
			'description'         => __( 'Create a new artist profile and link the current user as a member.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => __( 'Artist name.', 'extrachill-artist-platform' ),
					),
					'bio' => array(
						'type'        => 'string',
						'description' => __( 'Artist bio (HTML allowed).', 'extrachill-artist-platform' ),
					),
					'local_city' => array(
						'type'        => 'string',
						'description' => __( 'Local city/scene.', 'extrachill-artist-platform' ),
					),
					'genre' => array(
						'type'        => 'string',
						'description' => __( 'Genre.', 'extrachill-artist-platform' ),
					),
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'User ID to link as member. Defaults to current user.', 'extrachill-artist-platform' ),
					),
				),
				'required'   => array( 'name' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Created artist profile data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_create_artist',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/update-artist',
		array(
			'label'               => __( 'Update Artist', 'extrachill-artist-platform' ),
			'description'         => __( 'Update an existing artist profile. Supports partial updates.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ),
					),
					'name' => array(
						'type'        => 'string',
						'description' => __( 'Artist name.', 'extrachill-artist-platform' ),
					),
					'bio' => array(
						'type'        => 'string',
						'description' => __( 'Artist bio (HTML allowed).', 'extrachill-artist-platform' ),
					),
					'local_city' => array(
						'type'        => 'string',
						'description' => __( 'Local city/scene.', 'extrachill-artist-platform' ),
					),
					'genre' => array(
						'type'        => 'string',
						'description' => __( 'Genre.', 'extrachill-artist-platform' ),
					),
					'profile_image_id' => array(
						'type'        => 'integer',
						'description' => __( 'Profile image attachment ID. 0 to remove.', 'extrachill-artist-platform' ),
					),
					'header_image_id' => array(
						'type'        => 'integer',
						'description' => __( 'Header image attachment ID. 0 to remove.', 'extrachill-artist-platform' ),
					),
				),
				'required'   => array( 'artist_id' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Updated artist profile data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_update_artist',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/save-link-page-links',
		array(
			'label'               => __( 'Save Link Page Links', 'extrachill-artist-platform' ),
			'description'         => __( 'Save link sections and buttons to a link page. Full replacement with ID assignment.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ),
					),
					'links' => array(
						'type'        => 'array',
						'description' => __( 'Array of link sections with nested links.', 'extrachill-artist-platform' ),
					),
				),
				'required'   => array( 'artist_id', 'links' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Updated link page data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_save_link_page_links',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/save-link-page-styles',
		array(
			'label'               => __( 'Save Link Page Styles', 'extrachill-artist-platform' ),
			'description'         => __( 'Save CSS variables for a link page. Merges with existing styles.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ),
					),
					'css_vars' => array(
						'type'        => 'object',
						'description' => __( 'CSS variables to save. Merged with existing.', 'extrachill-artist-platform' ),
					),
				),
				'required'   => array( 'artist_id', 'css_vars' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Updated link page data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_save_link_page_styles',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/save-link-page-settings',
		array(
			'label'               => __( 'Save Link Page Settings', 'extrachill-artist-platform' ),
			'description'         => __( 'Save advanced settings for a link page. Merges with existing settings.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ),
					),
					'settings' => array(
						'type'        => 'object',
						'description' => __( 'Settings to save.', 'extrachill-artist-platform' ),
					),
					'background_image_id' => array(
						'type'        => 'integer',
						'description' => __( 'Background image attachment ID. 0 to remove.', 'extrachill-artist-platform' ),
					),
					'profile_image_id' => array(
						'type'        => 'integer',
						'description' => __( 'Profile image attachment ID. 0 to remove.', 'extrachill-artist-platform' ),
					),
				),
				'required'   => array( 'artist_id' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Updated link page data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_save_link_page_settings',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/save-social-links',
		array(
			'label'               => __( 'Save Social Links', 'extrachill-artist-platform' ),
			'description'         => __( 'Save social links for an artist profile. Full replacement.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ),
					),
					'social_links' => array(
						'type'        => 'array',
						'description' => __( 'Array of social link objects with type and url.', 'extrachill-artist-platform' ),
					),
				),
				'required'   => array( 'artist_id', 'social_links' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Updated social links data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_save_social_links',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

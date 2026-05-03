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
					'bio' => array(
						'type'        => 'string',
						'description' => __( 'Short bio displayed on the public link page.', 'extrachill-artist-platform' ),
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

	// ──────────────────────────────────────────────────────────────────────
	// Artist-domain abilities (extrachill-artists category) — issue #27
	// ──────────────────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/artists-list',
		array(
			'label'               => __( 'List artists', 'extrachill-artist-platform' ),
			'description'         => __( 'Returns paginated list of published artist profiles.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array(),
				'properties'           => array(
					'page'     => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Page number.', 'extrachill-artist-platform' ) ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => __( 'Results per page.', 'extrachill-artist-platform' ) ),
					'search'   => array( 'type' => 'string', 'description' => __( 'Search by artist name.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'artists'  => array( 'type' => 'array' ),
					'total'    => array( 'type' => 'integer' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
					'pages'    => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artists_list',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-get',
		array(
			'label'               => __( 'Get artist by ID', 'extrachill-artist-platform' ),
			'description'         => __( 'Returns a single artist payload.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'                => array( 'type' => 'integer' ),
					'name'              => array( 'type' => 'string' ),
					'slug'              => array( 'type' => 'string' ),
					'bio'               => array( 'type' => 'string' ),
					'local_city'        => array( 'type' => array( 'string', 'null' ) ),
					'genre'             => array( 'type' => array( 'string', 'null' ) ),
					'profile_image_id'  => array( 'type' => array( 'integer', 'null' ) ),
					'profile_image_url' => array( 'type' => array( 'string', 'null' ) ),
					'header_image_id'   => array( 'type' => array( 'integer', 'null' ) ),
					'header_image_url'  => array( 'type' => array( 'string', 'null' ) ),
					'link_page_id'      => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_get',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-get-links',
		array(
			'label'               => __( 'Get artist link page', 'extrachill-artist-platform' ),
			'description'         => __( 'Retrieves complete link page data for an artist.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Complete link page data.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_get_links',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-update-links',
		array(
			'label'               => __( 'Update artist link page', 'extrachill-artist-platform' ),
			'description'         => __( 'Updates link page data (links, styles, settings, socials).', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'                 => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
					'links'              => array( 'type' => 'array', 'description' => __( 'Link sections with nested links.', 'extrachill-artist-platform' ) ),
					'css_vars'           => array( 'type' => 'object', 'description' => __( 'CSS variables to save.', 'extrachill-artist-platform' ) ),
					'settings'           => array( 'type' => 'object', 'description' => __( 'Advanced settings.', 'extrachill-artist-platform' ) ),
					'socials'            => array( 'type' => 'array', 'description' => __( 'Social link objects.', 'extrachill-artist-platform' ) ),
					'background_image_id' => array( 'type' => 'integer', 'description' => __( 'Background image attachment ID.', 'extrachill-artist-platform' ) ),
					'profile_image_id'   => array( 'type' => 'integer', 'description' => __( 'Profile image attachment ID.', 'extrachill-artist-platform' ) ),
					'bio'                => array( 'type' => 'string', 'description' => __( 'Short bio for the link page.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Fresh link page data after update.', 'extrachill-artist-platform' ),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_update_links',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-get-permissions',
		array(
			'label'               => __( 'Get artist permissions', 'extrachill-artist-platform' ),
			'description'         => __( 'Checks current user permissions for an artist profile.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'can_edit'   => array( 'type' => 'boolean' ),
					'manage_url' => array( 'type' => 'string' ),
					'user_id'    => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_get_permissions',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-get-roster',
		array(
			'label'               => __( 'Get artist roster', 'extrachill-artist-platform' ),
			'description'         => __( 'Lists linked members and pending invites for an artist.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'members' => array( 'type' => 'array' ),
					'invites' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_get_roster',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-list-socials',
		array(
			'label'               => __( 'List artist socials', 'extrachill-artist-platform' ),
			'description'         => __( 'Lists social links for an artist profile.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'social_links' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_list_socials',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-create-social',
		array(
			'label'               => __( 'Create artist social', 'extrachill-artist-platform' ),
			'description'         => __( 'Adds a social link to an artist profile.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'type', 'url' ),
				'properties'           => array(
					'id'   => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
					'type' => array( 'type' => 'string', 'description' => __( 'Social platform type.', 'extrachill-artist-platform' ) ),
					'url'  => array( 'type' => 'string', 'format' => 'uri', 'description' => __( 'Social link URL.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'social_links' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_create_social',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-update-social',
		array(
			'label'               => __( 'Update artist social', 'extrachill-artist-platform' ),
			'description'         => __( 'Updates a single social link on an artist profile.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'social_id' ),
				'properties'           => array(
					'id'        => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
					'social_id' => array( 'type' => 'string', 'description' => __( 'Social link ID to update.', 'extrachill-artist-platform' ) ),
					'type'      => array( 'type' => 'string', 'description' => __( 'Social platform type.', 'extrachill-artist-platform' ) ),
					'url'       => array( 'type' => 'string', 'format' => 'uri', 'description' => __( 'Social link URL.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'social_links' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_update_social',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-delete-social',
		array(
			'label'               => __( 'Delete artist social', 'extrachill-artist-platform' ),
			'description'         => __( 'Removes a social link from an artist profile.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'social_id' ),
				'properties'           => array(
					'id'        => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
					'social_id' => array( 'type' => 'string', 'description' => __( 'Social link ID to delete.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'deleted'      => array( 'type' => 'boolean' ),
					'social_id'    => array( 'type' => 'string' ),
					'social_links' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_delete_social',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-subscribe',
		array(
			'label'               => __( 'Subscribe to artist', 'extrachill-artist-platform' ),
			'description'         => __( 'Subscribes an email address to an artist for updates.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'email' ),
				'properties'           => array(
					'id'    => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
					'email' => array( 'type' => 'string', 'format' => 'email', 'description' => __( 'Subscriber email address.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_subscribe',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-list-subscribers',
		array(
			'label'               => __( 'List artist subscribers', 'extrachill-artist-platform' ),
			'description'         => __( 'Returns paginated subscriber list for an artist.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
					'page'     => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Page number.', 'extrachill-artist-platform' ) ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => __( 'Results per page.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'subscribers' => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_list_subscribers',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-export-subscribers',
		array(
			'label'               => __( 'Export artist subscribers', 'extrachill-artist-platform' ),
			'description'         => __( 'Returns all subscribers for client-side CSV generation.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'               => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
					'include_exported' => array( 'type' => 'boolean', 'description' => __( 'Include already exported subscribers.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'subscribers'  => array( 'type' => 'array' ),
					'artist_name'  => array( 'type' => 'string' ),
					'export_date'  => array( 'type' => 'string' ),
					'total'        => array( 'type' => 'integer' ),
					'marked_count' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_export_subscribers',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-get-analytics',
		array(
			'label'               => __( 'Get artist analytics', 'extrachill-artist-platform' ),
			'description'         => __( 'Returns link page analytics for an artist.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'         => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
					'date_range' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => __( 'Number of days to query.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'summary'    => array( 'type' => 'object' ),
					'chart_data' => array( 'type' => 'object' ),
					'top_links'  => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_get_analytics',
			'permission_callback' => 'extrachill_artist_platform_ability_read_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}

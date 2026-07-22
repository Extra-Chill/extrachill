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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
		'extrachill/onboard-external-artist',
		array(
			'label'               => __( 'Onboard External Artist', 'extrachill-artist-platform' ),
			'description'         => __( 'Resolve or provision a consent-aware artist onboarding offer from a generic external source.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'artist_name', 'source_type', 'source_id' ),
				'properties'           => array(
					'submitter_user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'submitter_email'   => array( 'type' => 'string', 'format' => 'email' ),
					'artist_name'       => array( 'type' => 'string', 'minLength' => 1 ),
					'artist_term_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'artist_profile_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'source_type'       => array( 'type' => 'string', 'minLength' => 1 ),
					'source_id'         => array( 'type' => 'string', 'minLength' => 1 ),
					'return_url'        => array( 'type' => 'string', 'format' => 'uri' ),
					'consent'           => array(
						'type'                 => 'object',
						'properties'           => array(
							'profile_creation'  => array( 'type' => 'boolean' ),
							'link_page'          => array( 'type' => 'boolean' ),
							'disclosure_version' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'outcome', 'user', 'artist', 'membership', 'claim', 'link_page', 'source', 'return_url', 'next_action' ),
				'properties'           => array(
					'outcome'     => array( 'type' => 'string', 'enum' => array( 'account_claim_required', 'authentication_required', 'artist_consent_required', 'membership_request_required', 'managed_artist', 'artist_created' ) ),
					'user'        => array(
						'type'                 => 'object',
						'required'             => array( 'id', 'state', 'created' ),
						'properties'           => array(
							'id'      => array( 'type' => 'integer', 'minimum' => 1 ),
							'state'   => array( 'type' => 'string', 'enum' => array( 'unclaimed', 'created', 'existing' ) ),
							'created' => array( 'type' => 'boolean' ),
						),
						'additionalProperties' => false,
					),
					'artist'      => array(
						'type'                 => 'object',
						'required'             => array( 'name', 'profile_id', 'term_id', 'state' ),
						'properties'           => array(
							'name'               => array( 'type' => 'string' ),
							'profile_id'         => array( 'type' => array( 'integer', 'null' ) ),
							'term_id'            => array( 'type' => array( 'integer', 'null' ) ),
							'state'              => array( 'type' => 'string', 'enum' => array( 'existing_profile', 'existing_canonical_identity', 'new_eligible', 'eligible_after_claim_and_consent', 'eligible_after_authentication_and_consent', 'eligible_after_consent', 'created' ) ),
							'disclosure_version' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
					'membership'  => array(
						'type'                 => 'object',
						'required'             => array( 'state' ),
						'properties'           => array( 'state' => array( 'type' => 'string', 'enum' => array( 'managed', 'request_required', 'not_applicable' ) ) ),
						'additionalProperties' => false,
					),
					'claim'       => array(
						'type'                 => 'object',
						'required'             => array( 'required', 'delivery' ),
						'properties'           => array(
							'required' => array( 'type' => 'boolean' ),
							'delivery' => array( 'type' => 'string', 'enum' => array( 'not_required', 'previously_provisioned', 'sent', 'previously_sent', 'pending', 'busy', 'failed', 'sent_unconfirmed' ) ),
						),
						'additionalProperties' => false,
					),
					'link_page'   => array(
						'type'                 => 'object',
						'required'             => array( 'state', 'id' ),
						'properties'           => array(
							'state' => array( 'type' => 'string', 'enum' => array( 'unavailable', 'offered', 'existing', 'created' ) ),
							'id'    => array( 'type' => array( 'integer', 'null' ) ),
						),
						'additionalProperties' => false,
					),
					'source'      => array(
						'type'                 => 'object',
						'required'             => array( 'type', 'id' ),
						'properties'           => array(
							'type' => array( 'type' => 'string' ),
							'id'   => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
					'return_url'  => array( 'type' => 'string' ),
					'next_action' => array( 'type' => 'string', 'enum' => array( 'none', 'claim_account', 'request_membership', 'authenticate', 'confirm_profile_creation', 'manage_artist' ) ),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_onboard_external_artist',
			'permission_callback' => 'extrachill_artist_platform_ability_external_onboarding_permission',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/artist-invitation',
		array(
			'label'               => __( 'Artist Invitation', 'extrachill-artist-platform' ),
			'description'         => __( 'Validate or accept a token-authenticated artist invitation.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artist-platform',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'artist_id', 'email', 'token' ),
				'properties'           => array(
					'artist_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'email'     => array( 'type' => 'string', 'format' => 'email' ),
					'token'     => array( 'type' => 'string', 'minLength' => 1 ),
					'user_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status'    => array( 'type' => 'string', 'enum' => array( 'valid', 'applied' ) ),
					'artist_id' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_invitation',
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
			'permission_callback' => 'extrachill_artist_platform_ability_create_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
		'extrachill/get-artist-platform-stats',
		array(
			'label'               => __( 'Get artist platform stats', 'extrachill-artist-platform' ),
			'description'         => __( 'Point-in-time platform aggregates: total published artist profiles, total published link pages, profiles created in last N days, and link pages with at least one view/click in last N days. Funnel conversion-over-time is read separately via extrachill/get-analytics-summary.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-artists',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array(),
				'properties'           => array(
					'days' => array( 'type' => 'integer', 'minimum' => 0, 'description' => __( 'Window in days for the recent aggregates. 0 disables the recent metrics window. Default 28.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'total_artist_profiles'    => array( 'type' => 'integer' ),
					'total_link_pages'         => array( 'type' => 'integer' ),
					'profiles_created_recent'  => array( 'type' => 'integer' ),
					'active_link_pages_recent' => array( 'type' => 'integer' ),
					'days'                     => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_get_artist_platform_stats',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
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
			'description'         => __( 'Returns the canonical published artist profile payload, including imagery, profile details, and official links.', 'extrachill-artist-platform' ),
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
					'permalink'         => array( 'type' => 'string', 'format' => 'uri' ),
					'bio'               => array( 'type' => 'string' ),
					'local_city'        => array( 'type' => array( 'string', 'null' ) ),
					'genre'             => array( 'type' => array( 'string', 'null' ) ),
					'profile_image_id'  => array( 'type' => array( 'integer', 'null' ) ),
					'profile_image_url' => array( 'type' => array( 'string', 'null' ) ),
					'header_image_id'   => array( 'type' => array( 'integer', 'null' ) ),
					'header_image_url'  => array( 'type' => array( 'string', 'null' ) ),
					'official_links'    => array( 'type' => 'array' ),
					'link_page_id'      => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_artist_get',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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
			'permission_callback' => 'extrachill_artist_platform_ability_artist_permission',
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

	// ──────────────────────────────────────────────────────────────────────
	// Admin artist-relationships abilities (network admin only)
	// ──────────────────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/admin-list-artist-relationships',
		array(
			'label'               => __( 'List artist relationships', 'extrachill-artist-platform' ),
			'description'         => __( 'Lists artist-user relationships by artists or users view.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-admin-artist-relationships',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array(),
				'properties'           => array(
					'view'   => array( 'type' => 'string', 'enum' => array( 'artists', 'users' ), 'description' => __( 'View mode.', 'extrachill-artist-platform' ) ),
					'search' => array( 'type' => 'string', 'description' => __( 'Search term.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_admin_list_artist_relationships',
			'permission_callback' => 'extrachill_artist_platform_ability_admin_permission',
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
		'extrachill/admin-link-artist-relationship',
		array(
			'label'               => __( 'Link artist relationship', 'extrachill-artist-platform' ),
			'description'         => __( 'Links a user to an artist profile.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-admin-artist-relationships',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'user_id', 'artist_id' ),
				'properties'           => array(
					'user_id'   => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'WordPress user ID.', 'extrachill-artist-platform' ) ),
					'artist_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_admin_link_artist_relationship',
			'permission_callback' => 'extrachill_artist_platform_ability_admin_permission',
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
		'extrachill/admin-unlink-artist-relationship',
		array(
			'label'               => __( 'Unlink artist relationship', 'extrachill-artist-platform' ),
			'description'         => __( 'Unlinks a user from an artist profile.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-admin-artist-relationships',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'user_id', 'artist_id' ),
				'properties'           => array(
					'user_id'   => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'WordPress user ID.', 'extrachill-artist-platform' ) ),
					'artist_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_admin_unlink_artist_relationship',
			'permission_callback' => 'extrachill_artist_platform_ability_admin_permission',
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
		'extrachill/admin-list-orphan-artist-relationships',
		array(
			'label'               => __( 'List orphan artist relationships', 'extrachill-artist-platform' ),
			'description'         => __( 'Lists orphaned artist-user relationships where the artist post no longer exists.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-admin-artist-relationships',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array(),
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'orphans' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_admin_list_orphan_artist_relationships',
			'permission_callback' => 'extrachill_artist_platform_ability_admin_permission',
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
		'extrachill/admin-cleanup-artist-relationships',
		array(
			'label'               => __( 'Cleanup artist relationships', 'extrachill-artist-platform' ),
			'description'         => __( 'Removes an orphaned artist-user relationship entry.', 'extrachill-artist-platform' ),
			'category'            => 'extrachill-admin-artist-relationships',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'user_id', 'artist_id' ),
				'properties'           => array(
					'user_id'   => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'WordPress user ID.', 'extrachill-artist-platform' ) ),
					'artist_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Artist profile post ID.', 'extrachill-artist-platform' ) ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_artist_platform_ability_admin_cleanup_artist_relationships',
			'permission_callback' => 'extrachill_artist_platform_ability_admin_permission',
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
}

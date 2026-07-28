<?php
/**
 * Presentation enrichment for the account subscription inventory.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_subscription_inventory_ability' );

/** Register the self-only subscription presentation resolver. */
function extrachill_community_register_subscription_inventory_ability(): void {
	wp_register_ability(
		'extrachill/community-resolve-subscription-entities',
		array(
			'label'               => __( 'Resolve Subscription Entities', 'extra-chill-community' ),
			'description'         => __( 'Resolve canonical names and URLs for account subscription identities.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'identities' => array(
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => array( 'type' => 'object' ),
					),
				),
				'required'   => array( 'identities' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_community_resolve_subscription_entities',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Resolve identity presentation without reading subscription storage.
 *
 * @param array $input Ability input.
 * @return array
 */
function extrachill_community_resolve_subscription_entities( array $input ): array {
	$resolved = array();
	foreach ( array_slice( (array) ( $input['identities'] ?? array() ), 0, 100 ) as $identity ) {
		$entity_type = sanitize_key( $identity['entity_type'] ?? '' );
		$taxonomy    = sanitize_key( $identity['taxonomy'] ?? '' );
		$slug        = sanitize_title( $identity['slug'] ?? '' );
		$item        = array(
			'entity_type' => $entity_type,
			'taxonomy'    => $taxonomy,
			'slug'        => $slug,
			'name'        => '',
			'url'         => '',
			'resolved'    => false,
		);

		$site_key = in_array( $taxonomy, array( 'venue', 'location' ), true ) ? 'events' : 'main';
		$blog_id  = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( $site_key ) : 0;
		if ( ! $blog_id || ! $taxonomy || ! $slug ) {
			$resolved[] = $item;
			continue;
		}

		switch_to_blog( $blog_id );
		try {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$item['name'] = $term->name;
				$url          = get_term_link( $term );
				$item['url']  = is_wp_error( $url ) ? '' : (string) $url;
				$item['resolved'] = true;
			}
		} finally {
			restore_current_blog();
		}

		// Artist Platform owns the canonical artist profile URL.
		if ( $item['resolved'] && 'artist' === $taxonomy && function_exists( 'ec_get_blog_id' ) ) {
			$artist_blog_id = (int) ec_get_blog_id( 'artist' );
			$main_blog_id   = (int) ec_get_blog_id( 'main' );
			switch_to_blog( $main_blog_id );
			try {
				$term       = get_term_by( 'slug', $slug, 'artist' );
				$profile_id = $term instanceof WP_Term ? absint( get_term_meta( $term->term_id, '_artist_profile_id', true ) ) : 0;
			} finally {
				restore_current_blog();
			}
			if ( $artist_blog_id && $profile_id ) {
				switch_to_blog( $artist_blog_id );
				try {
					$profile_url = get_permalink( $profile_id );
					if ( $profile_url ) {
						$item['url'] = (string) $profile_url;
					}
				} finally {
					restore_current_blog();
				}
			}
		}

		$resolved[] = $item;
	}

	return array( 'entities' => $resolved );
}

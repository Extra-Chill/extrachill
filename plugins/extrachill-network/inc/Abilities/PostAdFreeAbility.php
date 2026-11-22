<?php
/**
 * Per-post ad-free write ability.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the `_extrachill_ad_free` flag on or off for one post on the current
 * site. Registered network-wide; callers target a site the usual way
 * (cross-site ability dispatch), so post IDs stay blog-local.
 */
class PostAdFreeAbility {

	/**
	 * Register the ability when the Abilities API initializes.
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register the write ability.
	 */
	public function register(): void {
		wp_register_ability(
			'extrachill/set-post-ad-free',
			array(
				'label'               => __( 'Set Post Ad-Free', 'extrachill-network' ),
				'description'         => __( 'Turn ads off (or back on) for one post on the current site, for every visitor. Works for any post type.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post ID on the current site.',
						),
						'ad_free' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'True to block ads on the post, false to allow them again.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'ad_free', 'serve_ads' ),
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'ad_free'   => array( 'type' => 'boolean' ),
						'serve_ads' => array( 'type' => 'boolean' ),
						'reason'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'permission' ),
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
	}

	/**
	 * Only people who can edit the post may change its ad flag.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return bool
	 */
	public function permission( $input = null ): bool {
		$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Set the flag and report the resulting policy for the post.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ) {
		$post_id = (int) $input['post_id'];
		$ad_free = ! array_key_exists( 'ad_free', $input ) || (bool) $input['ad_free'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'extrachill_post_not_found', sprintf( 'Post %d does not exist on this site.', $post_id ), array( 'status' => 404 ) );
		}

		if ( $ad_free ) {
			update_post_meta( $post_id, EXTRACHILL_AD_FREE_META_KEY, '1' );
		} else {
			delete_post_meta( $post_id, EXTRACHILL_AD_FREE_META_KEY );
		}

		$policy = extrachill_get_ad_policy(
			array(
				'post_id'     => $post_id,
				'post_type'   => (string) $post->post_type,
				'is_singular' => true,
			)
		);

		return array(
			'post_id'   => $post_id,
			'ad_free'   => extrachill_is_post_ad_free( $post_id ),
			'serve_ads' => (bool) $policy['serve_ads'],
			'reason'    => (string) $policy['reason'],
		);
	}
}

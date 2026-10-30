<?php
/**
 * Abilities for approved network term search, projection, and classification.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

defined( 'ABSPATH' ) || exit;

/** Register network-owned term abilities. */
class NetworkTermAbilities {
	/** Hook ability registration. */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/** Register search, projection, and classification contracts. */
	public function register(): void {
		wp_register_ability(
			'extrachill/search-network-terms',
			array(
				'label'               => __( 'Search Approved Network Terms', 'extrachill-network' ),
				'description'         => __( 'Search trusted network taxonomy identities without creating local terms.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'site', 'post_type', 'taxonomy', 'query' ),
					'properties' => array(
						'site'      => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string' ),
						'taxonomy'  => array( 'type' => 'string' ),
						'query'     => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'limit'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'site'      => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string' ),
						'taxonomy'  => array( 'type' => 'string' ),
						'terms'     => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( $this, 'search' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/project-network-term',
			array(
				'label'               => __( 'Project Approved Network Term', 'extrachill-network' ),
				'description'         => __( 'Resolve or create a local term projection from one approved taxonomy and slug identity.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'site', 'post_id', 'taxonomy', 'slug' ),
					'properties' => array(
						'site'     => array( 'type' => 'string' ),
						'post_id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'taxonomy' => array( 'type' => 'string' ),
						'slug'     => array( 'type' => 'string' ),
						'dry_run'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array( 'type' => 'string' ),
						'slug'     => array( 'type' => 'string' ),
						'term_id'  => array( 'type' => 'integer' ),
						'created'  => array( 'type' => 'boolean' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'project' ),
				'permission_callback' => array( $this, 'canProject' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'idempotent' => true ),
				),
			)
		);

		wp_register_ability(
			'extrachill/classify-post-terms',
			array(
				'label'               => __( 'Classify Post Network Terms', 'extrachill-network' ),
				'description'         => __( 'Schedule classification or an explicit rerun for one eligible network post.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'site', 'post_id' ),
					'properties' => array(
						'site'       => array( 'type' => 'string' ),
						'post_id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'taxonomies' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'uniqueItems' => true,
						),
						'force'      => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'dry_run'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'scheduled'   => array( 'type' => 'boolean' ),
						'job_id'      => array( 'type' => 'integer' ),
						'fingerprint' => array( 'type' => 'string' ),
						'taxonomies'  => array( 'type' => 'array' ),
						'reason'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'classify' ),
				'permission_callback' => array( $this, 'canClassify' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Search approved identities for an eligible target.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function search( array $input ) {
		$site_key  = sanitize_key( $input['site'] );
		$post_type = sanitize_key( $input['post_type'] );
		$taxonomy  = sanitize_key( $input['taxonomy'] );
		$blog_id   = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $site_key ) : 0;
		if ( ! $blog_id ) {
			return new \WP_Error( 'unknown_site', __( 'Unknown target site.', 'extrachill-network' ) );
		}
		$routed = $this->routeToTargetRuntime( 'extrachill/search-network-terms', 'GET', $input, $blog_id );
		if ( null !== $routed ) {
			return $routed;
		}

		switch_to_blog( $blog_id );
		try {
			if ( ! in_array( $taxonomy, \extrachill_network_get_eligible_term_taxonomies( $site_key, $post_type, array( $taxonomy ) ), true ) ) {
				return new \WP_Error( 'unsupported_term_search', __( 'That taxonomy is not eligible for the target.', 'extrachill-network' ) );
			}
			$terms = \extrachill_network_search_terms( $taxonomy, $input['query'], $input['limit'] ?? 20 );
			return is_wp_error( $terms ) ? $terms : array(
				'site'      => $site_key,
				'post_type' => $post_type,
				'taxonomy'  => $taxonomy,
				'terms'     => $terms,
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Materialize one approved local projection.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function project( array $input ) {
		$site_key = sanitize_key( $input['site'] );
		$blog_id  = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $site_key ) : 0;
		if ( ! $blog_id ) {
			return new \WP_Error( 'unknown_site', __( 'Unknown target site.', 'extrachill-network' ) );
		}
		$routed = $this->routeToTargetRuntime( 'extrachill/project-network-term', 'POST', $input, $blog_id );
		if ( null !== $routed ) {
			return $routed;
		}
		switch_to_blog( $blog_id );
		try {
			$post       = get_post( absint( $input['post_id'] ) );
			$permission = $this->canEditTarget( $post );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
			if ( ! $permission ) {
				return new \WP_Error( 'term_projection_forbidden', __( 'You cannot edit that post.', 'extrachill-network' ), array( 'status' => 403 ) );
			}
			$taxonomy = sanitize_key( $input['taxonomy'] );
			return \extrachill_network_project_term( $site_key, $post->post_type, $taxonomy, $input['slug'], ! empty( $input['dry_run'] ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Schedule one manual classification or rerun.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function classify( array $input ) {
		$site_key = sanitize_key( $input['site'] );
		$blog_id  = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( $site_key ) : 0;
		if ( ! $blog_id ) {
			return new \WP_Error( 'unknown_site', __( 'Unknown target site.', 'extrachill-network' ) );
		}
		$routed = $this->routeToTargetRuntime( 'extrachill/classify-post-terms', 'POST', $input, $blog_id );
		if ( null !== $routed ) {
			return $routed;
		}
		switch_to_blog( $blog_id );
		try {
			$post       = get_post( absint( $input['post_id'] ) );
			$permission = $this->canEditTarget( $post );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
			if ( ! $permission ) {
				return new \WP_Error( 'classification_forbidden', __( 'You cannot classify that post.', 'extrachill-network' ), array( 'status' => 403 ) );
			}
		} finally {
			restore_current_blog();
		}
		return \extrachill_network_schedule_term_classification( $input );
	}

	/**
	 * Permission callback for trusted term projection.
	 *
	 * Cross-site calls authenticate again in the target runtime; the source
	 * runtime can only establish that a user is present.
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function canProject( array $input ) {
		return $this->canMutateInput( $input );
	}

	/**
	 * Permission callback for manual classification.
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function canClassify( array $input ) {
		return $this->canMutateInput( $input );
	}

	/** Resolve mutation permission in the runtime that owns the target type. */
	private function canMutateInput( array $input ) {
		$blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( sanitize_key( $input['site'] ?? '' ) ) : 0;
		if ( ! $blog_id || ! is_user_logged_in() ) {
			return false;
		}
		if ( (int) get_current_blog_id() !== (int) $blog_id ) {
			return true;
		}

		return $this->canEditTarget( get_post( absint( $input['post_id'] ?? 0 ) ) );
	}

	/**
	 * Use the target post type's edit contract, not taxonomy creation caps.
	 *
	 * @param mixed $post Target post.
	 * @return bool|\WP_Error
	 */
	private function canEditTarget( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'topic' === $post->post_type ) {
			if ( function_exists( 'extrachill_community_ability_update_topic_permission' ) ) {
				return extrachill_community_ability_update_topic_permission( array( 'topic_id' => $post->ID ) );
			}
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by bbPress.
			return current_user_can( 'edit_topic', $post->ID );
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Route explicit cross-site targets through a fully bootstrapped runtime.
	 *
	 * @param string $ability_name Ability name.
	 * @param string $method       REST method.
	 * @param array  $input        Ability input.
	 * @param int    $blog_id      Target blog ID.
	 * @return array|\WP_Error|null Null when already in the target runtime.
	 */
	private function routeToTargetRuntime( $ability_name, $method, array $input, $blog_id ) {
		if ( (int) get_current_blog_id() === (int) $blog_id ) {
			return null;
		}
		if ( ! function_exists( 'ec_cross_site_rest_request_http' ) ) {
			return new \WP_Error( 'target_runtime_unavailable', __( 'The target site runtime is unavailable.', 'extrachill-network' ) );
		}

		$site_key = sanitize_key( $input['site'] ?? '' );
		$route    = '/wp-abilities/v1/abilities/' . $ability_name . '/run';
		$args     = array( 'user_id' => get_current_user_id() );
		if ( 'GET' === $method ) {
			$args['query'] = array( 'input' => $input );
		} else {
			$args['body'] = array( 'input' => $input );
		}

		return ec_cross_site_rest_request_http( $site_key, $method, $route, $args );
	}
}

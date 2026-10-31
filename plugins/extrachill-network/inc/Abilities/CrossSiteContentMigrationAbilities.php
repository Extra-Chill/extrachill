<?php
/**
 * Cross-Site Content Migration Abilities
 *
 * Exposes the generic cross-site post-migration primitive
 * (`ec_migrate_post()`, defined in inc/core/cross-site-content-migration.php)
 * as a WordPress Ability so it is discoverable and callable via REST, MCP,
 * chat, and WP-CLI (through the thin `wp extrachill network migrate-post`
 * wrapper in extrachill-cli).
 *
 * The ability is a THIN adapter: it validates/normalizes input, gates on a
 * network-level capability, and delegates all work to `ec_migrate_post()`.
 * No migration logic lives here.
 *
 * @package ExtraChillNetwork\Abilities
 * @since 1.21.0
 */

namespace ExtraChillNetwork\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrossSiteContentMigrationAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		// Category is registered once via inc/Abilities/CategoryRegistration.php.
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/migrate-post',
			array(
				'label'               => __( 'Migrate Post Across Sites', 'extrachill-network' ),
				'description'         => __(
					'Move one post and all of its media from any blog in the network to any other blog. Non-destructive by default (the source is only deleted when delete_source is true and the migration verifies successfully). Supports dry-run.',
					'extrachill-network'
				),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'source_blog_id', 'post_id', 'dest_blog_id' ),
					'properties' => array(
						'source_blog_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Blog ID the post currently lives on.', 'extrachill-network' ),
						),
						'post_id'        => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Post ID on the source blog.', 'extrachill-network' ),
						),
						'dest_blog_id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Blog ID to migrate the post into.', 'extrachill-network' ),
						),
						'status'         => array(
							'type'        => 'string',
							'description' => __( 'Destination post status. Default: preserve source status (pending source maps to pending).', 'extrachill-network' ),
						),
						'delete_source'  => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Delete the source post + its attachments after a verified successful migration. Never fires on dry-run or partial failure.', 'extrachill-network' ),
						),
						'dry_run'        => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Report what would happen without writing anything on either blog.', 'extrachill-network' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'dry_run'              => array( 'type' => 'boolean' ),
						'source_blog_id'       => array( 'type' => 'integer' ),
						'source_post_id'       => array( 'type' => 'integer' ),
						'dest_blog_id'         => array( 'type' => 'integer' ),
						'dest_post_id'         => array( 'type' => 'integer' ),
						'dest_status'          => array( 'type' => 'string' ),
						'attachments_total'    => array( 'type' => 'integer' ),
						'attachments_migrated' => array( 'type' => 'integer' ),
						'featured_image_id'    => array( 'type' => 'integer' ),
						'source_deleted'       => array( 'type' => 'boolean' ),
						'missing_files'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'attachment_map'       => array( 'type' => 'object' ),
						'url_map'              => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'executeMigrate' ),
				'permission_callback' => array( $this, 'permissionCallback' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * Migrating content across sites is a privileged network operation — gate
	 * on `manage_network` (super admin) with a fallback to `manage_options` on
	 * the destination blog for single-site admins in dev contexts.
	 *
	 * @return bool
	 */
	public function permissionCallback(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( is_multisite() && current_user_can( 'manage_network' ) ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute migrate-post — delegates entirely to ec_migrate_post().
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeMigrate( array $input ) {
		if ( ! function_exists( 'ec_migrate_post' ) ) {
			return new \WP_Error(
				'ec_migrate_unavailable',
				__( 'Cross-site migration primitive is not loaded.', 'extrachill-network' ),
				array( 'status' => 500 )
			);
		}

		$source_blog_id = isset( $input['source_blog_id'] ) ? (int) $input['source_blog_id'] : 0;
		$post_id        = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$dest_blog_id   = isset( $input['dest_blog_id'] ) ? (int) $input['dest_blog_id'] : 0;

		$args = array(
			'status'        => isset( $input['status'] ) ? (string) $input['status'] : '',
			'delete_source' => ! empty( $input['delete_source'] ),
			'dry_run'       => ! empty( $input['dry_run'] ),
			'migrated_by'   => (int) get_current_user_id(),
		);

		return ec_migrate_post( $source_blog_id, $post_id, $dest_blog_id, $args );
	}
}

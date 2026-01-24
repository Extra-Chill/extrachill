<?php
/**
 * SEO Abilities
 *
 * WordPress 6.9 Abilities API integration for SEO management.
 * Registers the extrachill-seo category and all SEO-related abilities.
 *
 * @package ExtraChill\SEO\Abilities
 */

namespace ExtraChill\SEO\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Abilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->register_hooks();
			self::$registered = true;
		}
	}

	private function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category(): void {
		wp_register_ability_category(
			'extrachill-seo',
			array(
				'label'       => __( 'SEO Management', 'extrachill-seo' ),
				'description' => __( 'Audit, analyze, and manage SEO across the multisite network.', 'extrachill-seo' ),
			)
		);
	}

	public function register_abilities(): void {
		$this->register_run_seo_audit();
		$this->register_get_seo_results();
		$this->register_analyze_url();
		$this->register_get_seo_config();
		$this->register_update_seo_config();
		$this->register_ping_indexnow();
	}

	private function register_run_seo_audit(): void {
		wp_register_ability(
			'extrachill/run-seo-audit',
			array(
				'label'        => __( 'Run SEO Audit', 'extrachill-seo' ),
				'description'  => __( 'Run SEO audit across the multisite network with configurable check types.', 'extrachill-seo' ),
				'category'     => 'extrachill-seo',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'blog_id' => array(
							'type'        => 'integer',
							'description' => __( 'Blog ID to audit (default: all sites).', 'extrachill-seo' ),
						),
						'mode'    => array(
							'type'        => 'string',
							'enum'        => array( 'full', 'batch' ),
							'default'     => 'full',
							'description' => __( 'Run full audit or start batch mode for large sites.', 'extrachill-seo' ),
						),
						'checks'  => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array(
									'excerpts',
									'alt_text',
									'featured_images',
									'broken_images',
									'broken_links',
									'redirect_links',
								),
							),
							'default'     => array(
								'excerpts',
								'alt_text',
								'featured_images',
								'broken_images',
								'broken_links',
								'redirect_links',
							),
							'description' => __( 'Check types to run. Omit for all checks.', 'extrachill-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'mode'    => array( 'type' => 'string' ),
						'blog_id' => array( 'type' => 'integer' ),
						'status'  => array(
							'type' => 'string',
							'enum' => array( 'complete', 'in_progress' ),
						),
						'results' => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\extrachill_seo_ability_run_audit',
				'permission_callback' => function() {
					return current_user_can( 'manage_network_options' );
				},
				'meta' => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => false,
					),
				),
			)
		);
	}

	private function register_get_seo_results(): void {
		wp_register_ability(
			'extrachill/get-seo-results',
			array(
				'label'        => __( 'Get SEO Results', 'extrachill-seo' ),
				'description'  => __( 'Retrieve stored audit results from the most recent SEO audit.', 'extrachill-seo' ),
				'category'     => 'extrachill-seo',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'blog_id' => array(
							'type'        => 'integer',
							'description' => __( 'Blog ID to get results for (optional, returns all if omitted).', 'extrachill-seo' ),
						),
						'check'   => array(
							'type'        => 'string',
							'description' => __( 'Specific check to retrieve (optional).', 'extrachill-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'    => array( 'type' => 'string' ),
						'timestamp' => array( 'type' => 'integer' ),
						'last_run'  => array( 'type' => 'string', 'format' => 'date-time' ),
						'results'   => array( 'type' => 'object' ),
						'summary'   => array(
							'type'       => 'object',
							'properties' => array(
								'total_issues' => array( 'type' => 'integer' ),
								'by_check'     => array( 'type' => 'object' ),
							),
						),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\extrachill_seo_ability_get_results',
				'permission_callback' => function() {
					return current_user_can( 'manage_network_options' );
				},
				'meta' => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	private function register_analyze_url(): void {
		wp_register_ability(
			'extrachill/analyze-url',
			array(
				'label'        => __( 'Analyze URL', 'extrachill-seo' ),
				'description'  => __( 'Analyze a single URL for SEO issues including redirect detection.', 'extrachill-seo' ),
				'category'     => 'extrachill-seo',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'    => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'URL to analyze.', 'extrachill-seo' ),
						),
						'checks' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'redirect', 'meta', 'schema', 'robots', 'social' ),
							),
							'default'     => array( 'redirect', 'meta', 'schema', 'robots', 'social' ),
							'description' => __( 'Analysis types to perform.', 'extrachill-seo' ),
						),
					),
					'required' => array( 'url' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'             => array( 'type' => 'string' ),
						'final_url'       => array( 'type' => 'string' ),
						'analysis'        => array( 'type' => 'object' ),
						'score'           => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
						'recommendations' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\extrachill_seo_ability_analyze_url',
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
				'meta' => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	private function register_get_seo_config(): void {
		wp_register_ability(
			'extrachill/get-seo-config',
			array(
				'label'        => __( 'Get SEO Config', 'extrachill-seo' ),
				'description'  => __( 'Get current SEO configuration settings.', 'extrachill-seo' ),
				'category'     => 'extrachill-seo',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'default_og_image_id'  => array( 'type' => 'integer' ),
						'default_og_image_url' => array( 'type' => 'string' ),
						'indexnow_key'         => array( 'type' => 'string' ),
						'indexnow_enabled'     => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\extrachill_seo_ability_get_config',
				'permission_callback' => function() {
					return current_user_can( 'manage_network_options' );
				},
				'meta' => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	private function register_update_seo_config(): void {
		wp_register_ability(
			'extrachill/update-seo-config',
			array(
				'label'        => __( 'Update SEO Config', 'extrachill-seo' ),
				'description'  => __( 'Update SEO configuration settings.', 'extrachill-seo' ),
				'category'     => 'extrachill-seo',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'default_og_image_id' => array(
							'type'        => 'integer',
							'description' => __( 'Media library ID for default OG image.', 'extrachill-seo' ),
						),
						'indexnow_key'        => array(
							'type'        => 'string',
							'description' => __( 'IndexNow API key.', 'extrachill-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'updated' => array( 'type' => 'array' ),
						'config'  => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\extrachill_seo_ability_update_config',
				'permission_callback' => function() {
					return current_user_can( 'manage_network_options' );
				},
				'meta' => array(
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

	private function register_ping_indexnow(): void {
		wp_register_ability(
			'extrachill/ping-indexnow',
			array(
				'label'        => __( 'Ping IndexNow', 'extrachill-seo' ),
				'description'  => __( 'Manually trigger IndexNow notification for URLs.', 'extrachill-seo' ),
				'category'     => 'extrachill-seo',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'urls'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string', 'format' => 'uri' ),
							'description' => __( 'URLs to submit to IndexNow.', 'extrachill-seo' ),
							'maxItems'    => 10000,
						),
						'post_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Post IDs to submit (alternative to URLs).', 'extrachill-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'submitted'     => array( 'type' => 'integer' ),
						'urls'          => array( 'type' => 'array' ),
						'response_code' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => __NAMESPACE__ . '\\extrachill_seo_ability_ping_indexnow',
				'permission_callback' => function() {
					return current_user_can( 'publish_posts' );
				},
				'meta' => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => false,
					),
				),
			)
		);
	}
}

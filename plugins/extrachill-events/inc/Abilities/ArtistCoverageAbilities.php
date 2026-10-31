<?php
/**
 * Artist Coverage Abilities
 *
 * Operator surface for the "Artists We Cover" Ticketmaster import: inspect
 * coverage, configure the target flow step, add or exclude acts by name,
 * and run a sync. Business logic lives in ArtistCoverageSync.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\ArtistCoverageSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistCoverageSync.php';

/** Registers the artist coverage abilities. */
class ArtistCoverageAbilities {

	/**
	 * Registration guard.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/** Hook ability registration once. */
	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the abilities. */
	public function register(): void {
		$permission = static fn(): bool => current_user_can( 'manage_options' );
		$object     = array( 'type' => 'object' );

		wp_register_ability(
			'extrachill/get-artist-coverage',
			array(
				'label'               => __( 'Get Artist Coverage', 'extrachill-events' ),
				'description'         => __( 'Show the Artists We Cover configuration and each covered artist\'s Ticketmaster match.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'output_schema'       => $object,
				'execute_callback'    => array( $this, 'get_coverage' ),
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'extrachill/configure-artist-coverage',
			array(
				'label'               => __( 'Configure Artist Coverage', 'extrachill-events' ),
				'description'         => __( 'Set the Ticketmaster flow step that artist coverage maintains, the post threshold, and the ID cap.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'flow_step_id' => array( 'type' => 'string' ),
						'min_posts'    => array( 'type' => 'integer' ),
						'max_ids'      => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => $object,
				'execute_callback'    => array( $this, 'configure' ),
				'permission_callback' => $permission,
				'meta'                => array( 'show_in_rest' => false ),
			)
		);

		wp_register_ability(
			'extrachill/sync-artist-coverage',
			array(
				'label'               => __( 'Sync Artist Coverage', 'extrachill-events' ),
				'description'         => __( 'Match covered artists to Ticketmaster and write the attraction list to the import flow.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'limit'   => array(
							'type'        => 'integer',
							'description' => 'Max Ticketmaster lookups this run (default 40).',
						),
					),
				),
				'output_schema'       => $object,
				'execute_callback'    => array( $this, 'sync' ),
				'permission_callback' => $permission,
				'meta'                => array( 'show_in_rest' => false ),
			)
		);

		wp_register_ability(
			'extrachill/add-artist-coverage',
			array(
				'label'               => __( 'Add Artist Coverage', 'extrachill-events' ),
				'description'         => __( 'Add an act to the Ticketmaster artist import by name (exact match) or explicit attraction ID.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name'          => array( 'type' => 'string' ),
						'attraction_id' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => $object,
				'execute_callback'    => array( $this, 'add' ),
				'permission_callback' => $permission,
				'meta'                => array( 'show_in_rest' => false ),
			)
		);

		wp_register_ability(
			'extrachill/exclude-artist-coverage',
			array(
				'label'               => __( 'Exclude Artist Coverage', 'extrachill-events' ),
				'description'         => __( 'Exclude an act (by name or attraction ID) from the Ticketmaster artist import, e.g. a different act sharing the name.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => $object,
				'execute_callback'    => array( $this, 'exclude' ),
				'permission_callback' => $permission,
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	/**
	 * Coverage status.
	 *
	 * @return array
	 */
	public function get_coverage(): array {
		$config  = ArtistCoverageSync::config();
		$matches = ArtistCoverageSync::matches();

		$artists = array();
		foreach ( $matches as $term_id => $match ) {
			$artists[] = array(
				'term_id'         => (int) $term_id,
				'name'            => (string) ( $match['name'] ?? '' ),
				'attraction_id'   => (string) ( $match['id'] ?? '' ),
				'attraction_name' => (string) ( $match['attraction_name'] ?? '' ),
				'upcoming'        => (int) ( $match['upcoming'] ?? 0 ),
				'excluded'        => in_array( (string) ( $match['id'] ?? '' ), $config['excluded'], true ),
				'checked'         => (int) ( $match['checked'] ?? 0 ),
			);
		}
		usort( $artists, static fn( $a, $b ) => $b['upcoming'] <=> $a['upcoming'] );

		return array(
			'config'         => $config,
			'artists'        => $artists,
			'attraction_ids' => ArtistCoverageSync::select_attraction_ids( $matches, $config['manual'], $config['excluded'], $config['max_ids'] ),
		);
	}

	/**
	 * Update configuration.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function configure( array $input ): array {
		$config = ArtistCoverageSync::update_config( array_intersect_key( $input, array_flip( array( 'flow_step_id', 'min_posts', 'max_ids' ) ) ) );
		ArtistCoverageSync::ensure_scheduled();

		return $config;
	}

	/**
	 * Run a sync.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function sync( array $input ) {
		return ArtistCoverageSync::sync( (bool) ( $input['dry_run'] ?? true ), (int) ( $input['limit'] ?? ArtistCoverageSync::BATCH_SIZE ) );
	}

	/**
	 * Add an act.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function add( array $input ) {
		return ArtistCoverageSync::add( (string) ( $input['name'] ?? '' ), (string) ( $input['attraction_id'] ?? '' ) );
	}

	/**
	 * Exclude an act.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function exclude( array $input ) {
		return ArtistCoverageSync::exclude( (string) ( $input['name'] ?? '' ) );
	}
}

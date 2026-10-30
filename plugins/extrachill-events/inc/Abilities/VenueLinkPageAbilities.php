<?php
/**
 * Closed venue Link Page ability contracts.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueLinkPages;

defined( 'ABSPATH' ) || exit;

/** Registers narrow management operations for the venue workspace consumer. */
final class VenueLinkPageAbilities {
	public const MAX_LINK_SECTIONS     = 10;
	public const MAX_LINKS_PER_SECTION = 25;
	public const MAX_ID_LENGTH         = 100;
	public const MAX_TITLE_LENGTH      = 200;
	public const MAX_LINK_TEXT_LENGTH  = 200;
	public const MAX_URL_LENGTH        = 2048;
	public const MAX_EXPIRATION_LENGTH = 64;

	/**
	 * Whether the ability hook registered in this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Attach registration after the abilities substrate initializes. */
	public function __construct() {
		if ( ! self::$registered ) {
			self::$registered = true;
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
		}
	}

	/** Register only concrete venue management operations. */
	public function register(): void {
		$this->register_ability( 'extrachill/provision-venue-link-page', __( 'Provision Venue Link Page', 'extrachill-events' ), array(), array( $this, 'provision' ), false, true );
		$this->register_ability( 'extrachill/get-venue-link-page', __( 'Get Venue Link Page', 'extrachill-events' ), array(), array( $this, 'get' ), true, true );
		$this->register_ability(
			'extrachill/save-venue-link-page-links',
			__( 'Save Venue Link Page Links', 'extrachill-events' ),
			array(
				'links'             => array(
					'type'     => 'array',
					'maxItems' => self::MAX_LINK_SECTIONS,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'            => array(
								'type'      => 'string',
								'maxLength' => self::MAX_ID_LENGTH,
							),
							'section_title' => array(
								'type'      => 'string',
								'maxLength' => self::MAX_TITLE_LENGTH,
							),
							'links'         => array(
								'type'     => 'array',
								'maxItems' => self::MAX_LINKS_PER_SECTION,
								'items'    => array(
									'type'                 => 'object',
									'properties'           => array(
										'id'         => array(
											'type'      => 'string',
											'maxLength' => self::MAX_ID_LENGTH,
										),
										'link_text'  => array(
											'type'      => 'string',
											'maxLength' => self::MAX_LINK_TEXT_LENGTH,
										),
										'link_url'   => array(
											'type'      => 'string',
											'format'    => 'uri',
											'maxLength' => self::MAX_URL_LENGTH,
										),
										'expires_at' => array(
											'type'      => 'string',
											'maxLength' => self::MAX_EXPIRATION_LENGTH,
										),
									),
									'required'             => array( 'link_text', 'link_url' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'links' ),
						'additionalProperties' => false,
					),
				),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_links' ),
			false,
			false
		);
		$this->register_ability(
			'extrachill/save-venue-link-page-styles',
			__( 'Save Venue Link Page Styles', 'extrachill-events' ),
			array(
				'css_vars'          => $this->styles_schema(),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_styles' ),
			false,
			false
		);
		$this->register_ability(
			'extrachill/save-venue-link-page-settings',
			__( 'Save Venue Link Page Settings', 'extrachill-events' ),
			array(
				'settings'          => $this->settings_schema(),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_settings' ),
			false,
			false
		);
		$this->register_patch_ability();
		$this->register_ability( 'extrachill/refresh-venue-link-page-snapshot', __( 'Refresh Venue Link Page Snapshot', 'extrachill-events' ), array(), array( $this, 'refresh' ), false, true );
		$this->register_ability(
			'extrachill/get-venue-link-page-analytics',
			__( 'Get Venue Link Page Analytics', 'extrachill-events' ),
			array(
				'date_range' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 90,
					'default' => 30,
				),
				'start_date' => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'end_date'   => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
			),
			array( $this, 'analytics' ),
			true,
			true,
			$this->analytics_schema()
		);
	}

	/** Register one atomic sparse patch contract for the shared editor. */
	private function register_patch_ability(): void {
		wp_register_ability(
			'extrachill/patch-venue-link-page',
			array(
				'label'               => __( 'Patch Venue Link Page', 'extrachill-events' ),
				'description'         => __( 'Atomically patch changed venue Link Page areas.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'venue_term_id'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'expected_revision'   => $this->revision_schema(),
						'links'               => $this->links_input_schema(),
						'css_vars'            => $this->styles_schema(),
						'settings'            => $this->settings_schema(),
						'background_image_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					'required'             => array( 'venue_term_id', 'expected_revision' ),
					'minProperties'        => 3,
					'additionalProperties' => false,
				),
				'output_schema'       => $this->document_schema(),
				'execute_callback'    => array( $this, 'patch' ),
				'permission_callback' => array( $this, 'authorize' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => true,
					),
				),
			)
		);
	}

	/** Register a REST-visible ability with a closed top-level request. */
	private function register_ability( string $name, string $label, array $properties, callable $execute, bool $is_readonly, bool $idempotent, ?array $output_schema = null ): void {
		$properties = array_merge(
			array(
				'venue_term_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			$properties
		);
		$required   = array( 'venue_term_id' );
		foreach ( array( 'links', 'css_vars', 'settings', 'expected_revision' ) as $field ) {
			if ( isset( $properties[ $field ] ) ) {
				$required[] = $field;
			}
		}
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $label,
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => $output_schema ? $output_schema : $this->document_schema(),
				'execute_callback'    => $execute,
				'permission_callback' => array( $this, 'authorize' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => $is_readonly,
						'idempotent'  => $idempotent,
						'destructive' => ! $is_readonly,
					),
				),
			)
		);
	}

	/** Enforce the exact direct membership policy at the transport boundary. */
	public function authorize( array $input ) {
		return VenueLinkPages::authorize_venue( absint( $input['venue_term_id'] ?? 0 ) );
	}

	public function provision( array $input ) {
		return VenueLinkPages::provision( absint( $input['venue_term_id'] ) );
	}

	public function get( array $input ) {
		$reference = VenueLinkPages::owner_reference( absint( $input['venue_term_id'] ) );
		return is_wp_error( $reference ) ? $reference : ec_read_link_page( $reference );
	}

	public function save_links( array $input ) {
		return $this->save(
			$input,
			array(
				'links'             => $input['links'],
				'expected_revision' => $input['expected_revision'],
			)
		);
	}

	public function save_styles( array $input ) {
		return $this->save(
			$input,
			array(
				'css_vars'          => $input['css_vars'],
				'expected_revision' => $input['expected_revision'],
			)
		);
	}

	public function save_settings( array $input ) {
		return $this->save( $input, array_merge( $input['settings'], array( 'expected_revision' => $input['expected_revision'] ) ) );
	}

	/** Atomically save only supplied generic areas under one canonical lock. */
	public function patch( array $input ) {
		$data = array( 'expected_revision' => $input['expected_revision'] );
		foreach ( array( 'links', 'css_vars', 'background_image_id' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = $input[ $field ];
			}
		}
		if ( isset( $input['settings'] ) ) {
			$data = array_merge( $data, $input['settings'] );
		}
		return $this->save( $input, $data );
	}

	public function refresh( array $input ) {
		return VenueLinkPages::refresh_snapshot( absint( $input['venue_term_id'] ) );
	}

	public function analytics( array $input ) {
		return VenueLinkPages::analytics( absint( $input['venue_term_id'] ), absint( $input['date_range'] ?? 30 ), (string) ( $input['start_date'] ?? '' ), (string) ( $input['end_date'] ?? '' ) );
	}

	/** Save through the standalone operation boundary, which reauthorizes. */
	private function save( array $input, array $data ) {
		$reference = VenueLinkPages::owner_reference( absint( $input['venue_term_id'] ) );
		return is_wp_error( $reference ) ? $reference : ec_save_link_page( $reference, $data );
	}

	/** Closed bounded section-and-link patch schema. */
	private function links_input_schema(): array {
		return array(
			'type'     => 'array',
			'maxItems' => self::MAX_LINK_SECTIONS,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'            => array(
						'type'      => 'string',
						'maxLength' => self::MAX_ID_LENGTH,
					),
					'section_title' => array(
						'type'      => 'string',
						'maxLength' => self::MAX_TITLE_LENGTH,
					),
					'links'         => array(
						'type'     => 'array',
						'maxItems' => self::MAX_LINKS_PER_SECTION,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'         => array(
									'type'      => 'string',
									'maxLength' => self::MAX_ID_LENGTH,
								),
								'link_text'  => array(
									'type'      => 'string',
									'maxLength' => self::MAX_LINK_TEXT_LENGTH,
								),
								'link_url'   => array(
									'type'      => 'string',
									'format'    => 'uri',
									'maxLength' => self::MAX_URL_LENGTH,
								),
								'expires_at' => array(
									'type'      => 'string',
									'maxLength' => self::MAX_EXPIRATION_LENGTH,
								),
							),
							'required'             => array( 'link_text', 'link_url' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'links' ),
				'additionalProperties' => false,
			),
		);
	}

	/** Enumerate standalone-supported style keys while leaving value validation generic. */
	private function styles_schema(): array {
		$keys = array( '--link-page-background-color', '--link-page-card-bg-color', '--link-page-text-color', '--link-page-link-text-color', '--link-page-button-bg-color', '--link-page-button-border-color', '--link-page-button-hover-bg-color', '--link-page-button-hover-text-color', '--link-page-muted-text-color', '--link-page-overlay-color', '--link-page-input-bg', '--link-page-accent', '--link-page-accent-hover', '--link-page-background-type', '--link-page-background-gradient-start', '--link-page-background-gradient-end', '--link-page-background-gradient-direction', '--link-page-background-image-url', '--link-page-image-size', '--link-page-image-position', '--link-page-image-repeat', 'overlay', '--link-page-title-font-family', '--link-page-title-font-size', '--link-page-body-font-family', '--link-page-button-radius', '--link-page-button-border-width', '--link-page-profile-img-size', '_link_page_profile_img_shape', '--link-page-profile-img-shape' );
		return array(
			'type'                 => 'object',
			'properties'           => array_fill_keys( $keys, array( 'type' => 'string' ) ),
			'minProperties'        => 1,
			'additionalProperties' => false,
		);
	}

	/** Enumerate the generic settings accepted by the standalone runtime. */
	private function settings_schema(): array {
		$boolean = array( 'type' => 'boolean' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'link_expiration_enabled' => $boolean,
				'redirect_enabled'        => $boolean,
				'redirect_target_url'     => array( 'type' => 'string' ),
				'youtube_embed_enabled'   => $boolean,
				'meta_pixel_id'           => array( 'type' => 'string' ),
				'google_tag_id'           => array( 'type' => 'string' ),
				'google_tag_manager_id'   => array( 'type' => 'string' ),
				'social_icons_position'   => array(
					'type' => 'string',
					'enum' => array( 'above', 'below' ),
				),
				'profile_image_shape'     => array(
					'type' => 'string',
					'enum' => array( 'circle', 'square', 'rectangle' ),
				),
				'background_image_id'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'minProperties'        => 1,
			'additionalProperties' => false,
		);
	}

	/** Closed optimistic concurrency token. */
	private function revision_schema(): array {
		return array(
			'type'      => 'string',
			'minLength' => 64,
			'maxLength' => 64,
			'pattern'   => '^[a-f0-9]{64}$',
		);
	}

	/** Closed composed response envelope; standalone owns nested field semantics. */
	private function document_schema(): array {
		$link     = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'         => array( 'type' => 'string' ),
				'link_text'  => array( 'type' => 'string' ),
				'link_url'   => array( 'type' => 'string' ),
				'expires_at' => array( 'type' => 'string' ),
			),
			'required'             => array( 'id', 'link_text', 'link_url' ),
			'additionalProperties' => false,
		);
		$section  = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'            => array( 'type' => 'string' ),
				'section_title' => array( 'type' => 'string' ),
				'links'         => array(
					'type'  => 'array',
					'items' => $link,
				),
			),
			'required'             => array( 'id', 'section_title', 'links' ),
			'additionalProperties' => false,
		);
		$social   = array(
			'type'                 => 'object',
			'properties'           => array(
				'type' => array( 'type' => 'string' ),
				'url'  => array( 'type' => 'string' ),
			),
			'required'             => array( 'type', 'url' ),
			'additionalProperties' => false,
		);
		$snapshot = array(
			'type'                 => 'object',
			'properties'           => array(
				'version'         => array( 'type' => 'integer' ),
				'owner_reference' => array( 'type' => 'string' ),
				'title'           => array( 'type' => 'string' ),
				'description'     => array( 'type' => 'string' ),
				'image_url'       => array( 'type' => 'string' ),
				'image_alt'       => array( 'type' => 'string' ),
				'website'         => array( 'type' => 'string' ),
				'social_links'    => array(
					'type'  => 'array',
					'items' => $social,
				),
				'location'        => array(
					'type'                 => 'object',
					'properties'           => array_fill_keys( array( 'address', 'city', 'state', 'zip', 'country' ), array( 'type' => 'string' ) ),
					'required'             => array( 'address', 'city', 'state', 'zip', 'country' ),
					'additionalProperties' => false,
				),
				'source'          => array(
					'type'                 => 'object',
					'properties'           => array(
						'blog_id'       => array( 'type' => 'integer' ),
						'taxonomy'      => array( 'type' => 'string' ),
						'venue_term_id' => array( 'type' => 'integer' ),
						'version'       => array( 'type' => 'string' ),
						'refreshed_at'  => array( 'type' => 'string' ),
						'public_url'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'blog_id', 'taxonomy', 'venue_term_id', 'version', 'refreshed_at', 'public_url' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'version', 'owner_reference', 'title', 'description', 'image_url', 'image_alt', 'website', 'social_links', 'location', 'source' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'created'   => array( 'type' => 'boolean' ),
				'venue'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'term_id'         => array( 'type' => 'integer' ),
						'owner_reference' => array( 'type' => 'string' ),
						'title'           => array( 'type' => 'string' ),
						'management_url'  => array( 'type' => 'string' ),
						'snapshot'        => $snapshot,
					),
					'required'             => array( 'term_id', 'owner_reference', 'title', 'management_url', 'snapshot' ),
					'additionalProperties' => false,
				),
				'link_page' => array(
					'type'                 => 'object',
					'properties'           => array(
						'link_page_id'         => array( 'type' => 'integer' ),
						'css_vars'             => $this->styles_schema(),
						'links'                => array(
							'type'  => 'array',
							'items' => array( 'oneOf' => array( $section, $link ) ),
						),
						'link_sections'        => array(
							'type'  => 'array',
							'items' => $section,
						),
						'bio'                  => array( 'type' => 'string' ),
						'settings'             => $this->settings_output_schema(),
						'background_image_id'  => array( 'type' => 'integer' ),
						'background_image_url' => array( 'type' => 'string' ),
						'public_url'           => array( 'type' => 'string' ),
						'revision'             => $this->revision_schema(),
					),
					'required'             => array( 'link_page_id', 'css_vars', 'links', 'link_sections', 'bio', 'settings', 'background_image_id', 'background_image_url', 'public_url', 'revision' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'venue', 'link_page' ),
			'additionalProperties' => false,
		);
	}

	/** Describe the complete shared settings read projection. */
	private function settings_output_schema(): array {
		$schema                                      = $this->settings_schema();
		$schema['properties']['overlay_enabled']     = array( 'type' => 'boolean' );
		$schema['properties']['background_image_id'] = array(
			'oneOf' => array(
				array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				array(
					'type' => 'string',
					'enum' => array( '' ),
				),
			),
		);
		$schema['required']                          = array_keys( $schema['properties'] );
		unset( $schema['minProperties'] );
		return $schema;
	}

	/** Return the established analytics provider projection as a closed schema. */
	private function analytics_schema(): array {
		$series = array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'start_date' => array( 'type' => 'string' ),
				'end_date'   => array( 'type' => 'string' ),
				'days'       => array( 'type' => 'integer' ),
				'summary'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'total_views'  => array( 'type' => 'integer' ),
						'total_clicks' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'total_views', 'total_clicks' ),
					'additionalProperties' => false,
				),
				'chart_data' => array(
					'type'                 => 'object',
					'properties'           => array(
						'labels'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'datasets' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'label' => array( 'type' => 'string' ),
									'data'  => $series,
								),
								'required'             => array( 'label', 'data' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'labels', 'datasets' ),
					'additionalProperties' => false,
				),
				'top_links'  => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'text'       => array( 'type' => 'string' ),
							'identifier' => array( 'type' => 'string' ),
							'clicks'     => array( 'type' => 'integer' ),
						),
						'required'             => array( 'text', 'identifier', 'clicks' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'start_date', 'end_date', 'days', 'summary', 'chart_data', 'top_links' ),
			'additionalProperties' => false,
		);
	}
}

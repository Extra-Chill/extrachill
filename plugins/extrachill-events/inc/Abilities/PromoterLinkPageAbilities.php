<?php
/**
 * Closed verified promoter Link Page and discovery abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\PromoterLinkPages;

defined( 'ABSPATH' ) || exit;

/** Registers concrete promoter management and approved identity operations. */
final class PromoterLinkPageAbilities {
	public const MAX_LINK_SECTIONS     = 10;
	public const MAX_LINKS_PER_SECTION = 25;
	public const MAX_LINK_ITEMS        = 250;
	public const MAX_ID_LENGTH         = 100;
	public const MAX_TITLE_LENGTH      = 200;
	public const MAX_LINK_TEXT_LENGTH  = 200;
	public const MAX_URL_LENGTH        = 2048;
	public const MAX_EXPIRATION_LENGTH = 64;

	/**
	 * Whether ability registration has been attached.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Attach registration once. */
	public function __construct() {
		if ( ! self::$registered ) {
			self::$registered = true;
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
		}
	}

	/** Register seven management operations and one public discovery operation. */
	public function register(): void {
		$this->register_management( 'extrachill/provision-promoter-link-page', __( 'Provision Promoter Link Page', 'extrachill-events' ), array(), array( $this, 'provision' ), false, true );
		$this->register_management( 'extrachill/get-promoter-link-page', __( 'Get Promoter Link Page', 'extrachill-events' ), array(), array( $this, 'get' ), true, true );
		$this->register_management(
			'extrachill/save-promoter-link-page-links',
			__( 'Save Promoter Link Page Links', 'extrachill-events' ),
			array(
				'links'             => $this->links_input_schema(),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_links' ),
			false,
			false
		);
		$this->register_management(
			'extrachill/save-promoter-link-page-styles',
			__( 'Save Promoter Link Page Styles', 'extrachill-events' ),
			array(
				'css_vars'          => $this->styles_schema(),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_styles' ),
			false,
			false
		);
		$this->register_management(
			'extrachill/save-promoter-link-page-settings',
			__( 'Save Promoter Link Page Settings', 'extrachill-events' ),
			array(
				'settings'          => $this->settings_schema(),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_settings' ),
			false,
			false
		);
		$this->register_patch_ability();
		$this->register_management( 'extrachill/refresh-promoter-link-page-snapshot', __( 'Refresh Promoter Link Page Snapshot', 'extrachill-events' ), array(), array( $this, 'refresh' ), false, true );
		$this->register_management(
			'extrachill/get-promoter-link-page-analytics',
			__( 'Get Promoter Link Page Analytics', 'extrachill-events' ),
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
		wp_register_ability(
			'extrachill/list-approved-promoters',
			array(
				'label'               => __( 'List Approved Promoters', 'extrachill-events' ),
				'description'         => __( 'List the bounded public projection of active verified Events promoters.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->discovery_schema(),
				'execute_callback'    => array( $this, 'approved_promoters' ),
				'permission_callback' => array( $this, 'allow_discovery' ),
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
	}

	/** Register one atomic sparse patch contract for the shared editor. */
	private function register_patch_ability(): void {
		wp_register_ability(
			'extrachill/patch-promoter-link-page',
			array(
				'label'               => __( 'Patch Promoter Link Page', 'extrachill-events' ),
				'description'         => __( 'Atomically patch changed promoter Link Page areas.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'promoter_term_id'    => array(
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
					'required'             => array( 'promoter_term_id', 'expected_revision' ),
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

	/**
	 * Register a REST-visible management ability with a closed request.
	 *
	 * @param string     $name          Ability name.
	 * @param string     $label         Human-readable label.
	 * @param array      $properties    Input properties.
	 * @param callable   $execute       Execution callback.
	 * @param bool       $is_readonly   Whether the operation is read-only.
	 * @param bool       $idempotent    Whether the operation is idempotent.
	 * @param array|null $output_schema Optional output schema.
	 */
	private function register_management( string $name, string $label, array $properties, callable $execute, bool $is_readonly, bool $idempotent, ?array $output_schema = null ): void {
		$properties = array_merge(
			array(
				'promoter_term_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			$properties
		);
		$required   = array( 'promoter_term_id' );
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

	/**
	 * Authorize the exact active verified promoter member.
	 *
	 * @param array $input Ability input.
	 */
	public function authorize( array $input ) {
		return PromoterLinkPages::authorize_promoter( absint( $input['promoter_term_id'] ?? 0 ) );
	}

	/** Public discovery needs no taxonomy or membership authority. */
	public function allow_discovery(): bool {
		return true;
	}

	/**
	 * Provision a promoter Link Page.
	 *
	 * @param array $input Ability input.
	 */
	public function provision( array $input ) {
		return PromoterLinkPages::provision( absint( $input['promoter_term_id'] ) );
	}

	/**
	 * Read a promoter Link Page.
	 *
	 * @param array $input Ability input.
	 */
	public function get( array $input ) {
		$reference = PromoterLinkPages::owner_reference( absint( $input['promoter_term_id'] ) );
		return is_wp_error( $reference ) ? $reference : ec_read_link_page( $reference );
	}

	/**
	 * Save bounded promoter links.
	 *
	 * @param array $input Ability input.
	 */
	public function save_links( array $input ) {
		$valid = $this->validate_links( $input['links'] ?? null );
		if ( true !== $valid ) {
			return $valid;
		}
		return $this->save(
			$input,
			array(
				'links'             => $input['links'],
				'expected_revision' => $input['expected_revision'],
			)
		);
	}

	/**
	 * Save promoter styles.
	 *
	 * @param array $input Ability input.
	 */
	public function save_styles( array $input ) {
		return $this->save(
			$input,
			array(
				'css_vars'          => $input['css_vars'],
				'expected_revision' => $input['expected_revision'],
			)
		);
	}

	/**
	 * Save promoter settings.
	 *
	 * @param array $input Ability input.
	 */
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

	/**
	 * Refresh the promoter snapshot.
	 *
	 * @param array $input Ability input.
	 */
	public function refresh( array $input ) {
		return PromoterLinkPages::refresh_snapshot( absint( $input['promoter_term_id'] ) );
	}

	/**
	 * Read promoter Link Page analytics.
	 *
	 * @param array $input Ability input.
	 */
	public function analytics( array $input ) {
		return PromoterLinkPages::analytics( absint( $input['promoter_term_id'] ), absint( $input['date_range'] ?? 30 ), (string) ( $input['start_date'] ?? '' ), (string) ( $input['end_date'] ?? '' ) );
	}

	/** List approved promoters. */
	public function approved_promoters() {
		return PromoterLinkPages::approved_promoters();
	}

	/**
	 * Save through the standalone operation boundary, which reauthorizes.
	 *
	 * @param array $input Ability input.
	 * @param array $data  Bounded save data.
	 */
	private function save( array $input, array $data ) {
		$reference = PromoterLinkPages::owner_reference( absint( $input['promoter_term_id'] ) );
		return is_wp_error( $reference ) ? $reference : ec_save_link_page( $reference, $data );
	}

	/** Closed links request schema. */
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
						'items'    => $this->link_schema( false ),
					),
				),
				'required'             => array( 'links' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Link item schema shared by input and output.
	 *
	 * @param bool $output Whether this is an output schema.
	 */
	private function link_schema( bool $output ): array {
		return array(
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
					'format'    => $output ? 'uri-reference' : 'uri',
					'maxLength' => self::MAX_URL_LENGTH,
				),
				'expires_at' => array(
					'type'      => 'string',
					'maxLength' => self::MAX_EXPIRATION_LENGTH,
				),
			),
			'required'             => $output ? array( 'id', 'link_text', 'link_url' ) : array( 'link_text', 'link_url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Reject over-limit payloads even when execute_callback is invoked directly.
	 *
	 * @param mixed $sections Submitted sections.
	 */
	private function validate_links( $sections ) {
		if ( ! is_array( $sections ) || count( $sections ) > self::MAX_LINK_SECTIONS ) {
			return $this->invalid_links();
		}
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['links'] ) || ! is_array( $section['links'] ) || count( $section['links'] ) > self::MAX_LINKS_PER_SECTION ) {
				return $this->invalid_links();
			}
			if ( ! $this->bounded_string( $section['id'] ?? '', self::MAX_ID_LENGTH ) || ! $this->bounded_string( $section['section_title'] ?? '', self::MAX_TITLE_LENGTH ) ) {
				return $this->invalid_links();
			}
			foreach ( $section['links'] as $link ) {
				if ( ! is_array( $link ) || ! $this->bounded_string( $link['id'] ?? '', self::MAX_ID_LENGTH ) || ! $this->bounded_string( $link['link_text'] ?? null, self::MAX_LINK_TEXT_LENGTH, false ) || ! $this->bounded_string( $link['link_url'] ?? null, self::MAX_URL_LENGTH, false ) || ! $this->bounded_string( $link['expires_at'] ?? '', self::MAX_EXPIRATION_LENGTH ) ) {
					return $this->invalid_links();
				}
			}
		}
		return true;
	}

	/**
	 * Check one scalar string against a concrete character bound.
	 *
	 * @param mixed $value       Submitted value.
	 * @param int   $maximum     Maximum characters.
	 * @param bool  $allow_empty Whether an empty string is accepted.
	 */
	private function bounded_string( $value, int $maximum, bool $allow_empty = true ): bool {
		if ( ! is_string( $value ) || ( ! $allow_empty && '' === trim( $value ) ) ) {
			return false;
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		return $length <= $maximum;
	}

	/** Build the stable direct-execution validation error. */
	private function invalid_links(): \WP_Error {
		return new \WP_Error( 'invalid_promoter_link_page_links', __( 'Promoter Link Page links exceed the supported limits or contain invalid values.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	/** Closed standalone style map. */
	private function styles_schema(): array {
		$keys = array( '--link-page-background-color', '--link-page-card-bg-color', '--link-page-text-color', '--link-page-link-text-color', '--link-page-button-bg-color', '--link-page-button-border-color', '--link-page-button-hover-bg-color', '--link-page-button-hover-text-color', '--link-page-muted-text-color', '--link-page-overlay-color', '--link-page-input-bg', '--link-page-accent', '--link-page-accent-hover', '--link-page-background-type', '--link-page-background-gradient-start', '--link-page-background-gradient-end', '--link-page-background-gradient-direction', '--link-page-background-image-url', '--link-page-image-size', '--link-page-image-position', '--link-page-image-repeat', 'overlay', '--link-page-title-font-family', '--link-page-title-font-size', '--link-page-body-font-family', '--link-page-button-radius', '--link-page-button-border-width', '--link-page-profile-img-size', '_link_page_profile_img_shape', '--link-page-profile-img-shape' );
		return array(
			'type'                 => 'object',
			'properties'           => array_fill_keys( $keys, array( 'type' => 'string' ) ),
			'minProperties'        => 1,
			'additionalProperties' => false,
		);
	}

	/** Closed generic settings map. */
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

	/** Closed composed management response. */
	private function document_schema(): array {
		$link                           = $this->link_schema( true );
		$section                        = array(
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
					'items'    => $link,
				),
			),
			'required'             => array( 'id', 'section_title', 'links' ),
			'additionalProperties' => false,
		);
		$normalized_section             = $section;
		$normalized_section['required'] = array( 'section_title', 'links' );
		$social                         = array(
			'type'                 => 'object',
			'properties'           => array(
				'type' => array( 'type' => 'string' ),
				'url'  => array( 'type' => 'string' ),
			),
			'required'             => array( 'type', 'url' ),
			'additionalProperties' => false,
		);
		$source                         = array(
			'type'                 => 'object',
			'properties'           => array_fill_keys( array( 'taxonomy', 'version', 'refreshed_at', 'public_url' ), array( 'type' => 'string' ) ) + array(
				'blog_id'          => array( 'type' => 'integer' ),
				'promoter_term_id' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'blog_id', 'taxonomy', 'promoter_term_id', 'version', 'refreshed_at', 'public_url' ),
			'additionalProperties' => false,
		);
		$snapshot                       = array(
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
				'entity_type'     => array(
					'type' => 'string',
					'enum' => array( 'Organization' ),
				),
				'source'          => $source,
			),
			'required'             => array( 'version', 'owner_reference', 'title', 'description', 'image_url', 'image_alt', 'website', 'social_links', 'entity_type', 'source' ),
			'additionalProperties' => false,
		);
		$styles                         = $this->styles_schema();
		$settings                       = $this->settings_schema();
		unset( $styles['minProperties'] );
		$settings['properties']['overlay_enabled']     = array( 'type' => 'boolean' );
		$settings['properties']['background_image_id'] = array(
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
		$settings['required']                          = array_keys( $settings['properties'] );
		unset( $settings['minProperties'] );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'created'   => array( 'type' => 'boolean' ),
				'promoter'  => array(
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
						'css_vars'             => $styles,
						'links'                => array(
							'type'     => 'array',
							'maxItems' => self::MAX_LINK_ITEMS,
							'items'    => array( 'oneOf' => array( $section, $link ) ),
						),
						'link_sections'        => array(
							'type'     => 'array',
							'maxItems' => self::MAX_LINK_SECTIONS,
							'items'    => $normalized_section,
						),
						'bio'                  => array( 'type' => 'string' ),
						'settings'             => $settings,
						'background_image_id'  => array( 'type' => 'integer' ),
						'background_image_url' => array( 'type' => 'string' ),
						'public_url'           => array( 'type' => 'string' ),
						'revision'             => $this->revision_schema(),
					),
					'required'             => array( 'link_page_id', 'css_vars', 'links', 'link_sections', 'bio', 'settings', 'background_image_id', 'background_image_url', 'public_url', 'revision' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'promoter', 'link_page' ),
			'additionalProperties' => false,
		);
	}

	/** Closed public discovery response with no rankings. */
	private function discovery_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'promoters' => array(
					'type'     => 'array',
					'maxItems' => 500,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array_fill_keys( array( 'name', 'slug', 'description', 'website', 'profile_url', 'link_page_url' ), array( 'type' => 'string' ) ) + array( 'promoter_term_id' => array( 'type' => 'integer' ) ),
						'required'             => array( 'promoter_term_id', 'name', 'slug', 'description', 'website', 'profile_url', 'link_page_url' ),
						'additionalProperties' => false,
					),
				),
				'count'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 500,
				),
			),
			'required'             => array( 'promoters', 'count' ),
			'additionalProperties' => false,
		);
	}

	/** Closed established analytics projection. */
	private function analytics_schema(): array {
		$dataset = array(
			'type'                 => 'object',
			'properties'           => array(
				'label' => array( 'type' => 'string' ),
				'data'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
			'required'             => array( 'label', 'data' ),
			'additionalProperties' => false,
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
							'items' => $dataset,
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

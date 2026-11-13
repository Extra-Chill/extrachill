<?php
/**
 * Closed venue and promoter Link Page ability contracts.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

defined( 'ABSPATH' ) || exit;

/** Registers one owner kind's management family (plus promoter discovery). */
final class TeamTermLinkPageAbilities {
	public const MAX_LINK_SECTIONS     = 10;
	public const MAX_LINKS_PER_SECTION = 25;
	public const MAX_LINK_ITEMS        = 250;
	public const MAX_ID_LENGTH         = 100;
	public const MAX_TITLE_LENGTH      = 200;
	public const MAX_LINK_TEXT_LENGTH  = 200;
	public const MAX_URL_LENGTH        = 2048;
	public const MAX_EXPIRATION_LENGTH = 64;

	/**
	 * Owner kinds whose registration hook is attached in this request.
	 *
	 * @var array<string, bool>
	 */
	private static $registered = array();

	/** @var string venue or promoter. */
	private $kind;

	/** @var class-string<\ExtraChillEvents\Core\TeamTermLinkPages> Owner definition class. */
	private $owner;

	/** @var string Input key carrying the owner term ID. */
	private $id_key;

	/**
	 * Attach registration once per owner kind.
	 *
	 * @param string $kind venue or promoter.
	 */
	public function __construct( string $kind ) {
		$this->kind   = $kind;
		$this->owner  = 'venue' === $kind ? \ExtraChillEvents\Core\VenueLinkPages::class : \ExtraChillEvents\Core\PromoterLinkPages::class;
		$this->id_key = $kind . '_term_id';
		if ( empty( self::$registered[ $kind ] ) ) {
			self::$registered[ $kind ] = true;
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
		}
	}

	/** Literal translatable copy for each owner kind. */
	private function labels(): array {
		if ( 'venue' === $this->kind ) {
			return array(
				'provision'         => __( 'Provision Venue Link Page', 'extrachill-events' ),
				'get'               => __( 'Get Venue Link Page', 'extrachill-events' ),
				'links'             => __( 'Save Venue Link Page Links', 'extrachill-events' ),
				'styles'            => __( 'Save Venue Link Page Styles', 'extrachill-events' ),
				'settings'          => __( 'Save Venue Link Page Settings', 'extrachill-events' ),
				'patch'             => __( 'Patch Venue Link Page', 'extrachill-events' ),
				'patch_description' => __( 'Atomically patch changed venue Link Page areas.', 'extrachill-events' ),
				'refresh'           => __( 'Refresh Venue Link Page Snapshot', 'extrachill-events' ),
				'analytics'         => __( 'Get Venue Link Page Analytics', 'extrachill-events' ),
			);
		}
		return array(
			'provision'         => __( 'Provision Promoter Link Page', 'extrachill-events' ),
			'get'               => __( 'Get Promoter Link Page', 'extrachill-events' ),
			'links'             => __( 'Save Promoter Link Page Links', 'extrachill-events' ),
			'styles'            => __( 'Save Promoter Link Page Styles', 'extrachill-events' ),
			'settings'          => __( 'Save Promoter Link Page Settings', 'extrachill-events' ),
			'patch'             => __( 'Patch Promoter Link Page', 'extrachill-events' ),
			'patch_description' => __( 'Atomically patch changed promoter Link Page areas.', 'extrachill-events' ),
			'refresh'           => __( 'Refresh Promoter Link Page Snapshot', 'extrachill-events' ),
			'analytics'         => __( 'Get Promoter Link Page Analytics', 'extrachill-events' ),
		);
	}

	/** Register eight management operations, plus promoter discovery. */
	public function register(): void {
		$labels = $this->labels();
		$prefix = 'extrachill/%s-' . $this->kind . '-link-page%s';
		$this->register_management( sprintf( $prefix, 'provision', '' ), $labels['provision'], array(), array( $this, 'provision' ), false, true );
		$this->register_management( sprintf( $prefix, 'get', '' ), $labels['get'], array(), array( $this, 'get' ), true, true );
		$this->register_management(
			sprintf( $prefix, 'save', '-links' ),
			$labels['links'],
			array(
				'links'             => $this->links_input_schema(),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_links' ),
			false,
			false
		);
		$this->register_management(
			sprintf( $prefix, 'save', '-styles' ),
			$labels['styles'],
			array(
				'css_vars'          => $this->styles_schema(),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_styles' ),
			false,
			false
		);
		$this->register_management(
			sprintf( $prefix, 'save', '-settings' ),
			$labels['settings'],
			array(
				'settings'          => $this->settings_schema(),
				'expected_revision' => $this->revision_schema(),
			),
			array( $this, 'save_settings' ),
			false,
			false
		);
		wp_register_ability(
			sprintf( $prefix, 'patch', '' ),
			array(
				'label'               => $labels['patch'],
				'description'         => $labels['patch_description'],
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						$this->id_key         => array(
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
					'required'             => array( $this->id_key, 'expected_revision' ),
					'minProperties'        => 3,
					'additionalProperties' => false,
				),
				'output_schema'       => $this->document_schema(),
				'execute_callback'    => array( $this, 'patch' ),
				'permission_callback' => array( $this, 'authorize' ),
				'meta'                => $this->meta( false, false ),
			)
		);
		$this->register_management( sprintf( $prefix, 'refresh', '-snapshot' ), $labels['refresh'], array(), array( $this, 'refresh' ), false, true );
		$this->register_management(
			sprintf( $prefix, 'get', '-analytics' ),
			$labels['analytics'],
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
		if ( 'promoter' !== $this->kind ) {
			return;
		}
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
				'meta'                => $this->meta( true, true ),
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
				$this->id_key => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			$properties
		);
		$required   = array( $this->id_key );
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
				'meta'                => $this->meta( $is_readonly, $idempotent ),
			)
		);
	}

	/**
	 * Deliberate core-runner exposure and annotations.
	 *
	 * @param bool $is_readonly Whether the operation is read-only.
	 * @param bool $idempotent  Whether the operation is idempotent.
	 */
	private function meta( bool $is_readonly, bool $idempotent ): array {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => $is_readonly,
				'idempotent'  => $idempotent,
				'destructive' => ! $is_readonly,
			),
		);
	}

	/**
	 * Authorize the exact active owner member.
	 *
	 * @param array $input Ability input.
	 */
	public function authorize( array $input ) {
		return $this->owner::authorize( $this->term_id( $input ) );
	}

	/** Public discovery needs no taxonomy or membership authority. */
	public function allow_discovery(): bool {
		return true;
	}

	/**
	 * Provision the owner's Link Page.
	 *
	 * @param array $input Ability input.
	 */
	public function provision( array $input ) {
		return $this->owner::provision( $this->term_id( $input ) );
	}

	/**
	 * Read the owner's Link Page.
	 *
	 * @param array $input Ability input.
	 */
	public function get( array $input ) {
		$reference = $this->owner::owner_reference( $this->term_id( $input ) );
		return is_wp_error( $reference ) ? $reference : ec_read_link_page( $reference );
	}

	/**
	 * Save bounded links.
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
	 * Save styles.
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
	 * Save settings.
	 *
	 * @param array $input Ability input.
	 */
	public function save_settings( array $input ) {
		return $this->save( $input, array_merge( $input['settings'], array( 'expected_revision' => $input['expected_revision'] ) ) );
	}

	/**
	 * Atomically save only supplied generic areas under one canonical lock.
	 *
	 * @param array $input Ability input.
	 */
	public function patch( array $input ) {
		if ( array_key_exists( 'links', $input ) ) {
			$valid = $this->validate_links( $input['links'] );
			if ( true !== $valid ) {
				return $valid;
			}
		}
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
	 * Refresh the owner snapshot.
	 *
	 * @param array $input Ability input.
	 */
	public function refresh( array $input ) {
		return $this->owner::refresh_snapshot( $this->term_id( $input ) );
	}

	/**
	 * Read Link Page analytics.
	 *
	 * @param array $input Ability input.
	 */
	public function analytics( array $input ) {
		return $this->owner::analytics( $this->term_id( $input ), absint( $input['date_range'] ?? 30 ), (string) ( $input['start_date'] ?? '' ), (string) ( $input['end_date'] ?? '' ) );
	}

	/** List approved promoters. */
	public function approved_promoters() {
		return \ExtraChillEvents\Core\PromoterLinkPages::approved_promoters();
	}

	/**
	 * Resolve the owner term ID from ability input.
	 *
	 * @param array $input Ability input.
	 */
	private function term_id( array $input ): int {
		return absint( $input[ $this->id_key ] ?? 0 );
	}

	/**
	 * Save through the standalone operation boundary, which reauthorizes.
	 *
	 * @param array $input Ability input.
	 * @param array $data  Bounded save data.
	 */
	private function save( array $input, array $data ) {
		$reference = $this->owner::owner_reference( $this->term_id( $input ) );
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
		return new \WP_Error( 'invalid_' . $this->kind . '_link_page_links', __( 'Link Page links exceed the supported limits or contain invalid values.', 'extrachill-events' ), array( 'status' => 400 ) );
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

	/** Kind-specific stored snapshot fields. */
	private function snapshot_extras_schema(): array {
		if ( 'venue' === $this->kind ) {
			$fields = array( 'address', 'city', 'state', 'zip', 'country' );
			return array(
				'location' => array(
					'type'                 => 'object',
					'properties'           => array_fill_keys( $fields, array( 'type' => 'string' ) ),
					'required'             => $fields,
					'additionalProperties' => false,
				),
			);
		}
		return array(
			'entity_type' => array(
				'type' => 'string',
				'enum' => array( 'Organization' ),
			),
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
		$runtime_social                 = array(
			'type'                 => 'object',
			'properties'           => array_fill_keys( array( 'id', 'type', 'url', 'custom_label' ), array( 'type' => 'string' ) ),
			'required'             => array( 'id', 'type', 'url' ),
			'additionalProperties' => false,
		);
		$source                         = array(
			'type'                 => 'object',
			'properties'           => array_fill_keys( array( 'taxonomy', 'version', 'refreshed_at', 'public_url' ), array( 'type' => 'string' ) ) + array(
				'blog_id'     => array( 'type' => 'integer' ),
				$this->id_key => array( 'type' => 'integer' ),
			),
			'required'             => array( 'blog_id', 'taxonomy', $this->id_key, 'version', 'refreshed_at', 'public_url' ),
			'additionalProperties' => false,
		);
		$extras                         = $this->snapshot_extras_schema();
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
			) + $extras + array( 'source' => $source ),
			'required'             => array_merge( array( 'version', 'owner_reference', 'title', 'description', 'image_url', 'image_alt', 'website', 'social_links' ), array_keys( $extras ), array( 'source' ) ),
			'additionalProperties' => false,
		);
		$styles                         = $this->styles_schema();
		$settings                       = $this->settings_schema();
		unset( $styles['minProperties'] );
		$styles['properties']                            += array_fill_keys( array( '--link-page-background-gradient-middle', '--link-page-icon-color', '--link-page-image-blur', '--link-page-image-brightness', '--link-page-button-fill', '--link-page-button-shadow', '--link-page-button-finish' ), array( 'type' => 'string' ) );
		$settings['properties']['overlay_enabled']        = array( 'type' => 'boolean' );
		$settings['properties']['subscribe_display_mode'] = array(
			'type' => 'string',
			'enum' => array( 'icon_modal', 'inline_form', 'disabled' ),
		);
		$settings['properties']['subscribe_description']  = array( 'type' => 'string' );
		$settings['properties']['background_image_id']    = array(
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
		$settings['required']                             = array_keys( $settings['properties'] );
		unset( $settings['minProperties'] );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'created'   => array( 'type' => 'boolean' ),
				$this->kind => array(
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
						'link_page_id'           => array( 'type' => 'integer' ),
						'css_vars'               => $styles,
						'links'                  => array(
							'type'     => 'array',
							'maxItems' => self::MAX_LINK_ITEMS,
							'items'    => array( 'oneOf' => array( $section, $link ) ),
						),
						'link_sections'          => array(
							'type'     => 'array',
							'maxItems' => self::MAX_LINK_SECTIONS,
							'items'    => $normalized_section,
						),
						'bio'                    => array( 'type' => 'string' ),
						'display_title'          => array( 'type' => 'string' ),
						'display_title_is_owned' => array( 'type' => 'boolean' ),
						'profile_image_id'       => array( 'type' => 'integer' ),
						'profile_image_url'      => array( 'type' => 'string' ),
						'social_links'           => array(
							'type'  => 'array',
							'items' => $runtime_social,
						),
						'schema_entity'          => array(
							'type'                 => 'object',
							'properties'           => array(
								'type' => array( 'type' => 'string' ),
								'url'  => array( 'type' => 'string' ),
							),
							'required'             => array( 'type', 'url' ),
							'additionalProperties' => false,
						),
						'previous_links'         => array(
							'type'                 => array( 'object', 'null' ),
							'properties'           => array(
								'links'     => array( 'type' => 'array' ),
								'stored_at' => array( 'type' => 'string' ),
							),
							'required'             => array( 'links', 'stored_at' ),
							'additionalProperties' => false,
						),
						'settings'               => $settings,
						'background_image_id'    => array( 'type' => 'integer' ),
						'background_image_url'   => array( 'type' => 'string' ),
						'public_url'             => array( 'type' => 'string' ),
						'revision'               => $this->revision_schema(),
					),
					'required'             => array( 'link_page_id', 'css_vars', 'links', 'link_sections', 'bio', 'settings', 'background_image_id', 'background_image_url', 'public_url', 'revision' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( $this->kind, 'link_page' ),
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

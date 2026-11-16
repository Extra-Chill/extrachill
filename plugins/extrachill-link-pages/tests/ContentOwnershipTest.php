<?php
/**
 * Page-owned content, font emission, and render-from-storage tests (#37).
 *
 * @package ExtraChillLinkPages
 */

use ExtraChill\LinkPages\Owned_Link_Pages;
use PHPUnit\Framework\TestCase;

final class ContentOwnershipTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => 'ec_link_page', 'post_status' => 'publish', 'post_title' => 'Fallback Title', 'post_name' => 'owned-page' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'profile', 'post_status' => 'publish' );
		$this->assertTrue( ec_assign_link_page_owner( 40, 'post:4:profile:20' ) );
	}

	private function errorCode( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result->get_error_code();
	}

	public function test_display_title_falls_back_to_post_title_when_unowned(): void {
		$data = ec_read_link_page_persistence( 40 );
		$this->assertSame( 'Fallback Title', $data['display_title'] );
		$this->assertFalse( $data['display_title_is_owned'] );
		$this->assertSame( 0, $data['profile_image_id'] );
		$this->assertSame( '', $data['profile_image_url'] );
		$this->assertSame( array(), $data['social_links'] );
	}

	public function test_display_title_profile_image_and_social_links_are_page_owned(): void {
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'display_title'     => '<b>Owned Title</b>',
				'profile_image_id'  => 55,
				'social_links'      => array( array( 'type' => 'instagram', 'url' => 'https://instagram.com/x' ) ),
			)
		);
		$this->assertIsArray( $saved );
		$this->assertSame( 'Owned Title', get_post_meta( 40, '_link_page_display_title', true ) );
		// WordPress stores scalars as strings.
		$this->assertSame( '55', get_post_meta( 40, '_link_page_profile_image_id', true ) );
		$this->assertSame(
			array( array( 'id' => 'instagram-1', 'type' => 'instagram', 'url' => 'https://instagram.com/x' ) ),
			get_post_meta( 40, '_link_page_social_links', true )
		);

		$data = ec_read_link_page_persistence( 40 );
		$this->assertSame( 'Owned Title', $data['display_title'] );
		$this->assertTrue( $data['display_title_is_owned'] );
		$this->assertSame( 55, $data['profile_image_id'] );
		$this->assertSame( 'https://media.example/55.jpg', $data['profile_image_url'] );
		$this->assertSame( 'instagram', $data['social_links'][0]['type'] );
	}

	public function test_unknown_social_type_and_missing_url_are_rejected(): void {
		$this->assertSame(
			'invalid_link_page_social_type',
			$this->errorCode( ec_save_link_page_persistence( 40, array( 'social_links' => array( array( 'type' => 'myspace', 'url' => 'https://myspace.com/x' ) ) ) ) )
		);
		$this->assertSame(
			'invalid_link_page_social_url',
			$this->errorCode( ec_save_link_page_persistence( 40, array( 'social_links' => array( array( 'type' => 'website', 'url' => '' ) ) ) ) )
		);
		$this->assertSame( '', get_post_meta( 40, '_link_page_social_links', true ) );
	}

	public function test_render_readiness_succeeds_with_zero_projection_providers_when_page_owns_content(): void {
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'display_title'    => 'Owned Title',
				'profile_image_id' => 55,
				'social_links'     => array( array( 'type' => 'website', 'url' => 'https://example.com' ) ),
				'css_vars'         => array( '--link-page-title-font-family' => 'Loft Sans' ),
				'links'            => array( array( 'id' => 'new-1', 'section_title' => 'Listen', 'links' => array( array( 'id' => '', 'link_text' => 'Song', 'link_url' => 'https://example.com/song' ) ) ) ),
			)
		);
		$this->assertIsArray( $saved );
		$this->assertTrue( ec_link_page_render_readiness( 40 ) );

		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertFalse( is_wp_error( $projection ) );
		$this->assertSame( 'Owned Title', $projection['display_title'] );
		$this->assertSame( 'https://media.example/55.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'website', $projection['social_links'][0]['type'] );
		$this->assertIsCallable( $projection['social_renderer'] );

		$data = ec_read_link_page_persistence( 40 );
		$prepared = ec_prepare_link_page_public_render( $projection, $data );
		$this->assertFalse( is_wp_error( $prepared ) );
		ob_start();
		ec_render_link_page_public_head( 40, $data, $prepared );
		$head = ob_get_clean();
		$this->assertStringContainsString( '<title>Owned Title</title>', $head );

		$body = ec_render_link_page_section( $data['link_sections'][0], 40, false );
		$this->assertStringContainsString( 'Song', $body );
	}

	public function test_existing_page_without_owned_content_still_renders_via_deprecated_fallback(): void {
		// Mirrors an unmigrated artist page today: nothing is page-owned
		// yet, so a live provider still supplies everything and the page
		// must keep rendering exactly as it does now.
		ec_register_link_page_public_projection_provider(
			'fixture',
			static function () {
				return array(
					'display_title'   => 'Live Artist Name',
					'bio'             => 'Live bio',
					'profile_img_url' => 'https://media.example/live.jpg',
					'social_links'    => array( array( 'type' => 'spotify', 'url' => 'https://spotify.com/artist' ) ),
				);
			}
		);
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertFalse( is_wp_error( $projection ) );
		$this->assertSame( 'Live Artist Name', $projection['display_title'] );
		$this->assertSame( 'https://media.example/live.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'spotify', $projection['social_links'][0]['type'] );
	}

	public function test_owned_content_wins_over_a_still_registered_projection_provider(): void {
		$saved = ec_save_link_page_persistence(
			40,
			array(
				'display_title'    => 'Owned Title',
				'profile_image_id' => 55,
				'social_links'     => array( array( 'type' => 'website', 'url' => 'https://example.com' ) ),
			)
		);
		$this->assertIsArray( $saved );
		ec_register_link_page_public_projection_provider(
			'fixture',
			static function () {
				return array(
					'display_title'   => 'Live Artist Name',
					'profile_img_url' => 'https://media.example/live.jpg',
					'social_links'    => array( array( 'type' => 'spotify', 'url' => 'https://spotify.com/artist' ) ),
				);
			}
		);
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertSame( 'Owned Title', $projection['display_title'] );
		$this->assertSame( 'https://media.example/55.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'website', $projection['social_links'][0]['type'] );
	}

	public function test_unowned_display_title_still_prefers_a_live_projection_over_the_post_title_fallback(): void {
		ec_register_link_page_public_projection_provider(
			'fixture',
			static function () {
				return array( 'display_title' => 'Live Artist Name' );
			}
		);
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertSame( 'Live Artist Name', $projection['display_title'] );
	}

	public function test_font_catalog_stack_and_local_css_resolution(): void {
		$this->assertSame( "'Loft Sans', Helvetica, Arial, sans-serif", ec_link_page_font_stack( 'Loft Sans' ) );
		$this->assertSame( "'Custom Font', 'Helvetica', Arial, sans-serif", ec_link_page_font_stack( 'Custom Font' ) );
		$this->assertNull( ec_link_page_google_font_param( 'Loft Sans' ) );
		$this->assertSame( 'Roboto:wght@400;600;700', ec_link_page_google_font_param( 'Roboto' ) );
		$this->assertStringContainsString( 'family=Roboto', ec_link_page_google_fonts_url( array( 'Loft Sans', 'Roboto' ) ) );
		$this->assertSame( '', ec_link_page_google_fonts_url( array( 'Loft Sans' ) ) );

		// Portable: with no site answering the filter, the runtime emits no
		// local @font-face and assumes no theme. The font still renders
		// through its CSS fallback stack.
		$this->assertSame( '', ec_link_page_local_font_face_url( 'Loft Sans' ) );
		$this->assertSame( '', ec_link_page_local_fonts_css( array( 'Loft Sans', 'Roboto' ) ) );

		// A site that hosts the face supplies its location through the filter.
		add_filter(
			'ec_link_page_local_font_face_url',
			static function ( $url, $font_value ) {
				return 'Loft Sans' === $font_value ? 'https://fonts.example.test/LoftSans' : $url;
			},
			10,
			2
		);
		$css = ec_link_page_local_fonts_css( array( 'Loft Sans', 'Roboto' ) );
		$this->assertStringContainsString( "@font-face{font-family:'Loft Sans'", $css );
		$this->assertStringContainsString( 'https://fonts.example.test/LoftSans.woff2', $css );
		$this->assertStringNotContainsString( 'Roboto', $css );

		// A resolved stack (from an owner projection override) round-trips
		// unchanged instead of being re-wrapped.
		$this->assertSame( "'Loft Sans', Helvetica, Arial, sans-serif", ec_link_page_font_stack( "'Loft Sans', Helvetica, Arial, sans-serif" ) );
	}

	public function test_font_enqueue_emits_google_url_and_local_font_face_for_owned_fonts(): void {
		ec_save_link_page_persistence(
			40,
			array(
				'css_vars' => array(
					'--link-page-title-font-family' => 'Loft Sans',
					'--link-page-body-font-family'  => 'Roboto',
				),
			)
		);
		// The site supplies the local face location (the runtime assumes no theme).
		add_filter(
			'ec_link_page_local_font_face_url',
			static function ( $url, $font_value ) {
				return 'Loft Sans' === $font_value ? 'https://fonts.example.test/LoftSans' : $url;
			},
			10,
			2
		);
		ec_enqueue_link_page_fonts( 40 );
		$this->assertContains( 'extrch-link-page-fonts', $GLOBALS['ec_test']['enqueued_styles'] );
		$this->assertStringContainsString( 'family=Roboto', $GLOBALS['ec_test']['registered_styles']['extrch-link-page-fonts']['src'] );
		$this->assertStringContainsString( "@font-face{font-family:'Loft Sans'", $GLOBALS['ec_test']['inline_styles']['extrch-link-page'][0] );
	}

	public function test_integer_fields_round_trip_as_strings_without_rollback(): void {
		// Regression: verification compared the stored "55" with int 55 and
		// rolled every integer write back.
		$saved = ec_save_link_page_persistence( 40, array( 'profile_image_id' => 55, 'background_image_id' => 77 ) );
		$this->assertIsArray( $saved );
		$this->assertSame( '55', get_post_meta( 40, '_link_page_profile_image_id', true ) );
		$this->assertSame( 55, ec_read_link_page_persistence( 40 )['profile_image_id'] );
		// Re-saving the same value is a no-op success, not a failure.
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'profile_image_id' => 55 ) ) );
	}

	public function test_meta_value_match_is_storage_aware(): void {
		$this->assertTrue( ec_link_page_meta_value_matches( '55', 55 ) );
		$this->assertTrue( ec_link_page_meta_value_matches( '1', true ) );
		$this->assertTrue( ec_link_page_meta_value_matches( '', false ) );
		$this->assertFalse( ec_link_page_meta_value_matches( '56', 55 ) );
		$this->assertTrue( ec_link_page_meta_value_matches( array( 'a' => 1 ), array( 'a' => 1 ) ) );
		$this->assertFalse( ec_link_page_meta_value_matches( array( 'a' => '1' ), array( 'a' => 1 ) ) );
	}

	public function test_title_suffix_comes_from_the_host_site(): void {
		add_filter( 'ec_link_page_title_suffix', static function () { return ' | host.example'; } );
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'display_title' => 'Owned Title' ) ) );
		$projection = ec_prepare_link_page_public_render( ec_get_link_page_public_projection( 40 ), ec_read_link_page_persistence( 40 ) );
		ob_start();
		ec_render_link_page_public_head( 40, ec_read_link_page_persistence( 40 ), $projection );
		$this->assertStringContainsString( '<title>Owned Title | host.example</title>', ob_get_clean() );
	}

	public function test_schema_entity_fields_are_page_owned_and_validated(): void {
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'schema_entity_type' => 'MusicGroup', 'schema_entity_url' => 'https://artist.example/band' ) ) );
		$data = ec_read_link_page_persistence( 40 );
		$this->assertSame( array( 'type' => 'MusicGroup', 'url' => 'https://artist.example/band' ), $data['schema_entity'] );
		$this->assertSame( 'invalid_link_page_schema_entity_type', ec_save_link_page_persistence( 40, array( 'schema_entity_type' => 'music group<script>' ) )->get_error_code() );
		$this->assertSame( 'invalid_link_page_schema_entity_url', ec_save_link_page_persistence( 40, array( 'schema_entity_url' => 'javascript:alert(1)' ) )->get_error_code() );
		$this->assertSame( 'MusicGroup', ec_read_link_page_persistence( 40 )['schema_entity']['type'] );
	}

	public function test_owned_schema_graph_is_built_without_an_owner_provider(): void {
		$this->assertIsArray(
			ec_save_link_page_persistence(
				40,
				array(
					'display_title'      => 'The Band',
					'bio'                => 'Loud.',
					'social_links'       => array( array( 'type' => 'instagram', 'url' => 'https://instagram.com/band' ) ),
					'schema_entity_type' => 'MusicGroup',
					'schema_entity_url'  => 'https://artist.example/band',
				)
			)
		);
		$projection = ec_get_link_page_public_projection( 40 );
		$graph      = $projection['seo']['schema'];
		$this->assertSame( 'MusicGroup', $graph[0]['@type'] );
		$this->assertSame( 'https://artist.example/band#musicgroup', $graph[0]['@id'] );
		$this->assertSame( 'The Band', $graph[0]['name'] );
		$this->assertSame( 'Loud.', $graph[0]['description'] );
		$this->assertSame( array( 'https://instagram.com/band' ), $graph[0]['sameAs'] );
		$this->assertSame( 'ProfilePage', $graph[1]['@type'] );
		$this->assertSame( array( '@id' => 'https://artist.example/band#musicgroup' ), $graph[1]['mainEntity'] );
	}

	public function test_no_schema_is_guessed_without_an_entity_type(): void {
		$this->assertSame( array(), ec_link_page_owned_schema_graph( ec_read_link_page_persistence( 40 ), 'https://host.example/page/' ) );
	}

	public function test_click_tracking_url_falls_back_to_the_site_integration(): void {
		$this->assertSame( '', ec_get_link_page_public_projection( 40 )['tracking_url'] );
		add_filter( 'ec_link_page_click_tracking_url', static function () { return 'https://api.example/click'; } );
		$this->assertSame( 'https://api.example/click', ec_get_link_page_public_projection( 40 )['tracking_url'] );
	}

	public function test_subscribe_is_off_without_a_host_endpoint(): void {
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertArrayNotHasKey( 'data-extrch-subscribe-api-url', $projection['body_attributes'] );
		$this->assertSame( array(), $projection['components']['after_links'] ?? array() );
	}

	public function test_host_subscribe_endpoint_attaches_modal_and_bell(): void {
		add_filter( 'ec_link_page_subscribe_url', static function ( $url, $id, $owner ) { return 'https://api.example/subscribe/' . $owner; }, 10, 3 );
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'display_title' => 'The Band' ) ) );
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertSame( 'https://api.example/subscribe/post:4:profile:20', $projection['body_attributes']['data-extrch-subscribe-api-url'] );
		$prepared = ec_prepare_link_page_public_render( $projection, ec_read_link_page_persistence( 40 ) );
		$this->assertStringContainsString( 'extrch-subscribe-icon-trigger', $prepared['_rendered_components']['header_actions'] );
		$this->assertStringContainsString( 'id="extrch-subscribe-modal"', $prepared['_rendered_components']['after_links'] );
		$this->assertStringContainsString( 'Subscribe to The Band', $prepared['_rendered_components']['after_links'] );
	}

	public function test_inline_and_disabled_modes_come_from_page_settings(): void {
		add_filter( 'ec_link_page_subscribe_url', static function () { return 'https://api.example/subscribe'; } );
		update_post_meta( 40, '_link_page_subscribe_display_mode', 'inline_form' );
		update_post_meta( 40, '_link_page_subscribe_description', 'News only.' );
		$prepared = ec_prepare_link_page_public_render( ec_get_link_page_public_projection( 40 ), ec_read_link_page_persistence( 40 ) );
		$this->assertStringContainsString( 'extrch-subscribe-inline-form-container', $prepared['_rendered_components']['after_links'] );
		$this->assertStringContainsString( 'News only.', $prepared['_rendered_components']['after_links'] );
		$this->assertStringNotContainsString( 'extrch-subscribe-icon-trigger', $prepared['_rendered_components']['header_actions'] );
		update_post_meta( 40, '_link_page_subscribe_display_mode', 'disabled' );
		$this->assertSame( array(), ec_get_link_page_public_projection( 40 )['components']['after_links'] ?? array() );
	}

	public function test_host_management_endpoints_set_edit_button_attributes(): void {
		$this->assertArrayNotHasKey( 'data-extrch-permissions-api-url', ec_get_link_page_public_projection( 40 )['body_attributes'] );
		add_filter( 'ec_link_page_management_endpoints', static function () { return array( 'permissions_url' => 'https://api.example/perm', 'handoff_url' => 'https://auth.example/handoff' ); } );
		$attributes = ec_get_link_page_public_projection( 40 )['body_attributes'];
		$this->assertSame( 'https://api.example/perm', $attributes['data-extrch-permissions-api-url'] );
		$this->assertSame( 'https://auth.example/handoff', $attributes['data-extrch-token-handoff-url'] );
	}

	/**
	 * Owned-page definition over the fixture `profile` owner, with optional
	 * projection extras.
	 *
	 * @param array $extras Projection extras the definition returns.
	 */
	private function ownedDefinition( array $extras = array() ): Owned_Link_Pages {
		static $definition = null;
		if ( null === $definition ) {
			$definition = new class() extends Owned_Link_Pages {
				public const OWNER_KIND        = 'post';
				public const KIND              = 'profile';
				public const SNAPSHOT_META_KEY = '_ec_test_profile_snapshot';
				public const ORPHAN_META_KEY   = '_ec_test_profile_orphan';
				protected const SCHEMA_TYPE    = 'Person';

				/**
				 * Projection extras for the current test.
				 *
				 * @var array
				 */
				public static $extras = array();

				protected static function owner_blog_id(): int {
					return 4;
				}

				protected static function authorize_owner( int $owner_id ) {
					unset( $owner_id );
					return true;
				}

				protected static function read_source( $owner ) {
					unset( $owner );
					return array();
				}

				public static function management_url( int $owner_id ): string {
					return 'https://manage.example/profile/' . $owner_id;
				}

				protected static function projection_extras( array $snapshot, int $link_page_id ): array {
					unset( $snapshot, $link_page_id );
					return self::$extras;
				}
			};
		}
		$definition::$extras = $extras;
		$snapshot            = $this->ownedEngine(
			$definition,
			'snapshot_from_source',
			array(
				array(
					'id'           => 20,
					'name'         => 'Owner Name',
					'slug'         => 'owner-name',
					'description'  => 'Owner description.',
					'website'      => 'https://owner.example',
					'image_url'    => 'https://owner.example/logo.jpg',
					'image_alt'    => 'Owner logo',
					'social_links' => array(
						array(
							'type' => 'instagram',
							'url'  => 'https://instagram.com/owner',
						),
					),
					'source_url'   => 'https://owner.example/profile',
				),
				'post:4:profile:20',
			)
		);
		update_post_meta( 40, $definition::SNAPSHOT_META_KEY, $snapshot );
		return $definition;
	}

	/**
	 * Call one private engine method with the definition bound as `static`.
	 *
	 * @param Owned_Link_Pages $definition Owned-page definition.
	 * @param string           $method     Private engine method.
	 * @param array            $arguments  Arguments.
	 */
	private function ownedEngine( Owned_Link_Pages $definition, string $method, array $arguments ) {
		$call = Closure::bind(
			static function () use ( $definition, $method, $arguments ) {
				return $definition::$method( ...$arguments );
			},
			null,
			Owned_Link_Pages::class
		);
		return $call();
	}

	/**
	 * Resolve the definition's live public projection for the fixture page.
	 *
	 * @param Owned_Link_Pages $definition Owned-page definition.
	 */
	private function ownedProjection( Owned_Link_Pages $definition ) {
		return $definition::public_projection_provider(
			array(
				'link_page_id'    => 40,
				'owner'           => ec_parse_link_page_owner_reference( 'post:4:profile:20' ),
				'owner_reference' => 'post:4:profile:20',
			)
		);
	}

	public function test_owned_page_bio_and_image_override_owner_snapshot(): void {
		$definition = $this->ownedDefinition();
		$saved      = ec_save_link_page_persistence(
			40,
			array(
				'bio'              => 'Page bio.',
				'profile_image_id' => 55,
			)
		);
		$this->assertIsArray( $saved );
		$projection = $this->ownedProjection( $definition );

		$this->assertSame( 'Page bio.', $projection['bio'] );
		$this->assertSame( 'https://media.example/55.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'Page bio.', $projection['seo']['description'] );
		$this->assertSame( 'https://media.example/55.jpg', $projection['seo']['image'] );
		// The owner supplies identity: title and schema entity stay the owner's.
		$entity = $projection['seo']['schema'][0];
		$this->assertSame( 'Owner Name', $projection['display_title'] );
		$this->assertSame( 'Owner Name', $entity['name'] );
		$this->assertSame( 'Owner description.', $entity['description'] );
		$this->assertSame( 'https://owner.example/logo.jpg', $entity['image'] );
		$this->assertSame( array( 'https://owner.example', 'https://instagram.com/owner' ), $entity['sameAs'] );
	}

	public function test_owned_page_without_bio_or_image_falls_back_to_owner_snapshot(): void {
		$projection = $this->ownedProjection( $this->ownedDefinition() );

		$this->assertSame( 'Owner description.', $projection['bio'] );
		$this->assertSame( 'https://owner.example/logo.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'Owner description.', $projection['seo']['description'] );
		$this->assertSame( 'https://owner.example/logo.jpg', $projection['seo']['image'] );
	}

	public function test_owned_page_persistence_error_falls_back_to_owner_snapshot(): void {
		$definition = $this->ownedDefinition();
		$snapshot   = get_post_meta( 40, $definition::SNAPSHOT_META_KEY, true );
		$this->assertInstanceOf( WP_Error::class, ec_read_link_page_persistence( 999 ) );

		$projection = $this->ownedEngine( $definition, 'projection_from_snapshot', array( $snapshot, 999 ) );

		$this->assertSame( 'Owner description.', $projection['bio'] );
		$this->assertSame( 'https://owner.example/logo.jpg', $projection['profile_img_url'] );
		$this->assertSame( 'Owner description.', $projection['seo']['description'] );
	}

	public function test_owned_projection_extras_merge_body_attributes(): void {
		$this->assertArrayNotHasKey( 'legacy_head_arguments', $this->ownedProjection( $this->ownedDefinition() ) );
		$definition = $this->ownedDefinition(
			array(
				'body_attributes'       => array(
					'data-extrch-owner-type' => 'spoofed',
					'data-extrch-extra'      => 'extra',
				),
				'legacy_head_arguments' => array( 'extra-head' ),
			)
		);
		$projection = $this->ownedProjection( $definition );

		$this->assertSame(
			array(
				'data-extrch-owner-type' => 'profile',
				'data-extrch-extra'      => 'extra',
				'data-extrch-profile-id' => '20',
			),
			$projection['body_attributes']
		);
		$this->assertSame( array( 'extra-head' ), $projection['legacy_head_arguments'] );
		$this->assertFalse( is_wp_error( ec_validate_link_page_public_projection( $projection ) ) );
	}

	public function test_owned_socials_render_through_runtime_icon_renderer_at_page_position(): void {
		$definition = $this->ownedDefinition();
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'social_icons_position' => 'below' ) ) );
		$this->assertTrue( ec_register_link_page_public_projection_provider( 'ec-test-owned-profile', array( $definition, 'public_projection_provider' ) ) );

		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertFalse( is_wp_error( $projection ) );
		$this->assertSame( 'ec_render_link_page_social_links', $projection['social_renderer'] );

		$prepared = ec_prepare_link_page_public_render( $projection, ec_read_link_page_persistence( 40 ) );
		$this->assertFalse( is_wp_error( $prepared ) );
		$this->assertSame( '', $prepared['_rendered_social_above'] );
		$this->assertSame( ec_render_link_page_social_links( $projection['social_links'], 'below' ), $prepared['_rendered_social_below'] );
		$this->assertStringContainsString( 'extrch-socials-below', $prepared['_rendered_social_below'] );
		$this->assertStringContainsString( 'extrch-social-icon', $prepared['_rendered_social_below'] );
		$this->assertFalse( method_exists( Owned_Link_Pages::class, 'render_social_links' ) );
	}

	/**
	 * Definition that reads its source live and opts into the owner
	 * visibility hook.
	 *
	 * @param bool $visible Whether the snapshot reports a public owner.
	 */
	private function liveDefinition( bool $visible ): Owned_Link_Pages {
		static $definition = null;
		if ( null === $definition ) {
			$definition = new class() extends Owned_Link_Pages {
				public const OWNER_KIND        = 'post';
				public const KIND              = 'profile';
				public const SNAPSHOT_META_KEY = '_ec_test_live_profile_snapshot';
				public const ORPHAN_META_KEY   = '_ec_test_live_profile_orphan';
				protected const SCHEMA_TYPE    = 'Person';

				/**
				 * Whether the owner is public.
				 *
				 * @var bool
				 */
				public static $visible = true;

				/**
				 * Canonical source description.
				 *
				 * @var string
				 */
				public static $description = 'Live description.';

				/**
				 * Owner page publishing the schema entity, or '' for the Link Page.
				 *
				 * @var string
				 */
				public static $entity_url = '';

				protected static function owner_blog_id(): int {
					return 4;
				}

				protected static function authorize_owner( int $owner_id ) {
					unset( $owner_id );
					return true;
				}

				protected static function read_source( $owner ) {
					return array(
						'id'           => (int) $owner->ID,
						'name'         => 'Live Owner',
						'slug'         => 'live-owner',
						'description'  => self::$description,
						'website'      => '',
						'image_url'    => '',
						'image_alt'    => '',
						'social_links' => array(),
						'source_url'   => 'https://owner.example/live',
					);
				}

				public static function management_url( int $owner_id ): string {
					return 'https://manage.example/profile/' . $owner_id;
				}

				protected static function snapshot_is_public( array $snapshot ): bool {
					unset( $snapshot );
					return self::$visible;
				}

				protected static function schema_entity_node( array $snapshot, string $canonical ): array {
					if ( '' === self::$entity_url ) {
						return parent::schema_entity_node( $snapshot, $canonical );
					}
					return array(
						'@id' => self::$entity_url . '#person',
						'url' => self::$entity_url,
					);
				}
			};
		}
		$definition::$visible     = $visible;
		$definition::$description = 'Live description.';
		$definition::$entity_url  = '';
		return $definition;
	}

	/**
	 * Store the snapshot the definition builds from its live source read.
	 *
	 * @param Owned_Link_Pages $definition Owned-page definition.
	 */
	private function storeLiveSnapshot( Owned_Link_Pages $definition ): Owned_Link_Pages {
		$snapshot = $this->ownedEngine( $definition, 'build_snapshot', array( 20, 'post:4:profile:20' ) );
		$this->assertIsArray( $snapshot );
		update_post_meta( 40, $definition::SNAPSHOT_META_KEY, $snapshot );
		return $definition;
	}

	public function test_missing_snapshot_fails_closed(): void {
		$definition = $this->liveDefinition( true );

		$this->assertSame( 'profile_link_page_snapshot_invalid', $this->errorCode( $this->ownedProjection( $definition ) ) );
		// Projection never writes: snapshots are created by provisioning and refreshes.
		$this->assertFalse( metadata_exists( 'post', 40, $definition::SNAPSHOT_META_KEY ) );
	}

	public function test_corrupt_snapshot_fails_closed(): void {
		$definition = $this->liveDefinition( true );
		update_post_meta( 40, $definition::SNAPSHOT_META_KEY, array( 'version' => 1 ) );

		$this->assertSame( 'profile_link_page_snapshot_invalid', $this->errorCode( $this->ownedProjection( $definition ) ) );
	}

	public function test_snapshot_reporting_a_non_public_owner_is_unavailable(): void {
		$result = $this->ownedProjection( $this->storeLiveSnapshot( $this->liveDefinition( false ) ) );

		$this->assertSame( 'link_page_public_owner_unavailable', $this->errorCode( $result ) );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_schema_entity_defaults_to_the_link_page_and_may_name_the_owner_page(): void {
		$definition = $this->storeLiveSnapshot( $this->liveDefinition( true ) );
		$canonical  = ec_get_link_page_public_url( 40 );
		$graph      = $this->ownedProjection( $definition )['seo']['schema'];

		$this->assertSame( $canonical . '#profile', $graph[0]['@id'] );
		$this->assertSame( $canonical, $graph[0]['url'] );
		$this->assertSame( array( '@id' => $canonical . '#profile' ), $graph[1]['mainEntity'] );

		$definition::$entity_url = 'https://owner.example/live';
		$graph                   = $this->ownedProjection( $definition )['seo']['schema'];
		$this->assertSame( 'https://owner.example/live#person', $graph[0]['@id'] );
		$this->assertSame( 'https://owner.example/live', $graph[0]['url'] );
		$this->assertSame( array( '@id' => 'https://owner.example/live#person' ), $graph[1]['mainEntity'] );
		$this->assertSame( $canonical . '#profilepage', $graph[1]['@id'] );
	}

	public function test_long_source_description_fits_the_stored_schema_limit(): void {
		$definition               = $this->liveDefinition( true );
		$definition::$description = str_repeat( 'Dénouement ', 400 );
		$this->storeLiveSnapshot( $definition );
		$projection  = $this->ownedProjection( $definition );
		$description = $projection['seo']['schema'][0]['description'];

		$this->assertLessThanOrEqual( 2048, strlen( $description ) );
		$this->assertStringEndsWith( 'Dénouement...', $description );
		$this->assertTrue( ec_validate_link_page_public_schema_value( $projection['seo']['schema'] ) );
		// The page-facing bio is not shortened.
		$this->assertSame( trim( $definition::$description ), $projection['bio'] );
	}
}

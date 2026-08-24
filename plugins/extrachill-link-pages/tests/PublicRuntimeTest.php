<?php

use PHPUnit\Framework\TestCase;

final class PublicRuntimeTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Legacy Page', 'post_name' => 'legacy-page' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'profile', 'post_status' => 'publish', 'post_title' => 'Legacy Owner', 'post_name' => 'legacy-owner' );
		$GLOBALS['ec_test']['blogs'][7]['terms'][30] = (object) array( 'term_id' => 30, 'taxonomy' => 'place' );
		$GLOBALS['wp_query'] = (object) array( 'posts' => array(), 'query_vars' => array(), 'is_404' => true );
		unset( $_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'], $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'] );
	}

	private function errorCode( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result->get_error_code();
	}

	private function assignPostOwner(): void {
		$this->assertTrue( ec_assign_link_page_owner( 40, 'post:4:profile:20' ) );
	}

	public function test_generic_persistence_preserves_defaults_ids_and_does_not_write_on_read(): void {
		$this->assignPostOwner();
		$before = $GLOBALS['ec_test']['blogs'][4]['post_meta'];
		$data = ec_read_link_page_persistence( 40 );
		$this->assertSame( '#121212', $data['css_vars']['--link-page-background-color'] );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['post_meta'] );

		$invalid = ec_save_link_page_persistence( 40, array( 'css_vars' => array( '--bad' => 'x' ) ) );
		$this->assertSame( 'unsupported_link_page_css_var', $this->errorCode( $invalid ) );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['post_meta'] );

		$saved = ec_save_link_page_persistence( 40, array(
			'bio' => '<b>Short</b>',
			'links' => array( array( 'id' => 'new-1', 'section_title' => '<b>Listen</b>', 'links' => array( array( 'id' => '', 'link_text' => '<i>Song</i>', 'link_url' => 'https://example.com/song' ) ) ) ),
			'css_vars' => array( '--link-page-text-color' => '#ABC', '--link-page-card-bg-color' => '#fff' ),
			'redirect_enabled' => true,
			'redirect_target_url' => 'https://example.com/live',
			'background_image_id' => 55,
		) );
		$this->assertSame( '40-section-1', $saved['links'][0]['id'] );
		$this->assertSame( '40-link-1', $saved['links'][0]['links'][0]['id'] );
		$this->assertSame( 'Short', $saved['bio'] );
		$this->assertArrayNotHasKey( '--bad', get_post_meta( 40, '_link_page_custom_css_vars', true ) );
		$this->assertSame( 'https://media.example/55.jpg', $saved['background_image_url'] );
		$this->assertCount( 1, array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'ec_link_page_persistence_saved' === $action[0] && 40 === $action[1][0]; } ) );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'ec_link_page_save' === $action[0]; } ) );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
	}

	public function test_owner_neutral_creation_detects_slug_and_owner_collisions_and_compensates(): void {
		$this->assignPostOwner();
		$this->assertSame( 40, ec_create_owned_link_page( 'post:4:profile:20', 'Existing', 'unused' ) );
		$this->assertSame( 'link_page_slug_conflict', $this->errorCode( ec_create_owned_link_page( 'term:7:place:30', 'Place', 'legacy-page' ) ) );
		$GLOBALS['ec_test']['mutate_insert_slug'] = true;
		$this->assertSame( 'link_page_slug_conflict', $this->errorCode( ec_create_owned_link_page( 'term:7:place:30', 'Place', 'place' ) ) );
		$this->assertCount( 2, $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_owner_locked_provisioning_reports_winner_without_breaking_integer_wrapper(): void {
		$first = ec_provision_owned_link_page( 'term:7:place:30', 'Place', 'place' );
		$this->assertTrue( $first['created'] );
		$this->assertIsInt( $first['link_page_id'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['lock_acquires'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['lock_releases'] );
		$second = ec_provision_owned_link_page( 'term:7:place:30', 'Ignored', 'other' );
		$this->assertFalse( $second['created'] );
		$this->assertSame( $first['link_page_id'], $second['link_page_id'] );
		$this->assertSame( 2, $GLOBALS['ec_test']['lock_acquires'] );
		$this->assertSame( 2, $GLOBALS['ec_test']['lock_releases'] );
		$this->assertSame( $first['link_page_id'], ec_create_owned_link_page( 'term:7:place:30', 'Ignored', 'other' ) );
	}

	public function test_composed_save_finalizes_under_exact_lock_before_one_success_hook(): void {
		update_post_meta( 40, '_link_page_bio_text', 'Before' );
		$result = ec_save_link_page_persistence_composed(
			40,
			array( 'bio' => 'After' ),
			static function ( $link_page_id, $persistence ) {
				$GLOBALS['ec_test']['finalizer_lock'] = array(
					$GLOBALS['ec_test']['advisory_lock_held'],
					$GLOBALS['ec_link_page_lock_scope']['link_page_id'] ?? 0,
				);
				do_action( 'ec_test_owner_finalized', $link_page_id, $persistence['bio'] );
				return true;
			}
		);

		$this->assertSame( 'After', $result['bio'] );
		$this->assertSame( array( true, 40 ), $GLOBALS['ec_test']['finalizer_lock'] );
		$hooks = array_values( array_filter( array_column( $GLOBALS['ec_test']['fired_actions'], 0 ), static function ( $hook ) { return in_array( $hook, array( 'ec_test_owner_finalized', 'ec_link_page_persistence_saved' ), true ); } ) );
		$this->assertSame( array( 'ec_test_owner_finalized', 'ec_link_page_persistence_saved' ), $hooks );
		$this->assertSame( 1, count( array_keys( $hooks, 'ec_link_page_persistence_saved', true ) ) );
	}

	public function test_composed_save_finalizer_failure_restores_generic_storage_without_success_hook(): void {
		update_post_meta( 40, '_link_page_bio_text', 'Before' );
		$result = ec_save_link_page_persistence_composed(
			40,
			array( 'bio' => 'After' ),
			static function () { return new WP_Error( 'owner_finalization_failed', 'Failed.' ); }
		);

		$this->assertSame( 'owner_finalization_failed', $this->errorCode( $result ) );
		$this->assertSame( 'Before', get_post_meta( 40, '_link_page_bio_text', true ) );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'] ?? array(), static function ( $action ) { return 'ec_link_page_persistence_saved' === $action[0]; } ) );
	}

	public function test_composed_save_reports_compensation_failure_with_finalizer_cause(): void {
		update_post_meta( 40, '_link_page_bio_text', 'Before' );
		$GLOBALS['ec_test']['meta_write_calls']     = 0;
		$GLOBALS['ec_test']['fail_meta_write_calls'] = array( 2 );
		$result = ec_save_link_page_persistence_composed(
			40,
			array( 'bio' => 'After' ),
			static function () { return new WP_Error( 'owner_finalization_failed', 'Failed.' ); }
		);

		$this->assertSame( 'link_page_save_compensation_failed', $this->errorCode( $result ) );
		$this->assertSame( 'owner_finalization_failed', $result->get_error_data()['cause'] );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'] ?? array(), static function ( $action ) { return 'ec_link_page_persistence_saved' === $action[0]; } ) );
	}

	public function test_composed_provision_finalizes_under_page_lock_before_one_creation_hook(): void {
		$result = ec_provision_owned_link_page_composed(
			'term:7:place:30',
			'Place',
			'place',
			static function ( $link_page_id, $owner_reference ) {
				$GLOBALS['ec_test']['finalizer_lock'] = array(
					$GLOBALS['ec_test']['advisory_lock_held'],
					$GLOBALS['ec_link_page_lock_scope']['link_page_id'] ?? 0,
				);
				do_action( 'ec_test_owner_finalized', $link_page_id, $owner_reference );
				return true;
			}
		);

		$this->assertTrue( $result['created'] );
		$this->assertSame( array( true, $result['link_page_id'] ), $GLOBALS['ec_test']['finalizer_lock'] );
		$this->assertSame( 2, $GLOBALS['ec_test']['lock_acquires'] );
		$this->assertSame( 2, $GLOBALS['ec_test']['lock_releases'] );
		$this->assertFalse( $GLOBALS['ec_test']['advisory_lock_held'] );
		$hooks = array_values( array_filter( array_column( $GLOBALS['ec_test']['fired_actions'], 0 ), static function ( $hook ) { return in_array( $hook, array( 'ec_test_owner_finalized', 'ec_owned_link_page_created' ), true ); } ) );
		$this->assertSame( array( 'ec_test_owner_finalized', 'ec_owned_link_page_created' ), $hooks );
		$this->assertSame( 1, count( array_keys( $hooks, 'ec_owned_link_page_created', true ) ) );
	}

	public function test_composed_existing_provision_finalizes_under_exact_lock_without_creation_hook(): void {
		$existing = ec_provision_owned_link_page( 'term:7:place:30', 'Place', 'place' );
		$GLOBALS['ec_test']['fired_actions'] = array();
		$GLOBALS['ec_test']['lock_acquires'] = 0;
		$GLOBALS['ec_test']['lock_releases'] = 0;
		$result = ec_provision_owned_link_page_composed(
			'term:7:place:30',
			'Ignored',
			'other',
			static function ( $link_page_id, $owner_reference ) {
				$GLOBALS['ec_test']['existing_finalizer'] = array(
					$link_page_id,
					$owner_reference,
					$GLOBALS['ec_test']['advisory_lock_held'],
					$GLOBALS['ec_link_page_lock_scope']['link_page_id'] ?? 0,
				);
				return true;
			}
		);

		$this->assertSame( array( 'link_page_id' => $existing['link_page_id'], 'created' => false ), $result );
		$this->assertSame( array( $existing['link_page_id'], 'term:7:place:30', true, $existing['link_page_id'] ), $GLOBALS['ec_test']['existing_finalizer'] );
		$this->assertSame( 2, $GLOBALS['ec_test']['lock_acquires'] );
		$this->assertSame( 2, $GLOBALS['ec_test']['lock_releases'] );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'ec_owned_link_page_created' === $action[0]; } ) );
	}

	public function test_composed_existing_provision_finalizer_failure_preserves_page(): void {
		$existing = ec_provision_owned_link_page( 'term:7:place:30', 'Place', 'place' );
		$before_posts = $GLOBALS['ec_test']['blogs'][4]['posts'];
		$before_meta  = $GLOBALS['ec_test']['blogs'][4]['post_meta'];
		$GLOBALS['ec_test']['fired_actions'] = array();
		$result = ec_provision_owned_link_page_composed(
			'term:7:place:30',
			'Ignored',
			'other',
			static function () { return new WP_Error( 'owner_finalization_failed', 'Failed.' ); }
		);

		$this->assertSame( 'owner_finalization_failed', $this->errorCode( $result ) );
		$this->assertSame( $before_posts, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertSame( $before_meta, $GLOBALS['ec_test']['blogs'][4]['post_meta'] );
		$this->assertSame( $existing['link_page_id'], ec_get_link_page_id_for_owner( 'term:7:place:30' ) );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'ec_owned_link_page_created' === $action[0]; } ) );
	}

	public function test_composed_existing_provision_finalizer_context_leak_fails_closed(): void {
		$existing = ec_provision_owned_link_page( 'term:7:place:30', 'Place', 'place' );
		$before = $GLOBALS['ec_test']['blogs'][4]['posts'];
		$result = ec_provision_owned_link_page_composed(
			'term:7:place:30',
			'Ignored',
			'other',
			static function () { switch_to_blog( 7 ); return true; }
		);

		$this->assertSame( 'link_page_mutation_finalizer_context_leak', $this->errorCode( $result ) );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertSame( $existing['link_page_id'], ec_get_link_page_id_for_owner( 'term:7:place:30' ) );
	}

	public function test_composed_provision_finalizer_failure_removes_page_without_creation_hook(): void {
		$before = $GLOBALS['ec_test']['blogs'][4]['posts'];
		$result = ec_provision_owned_link_page_composed(
			'term:7:place:30',
			'Place',
			'place',
			static function () { return new WP_Error( 'owner_finalization_failed', 'Failed.' ); }
		);

		$this->assertSame( 'owner_finalization_failed', $this->errorCode( $result ) );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'] ?? array(), static function ( $action ) { return 'ec_owned_link_page_created' === $action[0]; } ) );
	}

	public function test_failed_composed_force_provision_restores_replaced_page(): void {
		$this->assignPostOwner();
		$before = $GLOBALS['ec_test']['blogs'][4]['posts'];
		$result = ec_provision_owned_link_page_composed(
			'post:4:profile:20',
			'Replacement',
			'legacy-page',
			static function () { return new WP_Error( 'owner_finalization_failed', 'Failed.' ); },
			true
		);

		$this->assertSame( 'owner_finalization_failed', $this->errorCode( $result ) );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertSame( 'legacy-page', get_post_field( 'post_name', 40 ) );
		$this->assertSame( array( 'post:4:profile:20' ), ec_get_stored_link_page_owner_references( 40 ) );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'] ?? array(), static function ( $action ) { return 'ec_owned_link_page_created' === $action[0]; } ) );
	}

	public function test_composed_provision_reports_creation_compensation_failure(): void {
		$GLOBALS['ec_test']['fail_wp_delete_post'] = true;
		$result = ec_provision_owned_link_page_composed(
			'term:7:place:30',
			'Place',
			'place',
			static function () { return new WP_Error( 'owner_finalization_failed', 'Failed.' ); }
		);

		$this->assertSame( 'link_page_creation_compensation_failed', $this->errorCode( $result ) );
		$this->assertSame( 'owner_finalization_failed', $result->get_error_data()['cause'] );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'] ?? array(), static function ( $action ) { return 'ec_owned_link_page_created' === $action[0]; } ) );
	}

	public function test_composed_mutation_api_signatures_and_finalizer_validation_are_exact(): void {
		$signatures = array(
			'ec_save_link_page_persistence_composed' => array( 3, 3 ),
			'ec_provision_owned_link_page_composed'  => array( 4, 6 ),
		);
		foreach ( $signatures as $function => $expected ) {
			$reflection = new ReflectionFunction( $function );
			$this->assertSame( $expected, array( $reflection->getNumberOfRequiredParameters(), $reflection->getNumberOfParameters() ) );
		}
		$this->assertSame( 'invalid_link_page_mutation_finalizer', $this->errorCode( ec_save_link_page_persistence_composed( 40, array(), null ) ) );
		$this->assertSame( 'invalid_link_page_mutation_finalizer', $this->errorCode( ec_provision_owned_link_page_composed( 'term:7:place:30', 'Place', 'place', null ) ) );
	}

	public function test_owner_locked_precondition_denial_creates_nothing(): void {
		$before = $GLOBALS['ec_test']['blogs'][4]['posts'];
		$result = ec_provision_owned_link_page( 'term:7:place:30', 'Denied', 'denied', false, static function () { return new WP_Error( 'revoked', 'Revoked.' ); } );
		$this->assertSame( 'revoked', $result->get_error_code() );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['posts'] );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'] ?? array(), static function ( $action ) { return 'ec_owned_link_page_created' === $action[0]; } ) );
	}

	public function test_owner_locked_precondition_is_contained_and_fails_closed(): void {
		$cases = array(
			'link_page_provision_precondition_context_leak' => static function () { switch_to_blog( 7 ); return true; },
			'link_page_provision_precondition_exception' => static function () { throw new RuntimeException( 'failed' ); },
			'link_page_provision_precondition_invalid_result' => static function () { return 'yes'; },
		);
		foreach ( $cases as $code => $callback ) {
			$before = $GLOBALS['ec_test']['blogs'][4]['posts'];
			$result = ec_provision_owned_link_page( 'term:7:place:30', 'Denied', 'denied', false, $callback );
			$this->assertSame( $code, $result->get_error_code() );
			$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['posts'] );
			$this->assertSame( 4, get_current_blog_id() );
		}

		$before = $GLOBALS['ec_test']['blogs'][4]['posts'];
		$result = ec_provision_owned_link_page(
			'term:7:place:30',
			'Denied',
			'denied',
			false,
			static function () {
				switch_to_blog( 7 );
				$GLOBALS['ec_test']['blog_stack']       = array();
				$GLOBALS['_wp_switched_stack']          = array();
				$GLOBALS['ec_test']['fail_switch_to_blog'] = 4;
				return true;
			}
		);
		$this->assertSame( 'link_page_provision_precondition_restore_failed', $result->get_error_code() );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_stored_projection_renders_without_owner_provider_and_rejects_corruption(): void {
		$id = ec_create_owned_link_page( 'term:7:place:30', 'Stored Place', 'stored-place' );
		$record = ec_save_link_page_public_projection_snapshot(
			$id,
			'term:7:place:30',
			array(
				'display_title'   => 'Stored Place',
				'bio'             => "Public snapshot\nSecond line",
				'profile_img_url' => 'https://media.example/place.jpg',
				'social_links'    => array( array( 'type' => 'web', 'url' => 'https://place.example' ) ),
				'body_attributes' => array( 'data-owner-type' => 'place' ),
				'seo'             => array(
					'canonical'   => 'https://place.example/events/live%20music/',
					'description' => "Venue description\nSecond line",
					'schema'      => array( array( '@type' => 'Place', 'url' => 'https://place.example/events/live%20music/', 'description' => "Venue description\nSecond line" ) ),
				),
			)
		);
		$this->assertIsArray( $record );
		$projection = ec_get_link_page_public_projection( $id );
		$this->assertSame( 'Stored Place', $projection['display_title'] );
		$this->assertSame( "Public snapshot\nSecond line", $projection['bio'] );
		$this->assertSame( 'https://place.example/events/live%20music/', $projection['seo']['schema'][0]['url'] );
		$this->assertSame( "Venue description\nSecond line", $projection['seo']['schema'][0]['description'] );
		$this->assertSame( 'place', $projection['body_attributes']['data-owner-type'] );
		$this->assertIsCallable( $projection['social_renderer'] );

		$record['owner_checksum'] = str_repeat( '0', 64 );
		update_post_meta( $id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY, $record );
		$corrupt = ec_get_link_page_public_projection( $id );
		$this->assertSame( 'link_page_public_snapshot_corrupt', $corrupt->get_error_code() );
		$this->assertSame( 500, $corrupt->get_error_data()['status'] );
	}

	public function test_stored_schema_rejects_invalid_utf8_controls_and_oversize_values(): void {
		foreach ( array( "\xC3\x28", "unsafe\x01control", str_repeat( 'x', 2049 ) ) as $invalid ) {
			$this->assertFalse( ec_validate_link_page_public_schema_value( array( 'description' => $invalid ) ) );
		}
		$this->assertTrue( ec_validate_link_page_public_schema_value( array( 'description' => "Line one\nLine two", 'url' => 'https://example.com/live%20music/' ) ) );
	}

	public function test_force_creation_replaces_owner_and_preserves_requested_slug(): void {
		$this->assignPostOwner();
		$id = ec_create_owned_link_page( 'post:4:profile:20', 'Replacement', 'legacy-page', true );

		$this->assertIsInt( $id );
		$this->assertNotSame( 40, $id );
		$this->assertSame( 'legacy-page', get_post_field( 'post_name', $id ) );
		$this->assertSame( 'legacy-page-replaced-40', get_post_field( 'post_name', 40 ) );
		$this->assertSame( 'post:4:profile:20', ec_get_link_page_owner( $id )['reference'] );
		$this->assertSame( array(), ec_get_stored_link_page_owner_references( 40 ) );
	}

	public function test_term_owner_uses_same_create_read_save_and_public_projection_runtime(): void {
		$id = ec_create_owned_link_page( 'term:7:place:30', 'Term Page', 'term-page' );
		$this->assertIsInt( $id );
		$this->assertSame( 'term:7:place:30', ec_get_link_page_owner( $id )['reference'] );
		ec_register_link_page_public_projection_provider( 'term-fixture', static function ( $context ) {
			return 'term' === $context['owner']['kind'] ? array( 'display_title' => 'Term Page', 'bio' => 'Term bio', 'tracking_url' => 'https://api.example/click' ) : null;
		} );
		$projection = ec_get_link_page_public_projection( $id );
		$this->assertSame( 'Term Page', $projection['display_title'] );
		$this->assertSame( 'https://extrachill.link/term-page/', $projection['_context']['public_url'] );
		$this->assertIsArray( ec_save_link_page_persistence( $id, array( 'bio' => 'Saved' ) ) );
		switch_to_blog( 7 );
		unset( $GLOBALS['ec_test']['blogs'][7]['terms'][30] );
		restore_current_blog();
		$this->assertSame( 'invalid_link_page_owner_object', $this->errorCode( ec_get_link_page_public_projection( $id ) ) );
	}

	public function test_legacy_fixture_projection_preserves_public_contract_without_generic_branches(): void {
		$this->assignPostOwner();
		ec_register_link_page_public_projection_provider( 'legacy-fixture', static function ( $context ) {
			if ( 'post:4:profile:20' !== $context['owner_reference'] ) return null;
			return array(
				'display_title' => 'Legacy Owner',
				'bio' => 'Legacy bio',
				'profile_img_url' => 'https://media.example/profile.jpg',
				'body_attributes' => array( 'data-extrch-artist-id' => '20', 'data-extrch-permissions-api-url' => 'https://api.example/permissions' ),
				'social_links' => array( array( 'type' => 'web', 'url' => 'https://example.com' ) ),
				'social_renderer' => static function () { return '<div class="extrch-link-page-socials"></div>'; },
				'seo' => array( 'og_type' => 'profile' ),
				'tracking_url' => 'https://api.example/analytics/click',
				'css_vars' => array( '--link-page-title-font-family' => 'Artist Font' ),
			);
		} );
		$projection = ec_get_link_page_public_projection( 40 );
		$this->assertSame( 'Legacy Owner', $projection['display_title'] );
		$this->assertSame( '20', $projection['body_attributes']['data-extrch-artist-id'] );
		$this->assertSame( 'Artist Font', $projection['css_vars']['--link-page-title-font-family'] );
		$this->assertSame( 'https://extrachill.link/legacy-page/', ec_get_link_page_public_url( 40 ) );
		$html = ec_render_link_page_section( array( 'section_title' => 'Listen', 'links' => array( array( 'link_text' => 'Song', 'link_url' => 'https://youtube.com/watch?v=abcdefghijk' ) ) ), 40, true );
		$this->assertStringContainsString( 'extrch-link-button-wrapper', $html );
		$this->assertStringContainsString( 'extrch-share-item-trigger', $html );
		$this->assertStringContainsString( 'extrch-youtube-embed-link', $html );
	}

	public function test_projection_registry_is_deterministic_fail_closed_and_context_safe(): void {
		$this->assignPostOwner();
		ec_register_link_page_public_projection_provider( 'z', static function () { $GLOBALS['ec_test']['projection_order'][] = 'z'; return null; }, 20 );
		ec_register_link_page_public_projection_provider( 'a', static function () { $GLOBALS['ec_test']['projection_order'][] = 'a'; return array( 'display_title' => 'Page' ); }, 5 );
		$this->assertSame( 'Page', ec_get_link_page_public_projection( 40 )['display_title'] );
		$this->assertSame( array( 'a', 'z' ), $GLOBALS['ec_test']['projection_order'] );
		$this->assertFalse( method_exists( ec_link_page_public_projection_registry(), 'unregister' ) );

		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_name' => 'page' );
		$GLOBALS['ec_test']['blogs'][4]['posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'profile', 'post_status' => 'publish' );
		ec_assign_link_page_owner( 40, 'post:4:profile:20' );
		ec_register_link_page_public_projection_provider( 'leak', static function () { switch_to_blog( 7 ); return array( 'display_title' => 'Unsafe' ); } );
		$this->assertSame( 'link_page_public_projection_provider_context_leak', $this->errorCode( ec_get_link_page_public_projection( 40 ) ) );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_live_projection_provider_preserves_explicit_404_status(): void {
		$this->assignPostOwner();
		ec_register_link_page_public_projection_provider( 'missing-owner', static function () { return new WP_Error( 'public_owner_unavailable', 'Unavailable.', array( 'status' => 404 ) ); } );
		$result = ec_get_link_page_public_projection( 40 );
		$this->assertSame( 'public_owner_unavailable', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_public_routing_root_valid_unknown_extra_chill_www_and_head_contracts(): void {
		$_SERVER['HTTP_HOST'] = 'extrachill.link';
		$_SERVER['SERVER_NAME'] = 'extrachill.link';
		$_SERVER['REQUEST_URI'] = '/legacy-page/';
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		ec_resolve_link_page_public_query();
		$this->assertSame( 200, $GLOBALS['ec_test']['status'] );
		$this->assertSame( 40, $GLOBALS['wp_query']->queried_object_id );
		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
		$this->assertFalse( ec_prevent_link_page_public_canonical_redirect( 'redirect', 'request' ) );

		$GLOBALS['ec_test']['blogs'][4]['posts'][40]->post_name = 'extra-chill';
		$GLOBALS['wp_query'] = (object) array( 'posts' => array(), 'query_vars' => array(), 'is_404' => true );
		$_SERVER['REQUEST_URI'] = '/';
		ec_resolve_link_page_public_query();
		$this->assertSame( 40, $GLOBALS['wp_query']->queried_object_id );
		$GLOBALS['ec_test']['blogs'][4]['posts'][40]->post_name = 'legacy-page';

		$GLOBALS['wp_query'] = (object) array( 'posts' => array(), 'query_vars' => array(), 'is_404' => true );
		$_SERVER['HTTP_HOST'] = 'www.extrachill.link';
		$_SERVER['REQUEST_URI'] = '/missing/';
		ec_resolve_link_page_public_query();
		$this->assertSame( array( 'https://extrachill.link/', 301, false ), $GLOBALS['ec_test']['redirect'] );

		unset( $GLOBALS['ec_test']['redirect'] );
		$_SERVER['REQUEST_URI'] = '/extra-chill/';
		ec_resolve_link_page_public_query();
		$this->assertSame( array( 'https://extrachill.link/', 301, false ), $GLOBALS['ec_test']['redirect'] );
	}

	public function test_successful_redirect_invokes_production_termination_seam(): void {
		$this->assertTrue( ec_link_page_public_redirect( 'https://example.com/target', 302, true ) );
		$this->assertSame( array( 'https://example.com/target', 302 ), $GLOBALS['ec_test']['terminations'][0] );
		$this->assertSame( array( 'https://example.com/target', 302, true ), $GLOBALS['ec_test']['redirect'] );
	}

	public function test_failed_or_unsafe_redirect_never_terminates(): void {
		$unsafe = ec_link_page_public_redirect( 'ftp://example.com/file', 302 );
		$this->assertSame( 'invalid_link_page_redirect_url', $this->errorCode( $unsafe ) );
		$this->assertSame( array(), $GLOBALS['ec_test']['terminations'] ?? array() );

		$GLOBALS['ec_test']['fail_redirect'] = true;
		$failed = ec_link_page_public_redirect( 'https://example.com/target', 302 );
		$this->assertSame( 'link_page_redirect_failed', $this->errorCode( $failed ) );
		$this->assertSame( array(), $GLOBALS['ec_test']['terminations'] ?? array() );
	}

	public function test_component_and_social_callback_failures_propagate(): void {
		$projection = array(
			'_context' => array( 'owner' => array() ),
			'components' => array( 'before_header' => array( static function () { throw new RuntimeException( 'failed' ); } ) ),
			'social_renderer' => null,
			'social_links' => array(),
		);
		$result = ec_prepare_link_page_public_render( $projection, array( 'settings' => array( 'social_icons_position' => 'above' ) ) );
		$this->assertSame( 'link_page_public_projection_provider_exception', $this->errorCode( $result ) );

		$projection['components'] = array();
		$projection['social_links'] = array( array( 'type' => 'web', 'url' => 'https://example.com' ) );
		$projection['social_renderer'] = static function () { switch_to_blog( 7 ); return '<div></div>'; };
		$result = ec_prepare_link_page_public_render( $projection, array( 'settings' => array( 'social_icons_position' => 'above' ) ) );
		$this->assertSame( 'link_page_public_projection_provider_context_leak', $this->errorCode( $result ) );
	}

	public function test_deleted_canonical_owner_shape_carries_public_404_status(): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'][12714] = (object) array( 'ID' => 12714, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_name' => 'deleted-owner' );
		update_post_meta( 12714, EC_LINK_PAGE_OWNER_META_KEY, 'post:4:profile:12711' );
		$result = ec_get_link_page_public_projection( 12714 );
		$this->assertSame( 'invalid_link_page_owner_object', $this->errorCode( $result ) );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_join_exclusions_direct_cpt_temporary_redirect_cache_canonical_and_sitemap(): void {
		add_filter( 'ec_link_page_public_special_route', static function ( $route, $path ) { return 'join' === $path ? array( 'url' => 'https://owner.example/login/?from_join=true', 'status' => 301 ) : $route; }, 10, 2 );
		add_filter( 'ec_link_page_public_exclusions', static function ( $excluded ) { $excluded[] = 'owner-management'; return $excluded; } );
		$_SERVER['HTTP_HOST'] = 'extrachill.link';
		$_SERVER['REQUEST_URI'] = '/join/';
		ec_resolve_link_page_public_query();
		$this->assertSame( 'https://owner.example/login/?from_join=true', $GLOBALS['ec_test']['redirect'][0] );

		ec_register_link_page_public_rewrites();
		$this->assertStringContainsString( 'owner\-management', $GLOBALS['ec_test']['rewrite_rules'][0]['regex'] );
		$this->assertSame( array( 'https://extrachill.link/legacy-page/' ), ec_link_page_public_urls( 40 ) );
		$this->assertSame( ec_link_page_public_urls( 40 ), ec_link_page_cache_post_change_urls( null, 40, EC_LINK_PAGE_POST_TYPE ) );
		$this->assertSame( 'https://extrachill.link/legacy-page/', ec_link_page_sitemap_urls( array() )[0]['loc'] );

		$GLOBALS['ec_test']['singular'] = EC_LINK_PAGE_POST_TYPE;
		$GLOBALS['wp_query']->queried_object = get_post( 40 );
		$_SERVER['HTTP_HOST'] = 'owner.example';
		$_SERVER['QUERY_STRING'] = 'utm=1';
		ec_redirect_direct_link_page_request();
		$this->assertSame( array( 'https://extrachill.link/legacy-page/?utm=1', 301, true ), $GLOBALS['ec_test']['redirect'] );

		ec_save_link_page_persistence( 40, array( 'redirect_enabled' => true, 'redirect_target_url' => 'https://example.com/temporary' ) );
		ec_redirect_direct_link_page_request();
		$this->assertSame( array( 'https://example.com/temporary', 302, false ), $GLOBALS['ec_test']['redirect'] );
	}

	public function test_expiration_removes_only_expired_links_and_preserves_post_id_keys(): void {
		$this->assignPostOwner();
		$GLOBALS['ec_test']['now'] = strtotime( '2026-08-23 12:00:00 UTC' );
		ec_save_link_page_persistence( 40, array( 'link_expiration_enabled' => true, 'links' => array( array( 'id' => '40-section-1', 'section_title' => '', 'links' => array( array( 'id' => '40-link-1', 'link_text' => 'Old', 'link_url' => 'https://old.example', 'expires_at' => '2026-08-22' ), array( 'id' => '40-link-2', 'link_text' => 'New', 'link_url' => 'https://new.example', 'expires_at' => '2026-08-24' ) ) ) ) ) );
		ec_cleanup_expired_link_page_links();
		$links = get_post_meta( 40, '_link_page_links', true );
		$this->assertSame( '40-link-2', $links[0]['links'][0]['id'] );
		$this->assertArrayHasKey( 40, $GLOBALS['ec_test']['blogs'][4]['post_meta'] );
	}

	public function test_public_analytics_remains_browser_owned(): void {
		$this->assertFalse( function_exists( 'ec_record_link_page_public_view' ) );
		$source = file_get_contents( dirname( __DIR__ ) . '/templates/single-link-page.php' );
		$this->assertStringNotContainsString( 'view_recorded', $source );
		$this->assertStringContainsString( 'extrch-public-tracking', file_get_contents( dirname( __DIR__ ) . '/inc/public-runtime.php' ) );
	}

	public function test_cross_blog_operations_use_only_canonical_storage_and_restore_caller(): void {
		$this->assignPostOwner();
		switch_to_blog( 7 );
		$this->assertSame( 40, ec_get_link_page_id_for_owner( 'post:4:profile:20' ) );
		$this->assertSame( 40, ec_read_link_page_persistence( 40 )['link_page_id'] );
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'bio' => 'Cross blog' ) ) );
		$this->assertSame( 7, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][7]['post_meta'] );
		restore_current_blog();
		$this->assertSame( 'Cross blog', get_post_meta( 40, '_link_page_bio_text', true ) );

		switch_to_blog( 7 );
		$urls = ec_link_page_sitemap_urls( array() );
		$this->assertSame( 'https://extrachill.link/legacy-page/', $urls[0]['loc'] );
		$this->assertSame( 7, get_current_blog_id() );
		restore_current_blog();
	}

	public function test_multisite_storage_without_provider_fails_closed_but_single_site_uses_current_blog(): void {
		$GLOBALS['ec_test']['filters']['ec_link_page_storage_blog_id'] = array();
		$this->assertSame( 0, ec_get_link_page_storage_blog_id() );
		$this->assertSame( 'link_page_storage_unavailable', $this->errorCode( ec_read_link_page_persistence( 40 ) ) );

		$GLOBALS['ec_test']['multisite'] = false;
		switch_to_blog( 7 );
		$this->assertSame( 7, ec_get_link_page_storage_blog_id() );
		restore_current_blog();
	}

	public function test_persistent_ids_reject_cross_page_duplicates_and_lock_failure(): void {
		$cross_page = ec_sanitize_link_page_links( array( array( 'id' => '41-section-1', 'links' => array() ) ), 40 );
		$this->assertSame( 'invalid_link_page_element_id', $this->errorCode( $cross_page ) );

		$duplicate = ec_sanitize_link_page_links(
			array(
				array( 'id' => '40-section-1', 'links' => array( array( 'id' => '40-link-1', 'link_text' => 'One', 'link_url' => 'https://one.example' ) ) ),
				array( 'id' => '40-section-1', 'links' => array() ),
			),
			40
		);
		$this->assertSame( 'duplicate_link_page_element_id', $this->errorCode( $duplicate ) );

		$GLOBALS['ec_test']['fail_advisory_lock'] = true;
		$locked = ec_sanitize_link_page_links( array(), 40 );
		$this->assertSame( 'link_page_id_lock_failed', $this->errorCode( $locked ) );
		$GLOBALS['ec_test']['fail_advisory_lock'] = false;
		$GLOBALS['ec_test']['lock_acquires'] = 0;
		$GLOBALS['ec_test']['lock_releases'] = 0;
		$composed = ec_with_link_page_lock_scope( 40, static function () { return ec_with_link_page_id_lock( 40, '__return_true' ); } );
		$this->assertTrue( $composed );
		$this->assertSame( 1, $GLOBALS['ec_test']['lock_acquires'] );
		$this->assertSame( 1, $GLOBALS['ec_test']['lock_releases'] );
		$conflict = ec_with_link_page_lock_scope( 40, static function () { return ec_with_link_page_lock_scope( 41, '__return_true' ); } );
		$this->assertSame( 'link_page_lock_scope_conflict', $this->errorCode( $conflict ) );
	}

	public function test_existing_production_legacy_ids_survive_but_new_legacy_ids_fail(): void {
		$legacy_id = 'link_1763889579_RU8dCXRaH';
		$stored    = array();
		for ( $index = 1; $index <= 18; ++$index ) {
			$stored[] = array(
				'id'            => '40-section-' . $index,
				'section_title' => 'Section ' . $index,
				'links'         => array( array( 'id' => 1 === $index ? $legacy_id : '40-link-' . $index, 'link_text' => 'Link ' . $index, 'link_url' => 'https://example.com/' . $index ) ),
			);
		}
		update_post_meta( 40, '_link_page_links', $stored );
		$stored[0]['links'][0]['link_text'] = 'Updated legacy link';
		$result = ec_save_link_page_persistence( 40, array( 'links' => $stored ) );
		$this->assertIsArray( $result );
		$this->assertSame( $legacy_id, $result['links'][0]['links'][0]['id'] );

		$stored[0]['links'][] = array( 'id' => 'link_1763889580_NEWVALUE', 'link_text' => 'Injected', 'link_url' => 'https://example.com/new' );
		$invalid = ec_save_link_page_persistence( 40, array( 'links' => $stored ) );
		$this->assertSame( 'invalid_link_page_element_id', $this->errorCode( $invalid ) );
	}

	public function test_existing_large_data_uri_is_preserved_but_cannot_be_introduced_or_changed(): void {
		$data_uri = 'url(data:image/svg+xml;base64,' . str_repeat( 'A', 96 * 1024 ) . ')';
		update_post_meta( 40, '_link_page_custom_css_vars', array( '--link-page-background-image-url' => $data_uri, '--link-page-text-color' => '#111111' ) );
		$result = ec_save_link_page_persistence( 40, array( 'css_vars' => array( '--link-page-text-color' => '#222222' ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( $data_uri, get_post_meta( 40, '_link_page_custom_css_vars', true )['--link-page-background-image-url'] );

		$changed = ec_save_link_page_persistence( 40, array( 'css_vars' => array( '--link-page-background-image-url' => $data_uri . 'x' ) ) );
		$this->assertSame( 'invalid_link_page_css_value', $this->errorCode( $changed ) );

		delete_post_meta( 40, '_link_page_custom_css_vars' );
		$introduced = ec_save_link_page_persistence( 40, array( 'css_vars' => array( '--link-page-background-image-url' => $data_uri ) ) );
		$this->assertSame( 'invalid_link_page_css_value', $this->errorCode( $introduced ) );
	}

	public function test_advisory_lock_remains_held_through_metadata_writes(): void {
		$GLOBALS['ec_test']['require_advisory_lock'] = true;
		$result = ec_save_link_page_persistence( 40, array( 'bio' => 'Locked write' ) );
		$this->assertIsArray( $result );
		$this->assertFalse( $GLOBALS['ec_test']['advisory_lock_held'] );
	}

	public function test_css_and_redirect_validation_fail_closed_without_writes(): void {
		$this->assignPostOwner();
		$before = $GLOBALS['ec_test']['blogs'][4]['post_meta'];
		foreach ( array( 'red;display:none', 'url(javascript:alert(1))', '/*breakout*/', 'red}' ) as $value ) {
			$result = ec_save_link_page_persistence( 40, array( 'css_vars' => array( '--link-page-text-color' => $value ) ) );
			$this->assertSame( 'invalid_link_page_css_value', $this->errorCode( $result ) );
		}
		$redirect = ec_save_link_page_persistence( 40, array( 'redirect_target_url' => 'javascript:alert(1)' ) );
		$this->assertSame( 'invalid_link_page_redirect_url', $this->errorCode( $redirect ) );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['post_meta'] );
	}

	public function test_metadata_save_compensates_prior_writes_and_emits_no_success_hooks(): void {
		$this->assignPostOwner();
		update_post_meta( 40, '_link_page_custom_css_vars', array( '--link-page-text-color' => '#111111' ) );
		update_post_meta( 40, '_link_page_bio_text', 'Before' );
		$before = $GLOBALS['ec_test']['blogs'][4]['post_meta'][40];
		$GLOBALS['ec_test']['meta_write_calls'] = 0;
		$GLOBALS['ec_test']['fail_meta_write_calls'] = array( 2 );
		$result = ec_save_link_page_persistence( 40, array( 'css_vars' => array( '--link-page-text-color' => '#222222' ), 'bio' => 'After' ) );
		$this->assertSame( 'link_page_save_failed', $this->errorCode( $result ) );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['post_meta'][40] );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'] ?? array(), static function ( $action ) { return in_array( $action[0], array( 'ec_link_page_persistence_saved', 'ec_link_page_save', 'extrachill_cache_purge_post' ), true ); } ) );
	}

	public function test_expiration_removes_malformed_and_expired_flat_links(): void {
		$this->assignPostOwner();
		$GLOBALS['ec_test']['now'] = strtotime( '2026-08-23 12:00:00 UTC' );
		update_post_meta( 40, '_link_expiration_enabled', '1' );
		update_post_meta(
			40,
			'_link_page_links',
			array(
				array( 'id' => '40-link-1', 'link_text' => 'Malformed', 'link_url' => 'https://bad.example', 'expires_at' => 'not-a-date' ),
				array( 'id' => '40-link-2', 'link_text' => 'Future', 'link_url' => 'https://future.example', 'expires_at' => '2026-08-24' ),
			)
		);
		$this->assertTrue( ec_cleanup_expired_link_page_links() );
		$links = get_post_meta( 40, '_link_page_links', true );
		$this->assertSame( array( '40-link-2' ), array_column( $links, 'id' ) );
	}

	public function test_compensation_failure_is_explicit_and_preserves_primary_cause(): void {
		$this->assignPostOwner();
		update_post_meta( 40, '_link_page_custom_css_vars', array( '--link-page-text-color' => '#111111' ) );
		update_post_meta( 40, '_link_page_bio_text', 'Before' );
		$GLOBALS['ec_test']['meta_write_calls'] = 0;
		$GLOBALS['ec_test']['fail_meta_write_calls'] = array( 2, 3 );
		$result = ec_save_link_page_persistence( 40, array( 'css_vars' => array( '--link-page-text-color' => '#222222' ), 'bio' => 'After' ) );
		$this->assertSame( 'link_page_save_compensation_failed', $this->errorCode( $result ) );
		$this->assertSame( 'link_page_save_failed', $result->get_error_data()['cause'] );
	}

	public function test_create_and_delete_request_cache_contract_once(): void {
		$id = ec_create_owned_link_page( 'term:7:place:30', 'Cache Page', 'cache-page' );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) { return 'extrachill_cache_purge_post' === $action[0]; } ) );
		$GLOBALS['ec_test']['execute_actions'] = true;
		do_action( 'ec_link_page_save', $id );
		$create_purges = array_values( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) use ( $id ) { return 'extrachill_cache_purge_post' === $action[0] && $id === $action[1][0]; } ) );
		$this->assertCount( 1, $create_purges );
		ec_purge_link_page_before_delete( $id );
		$all_purges = array_values( array_filter( $GLOBALS['ec_test']['fired_actions'], static function ( $action ) use ( $id ) { return 'extrachill_cache_purge_post' === $action[0] && $id === $action[1][0]; } ) );
		$this->assertCount( 2, $all_purges );
	}
}

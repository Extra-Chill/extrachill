<?php

use PHPUnit\Framework\TestCase;

final class RuntimeTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		$this->post( 4, 20, 'profile' );
		$this->post( 4, 40, EC_LINK_PAGE_POST_TYPE );
		$this->term( 7, 30, 'place' );
	}

	private function post( $blog_id, $id, $type ): void {
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish' );
	}

	private function term( $blog_id, $id, $taxonomy ): void {
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['terms'][ $id ] = (object) array( 'term_id' => $id, 'taxonomy' => $taxonomy );
	}

	private function owner(): array {
		return array( 'kind' => 'post', 'blog_id' => 4, 'subtype' => 'profile', 'object_id' => 20 );
	}

	private function errorCode( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result->get_error_code();
	}

	public function test_runtime_contract_and_post_type_are_legacy_compatible(): void {
		$this->assertTrue( ec_link_pages_runtime_ready() );
		$this->assertSame( '3', EC_LINK_PAGES_RUNTIME_API_VERSION );
		$this->assertSame( 'artist_link_page', EC_LINK_PAGE_POST_TYPE );
		unset( $GLOBALS['ec_test']['blogs'][4]['posts'][40] );
		ec_register_link_page_post_type();
		$args = $GLOBALS['ec_test']['registered_post_types'][ EC_LINK_PAGE_POST_TYPE ];
		unset( $args['label'], $args['description'], $args['labels'] );
		$this->assertSame(
			array(
				'supports'            => array( 'title', 'custom-fields', 'author' ),
				'hierarchical'        => false,
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_position'       => 6,
				'menu_icon'           => 'dashicons-admin-links',
				'show_in_admin_bar'   => true,
				'show_in_nav_menus'   => true,
				'can_export'          => true,
				'has_archive'         => false,
				'exclude_from_search' => false,
				'publicly_queryable'  => true,
				'rewrite'             => array( 'slug' => 'link-page' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'show_in_rest'        => true,
			),
			$args
		);
	}

	public function test_post_term_and_cross_blog_references_restore_context(): void {
		$this->assertSame( 'post:4:profile:20', ec_format_link_page_owner_reference( $this->owner() ) );
		$this->assertSame( 'term:7:place:30', ec_normalize_link_page_owner_reference( 'term:7:place:30' ) );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] );
		$this->assertSame( 'invalid_link_page_owner_object', $this->errorCode( ec_normalize_link_page_owner_reference( 'post:7:missing:30' ) ) );
	}

	public function test_provider_order_is_append_only_and_context_is_restored(): void {
		foreach ( array( array( 'z', 20 ), array( 'b', 5 ), array( 'a', 5 ) ) as $provider ) {
			ec_register_link_page_owner_compatibility_provider( $provider[0], static function () use ( $provider ) { $GLOBALS['ec_test']['order'][] = $provider[0]; return array(); }, $provider[1] );
		}
		$this->assertSame( 0, ec_get_link_page_id_for_owner( $this->owner() ) );
		$this->assertSame( array( 'a', 'b', 'z' ), $GLOBALS['ec_test']['order'] );
		$this->assertSame( 'duplicate_link_page_owner_provider', $this->errorCode( ec_register_link_page_owner_compatibility_provider( 'a', '__return_true' ) ) );
		$this->assertFalse( method_exists( ec_link_page_owner_compatibility_registry(), 'unregister' ) );

		ec_test_reset();
		$this->post( 4, 20, 'profile' );
		$this->term( 7, 30, 'place' );
		ec_register_link_page_owner_compatibility_provider( 'switcher', static function () { switch_to_blog( 7 ); throw new RuntimeException( 'failure' ); } );
		$this->assertSame( 'link_page_owner_provider_exception', $this->errorCode( ec_get_link_page_id_for_owner( $this->owner() ) ) );
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertFalse( $GLOBALS['switched'] );
	}

	public function test_provider_reentrancy_and_conflicting_claims_fail_closed(): void {
		ec_register_link_page_owner_compatibility_provider( 'recursive', static function ( $operation, $context ) { return ec_collect_raw_link_page_owner_compatibility_claims( $operation, $context ); } );
		$this->assertSame( 'link_page_owner_provider_reentrancy', $this->errorCode( ec_get_link_page_id_for_owner( $this->owner() ) ) );

		ec_test_reset();
		$this->post( 4, 20, 'profile' );
		$this->post( 4, 21, 'profile' );
		$this->post( 4, 40, EC_LINK_PAGE_POST_TYPE );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:profile:21';
		ec_register_link_page_owner_compatibility_provider( 'claim', static function ( $operation, $context ) { return 'owner_pages' === $operation ? array( array( 'link_page_id' => 40, 'owner_reference' => $context['owner_reference'] ) ) : array(); } );
		$this->assertSame( 'link_page_owner_divergence', $this->errorCode( ec_get_link_page_id_for_owner( $this->owner() ) ) );
	}

	public function test_uniqueness_conflicts_and_assignment_compensation(): void {
		$this->post( 4, 41, EC_LINK_PAGE_POST_TYPE );
		$this->assertTrue( ec_assign_link_page_owner( 40, $this->owner() ) );
		$this->assertSame( 'link_page_owner_conflict', $this->errorCode( ec_assign_link_page_owner( 41, $this->owner() ) ) );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][41][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:profile:20';
		$this->assertSame( 'duplicate_link_pages_for_owner', $this->errorCode( ec_get_link_page_id_for_owner( $this->owner() ) ) );

		ec_test_reset();
		$this->post( 4, 20, 'profile' );
		$this->post( 4, 40, EC_LINK_PAGE_POST_TYPE );
		$this->post( 4, 41, EC_LINK_PAGE_POST_TYPE );
		$GLOBALS['ec_test']['after_add'] = static function () { $GLOBALS['ec_test']['blogs'][4]['post_meta'][41][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:profile:20'; };
		$this->assertSame( 'duplicate_link_pages_for_owner', $this->errorCode( ec_assign_link_page_owner( 40, $this->owner() ) ) );
		$this->assertArrayNotHasKey( EC_LINK_PAGE_OWNER_META_KEY, $GLOBALS['ec_test']['blogs'][4]['post_meta'][40] );
	}

	public function test_bounded_backfill_is_idempotent_and_halts_on_hazard(): void {
		ec_register_link_page_owner_compatibility_provider( 'compat', static function ( $operation, $context ) {
			if ( 'page_owner' === $operation && 40 === $context['link_page_id'] ) { return array( array( 'link_page_id' => 40, 'owner_reference' => 'post:4:profile:20' ) ); }
			if ( 'owner_pages' === $operation && 'post:4:profile:20' === $context['owner_reference'] ) { return array( array( 'link_page_id' => 40, 'owner_reference' => $context['owner_reference'] ) ); }
			return array();
		} );
		$this->assertSame( array( 'processed' => 1, 'updated' => 1, 'skipped' => 0, 'errors' => array(), 'next_offset' => 1 ), ec_backfill_link_page_owner_references( 1 ) );
		$this->assertSame( array( 'processed' => 1, 'updated' => 0, 'skipped' => 1, 'errors' => array(), 'next_offset' => 1 ), ec_backfill_link_page_owner_references( 1 ) );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = array( 'post:4:profile:20', 'post:4:profile:20' );
		$result = ec_backfill_link_page_owner_references( 5000 );
		$this->assertSame( array( 40 => 'duplicate_link_page_owner_references' ), $result['errors'] );
		$this->assertSame( 0, $result['next_offset'] );
	}

	public function test_operation_target_failures_reentrancy_context_and_ownership_change(): void {
		$this->assertTrue( ec_assign_link_page_owner( 40, $this->owner() ) );
		$this->assertSame( 40, ec_resolve_link_page_operation_target( 'post:4:profile:20' )['link_page_id'] );
		$this->assertSame( 'invalid_link_page_operation_target', $this->errorCode( ec_read_link_page( array() ) ) );
		$this->assertSame( 'link_page_operation_provider_missing', $this->errorCode( ec_read_link_page( 40 ) ) );

		ec_register_link_page_operation_provider( 'thrower', static function () { switch_to_blog( 7 ); throw new RuntimeException( 'failure' ); } );
		$this->assertSame( 'link_page_operation_provider_exception', $this->errorCode( ec_read_link_page( 40 ) ) );
		$this->assertSame( 4, get_current_blog_id() );

		ec_test_reset();
		$this->post( 4, 20, 'profile' );
		$this->post( 4, 21, 'profile' );
		$this->post( 4, 40, EC_LINK_PAGE_POST_TYPE );
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:profile:20';
		ec_register_link_page_owner_compatibility_provider( 'stable-claim', static function ( $operation, $context ) {
			if ( 'page_owner' === $operation ) { return array( array( 'link_page_id' => 40, 'owner_reference' => 'post:4:profile:20' ) ); }
			if ( 'owner_pages' === $operation && 'post:4:profile:20' === $context['owner_reference'] ) { return array( array( 'link_page_id' => 40, 'owner_reference' => $context['owner_reference'] ) ); }
			return array();
		} );
		ec_register_link_page_operation_provider( 'mutator', static function () { return array(
			'authorize' => static function () { $GLOBALS['ec_test']['blogs'][4]['post_meta'][40][ EC_LINK_PAGE_OWNER_META_KEY ] = 'post:4:profile:21'; return true; },
			'read' => static function () { $GLOBALS['ec_test']['executed'] = true; return array(); },
			'save' => static function () { return array(); },
		); } );
		$this->assertSame( 'link_page_owner_divergence', $this->errorCode( ec_read_link_page( 40 ) ) );
		$this->assertArrayNotHasKey( 'executed', $GLOBALS['ec_test'] );
	}

	public function test_operation_provider_order_and_payloads_are_stable(): void {
		$this->assertTrue( ec_assign_link_page_owner( 40, $this->owner() ) );
		foreach ( array( array( 'z', 20 ), array( 'a', 5 ) ) as $provider ) {
			ec_register_link_page_operation_provider( $provider[0], static function () use ( $provider ) { $GLOBALS['ec_test']['operation_order'][] = $provider[0]; return 'z' === $provider[0] ? array( 'authorize' => '__return_true', 'read' => static function ( $target ) { return $target; }, 'save' => static function ( $target, $data ) { return $data + $target; } ) : null; }, $provider[1] );
		}
		$this->assertSame( 40, ec_read_link_page( 40 )['link_page_id'] );
		$this->assertSame( 'value', ec_save_link_page( 40, array( 'saved' => 'value' ) )['saved'] );
		$this->assertSame( array( 'a', 'z', 'a', 'z' ), $GLOBALS['ec_test']['operation_order'] );
		$this->assertFalse( method_exists( ec_link_page_operation_provider_registry(), 'unregister' ) );
	}
}

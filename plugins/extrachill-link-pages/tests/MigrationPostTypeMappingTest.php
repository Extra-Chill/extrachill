<?php
/**
 * Migration type-mapping behavior for the dedicated Link Pages site.
 *
 * @package ExtraChillLinkPages
 */

use PHPUnit\Framework\TestCase;

/**
 * The migration writes destination rows as the destination-resolved type
 * and leaves the source untouched, in both directions (blog 4 -> 13 and the
 * reverse rollback-style 13 -> 4).
 */
final class MigrationPostTypeMappingTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
	}

	private function post( $id, $type, $slug ) {
		return (object) array(
			'ID'                    => $id,
			'post_author'           => '1',
			'post_date'             => '2026-01-02 03:04:05',
			'post_date_gmt'         => '2026-01-02 08:04:05',
			'post_content'          => 'Content ' . $id,
			'post_title'            => 'Title ' . $id,
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => $slug,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2026-02-03 04:05:06',
			'post_modified_gmt'     => '2026-02-03 09:05:06',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => 'https://source.test/?p=' . $id,
			'menu_order'            => 0,
			'post_type'             => $type,
			'post_mime_type'        => '',
		);
	}

	private function registerParticipant(): void {
		$callbacks = array(
			'claim_owner' => static function () {
				return true;
			},
			'plan'        => static function () {
				return array(
					'fingerprint'    => hash( 'sha256', 'fixture-plan' ),
					'attachment_ids' => array(),
				);
			},
			'apply'       => static function () {
				return true;
			},
			'validate'    => static function () {
				return true;
			},
			'rollback'    => static function () {
				return true;
			},
		);
		$this->assertTrue( ec_register_link_page_migration_participant( 'owner-adapter', '1', $callbacks ) );
	}

	private function seedOwnedPage( $blog_id, $link_page_id, $type ): void {
		$profile_id = $link_page_id + 100;
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'][ $profile_id ]   = $this->post( $profile_id, 'profile', 'profile-' . $profile_id );
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'][ $link_page_id ] = $this->post( $link_page_id, $type, 'page-' . $link_page_id );
		$meta_id = ++$GLOBALS['ec_test']['next_meta_id'];
		$GLOBALS['ec_test']['meta_rows'][ $meta_id ] = array(
			'blog_id'    => $blog_id,
			'post_id'    => $link_page_id,
			'meta_key'   => EC_LINK_PAGE_OWNER_META_KEY,
			'meta_value' => 'post:' . $blog_id . ':profile:' . $profile_id,
		);
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $link_page_id ][ EC_LINK_PAGE_OWNER_META_KEY ][] = 'post:' . $blog_id . ':profile:' . $profile_id;
	}

	public function test_plan_reports_source_and_destination_types_explicitly(): void {
		$this->seedOwnedPage( 4, 40, 'artist_link_page' );
		$this->registerParticipant();
		$plan = ec_plan_link_page_storage_migration( 4, 13 );
		$this->assertFalse( is_wp_error( $plan ), is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertSame( 'artist_link_page', $plan['source_post_type'] );
		$this->assertSame( 'ec_link_page', $plan['destination_post_type'] );
		$this->assertSame( 'ec_link_page', $plan['posts'][0]['post_type'] );
	}

	public function test_apply_writes_destination_type_and_leaves_source_untouched(): void {
		$this->seedOwnedPage( 4, 40, 'artist_link_page' );
		$this->registerParticipant();
		$plan = ec_plan_link_page_storage_migration( 4, 13 );
		$this->assertTrue( $plan['ready'], wp_json_encode( $plan ) );
		$result = ec_apply_link_page_storage_migration( 4, 13, $plan['fingerprint'] );
		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'applied', $result['status'] );

		$this->assertSame( 'ec_link_page', $GLOBALS['ec_test']['blogs'][13]['posts'][40]->post_type );
		$this->assertSame( 'artist_link_page', $GLOBALS['ec_test']['blogs'][4]['posts'][40]->post_type );

		$this->assertSame( 'valid', ec_validate_link_page_storage_migration( $result['journal_id'] )['status'] );
		$this->assertSame( 'rolled_back', ec_rollback_link_page_storage_migration( $result['journal_id'] )['status'] );
		$this->assertArrayNotHasKey( 40, $GLOBALS['ec_test']['blogs'][13]['posts'] );
		$this->assertSame( 'artist_link_page', $GLOBALS['ec_test']['blogs'][4]['posts'][40]->post_type );
	}

	public function test_reverse_migration_maps_back_to_the_legacy_type(): void {
		// Simulate the gate already being on (storage resolves to the
		// dedicated site) so the owner lookup performed during the source
		// read finds the owner meta where it actually lives.
		add_filter( 'ec_link_page_storage_blog_id', static function () {
			return 13;
		} );
		$this->seedOwnedPage( 13, 40, 'ec_link_page' );
		$this->registerParticipant();
		$plan = ec_plan_link_page_storage_migration( 13, 4 );
		$this->assertTrue( $plan['ready'], wp_json_encode( $plan ) );
		$this->assertSame( 'ec_link_page', $plan['source_post_type'] );
		$this->assertSame( 'artist_link_page', $plan['destination_post_type'] );

		$result = ec_apply_link_page_storage_migration( 13, 4, $plan['fingerprint'] );
		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'artist_link_page', $GLOBALS['ec_test']['blogs'][4]['posts'][40]->post_type );
		$this->assertSame( 'ec_link_page', $GLOBALS['ec_test']['blogs'][13]['posts'][40]->post_type );
	}

	public function test_collision_preflight_compares_against_the_destination_type(): void {
		$this->seedOwnedPage( 4, 40, 'artist_link_page' );
		// A pre-existing destination post sharing the slug, already stored
		// under the destination-resolved type, must be flagged as a slug
		// collision when planning into the dedicated site.
		$GLOBALS['ec_test']['blogs'][13]['posts'][900] = $this->post( 900, 'ec_link_page', 'page-40' );
		$this->registerParticipant();
		$plan = ec_plan_link_page_storage_migration( 4, 13 );
		$this->assertFalse( is_wp_error( $plan ), is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertContains( 'slug', array_column( $plan['collisions'], 'type' ) );
	}

	public function test_participant_context_carries_both_types_additively(): void {
		$this->seedOwnedPage( 4, 40, 'artist_link_page' );
		$seen = array();
		$this->assertTrue(
			ec_register_link_page_migration_participant(
				'type-observer',
				'1',
				array(
					'claim_owner' => static function () {
						return true;
					},
					'plan'        => static function ( $context ) use ( &$seen ) {
						$seen['plan'] = array( $context['source_post_type'], $context['destination_post_type'] );
						return array(
							'fingerprint'    => hash( 'sha256', 'observed-plan' ),
							'attachment_ids' => array(),
						);
					},
					'apply'       => static function ( $context ) use ( &$seen ) {
						$seen['apply'] = array( $context['source_post_type'], $context['destination_post_type'] );
						return true;
					},
					'validate'    => static function ( $context ) use ( &$seen ) {
						$seen['validate'] = array( $context['source_post_type'], $context['destination_post_type'] );
						return true;
					},
					'rollback'    => static function () {
						return true;
					},
				)
			)
		);
		$plan = ec_plan_link_page_storage_migration( 4, 13 );
		$this->assertSame( array( 'artist_link_page', 'ec_link_page' ), $seen['plan'] );
		$result = ec_apply_link_page_storage_migration( 4, 13, $plan['fingerprint'] );
		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array( 'artist_link_page', 'ec_link_page' ), $seen['apply'] );
		ec_validate_link_page_storage_migration( $result['journal_id'] );
		$this->assertSame( array( 'artist_link_page', 'ec_link_page' ), $seen['validate'] );
	}
}

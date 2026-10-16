<?php
/**
 * Tests for the network-owned QR code ability.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

use ExtraChillNetwork\Abilities\QRCodeAbility;

/**
 * Validate QR generation compatibility and resource bounds.
 *
 * @group qr-code
 */
class QRCodeAbilityTest extends WP_UnitTestCase {

	/**
	 * Ability under test.
	 *
	 * @var QRCodeAbility
	 */
	private QRCodeAbility $ability;

	/**
	 * Create a fresh ability handler for each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->ability = new QRCodeAbility();
	}

	/**
	 * Normal plugin initialization makes the ability available to consumers.
	 */
	public function test_ability_resolves_after_plugin_initialization(): void {
		$this->assertTrue( wp_has_ability( 'extrachill/generate-qr-code' ) );
		$this->assertInstanceOf(
			\WP_Ability::class,
			wp_get_ability( 'extrachill/generate-qr-code' )
		);
	}

	/**
	 * The API and CLI consumers receive the unchanged response keys.
	 */
	public function test_generate_preserves_response_contract(): void {
		$result = $this->ability->execute(
			array(
				'url'  => 'https://extrachill.com/test',
				'size' => 300,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'https://extrachill.com/test', $result['url'] );
		$this->assertSame( 300, $result['size'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- verifies the binary response contract.
		$this->assertNotFalse( base64_decode( $result['image'], true ) );
	}

	/**
	 * Pixel dimensions remain bounded without response drift.
	 */
	public function test_generate_clamps_requested_dimensions(): void {
		$small = $this->ability->execute(
			array(
				'url'  => 'https://extrachill.com',
				'size' => 1,
			)
		);
		$large = $this->ability->execute(
			array(
				'url'  => 'https://extrachill.com',
				'size' => 5000,
			)
		);

		$this->assertSame( 100, $small['size'] );
		$this->assertSame( 2000, $large['size'] );
	}

	/**
	 * Oversized input is rejected before QR matrix allocation.
	 */
	public function test_generate_rejects_oversized_url_payload(): void {
		$result = $this->ability->execute(
			array( 'url' => 'https://extrachill.com/?value=' . str_repeat( 'a', 2048 ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'url_too_long', $result->get_error_code() );
	}

	/**
	 * REST exposure remains restricted to network managers.
	 */
	public function test_permission_requires_network_management_capability(): void {
		$this->assertFalse( $this->ability->check_permission() );

		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user          = get_userdata( $administrator );

		/*
		 * Establish super-admin status through $GLOBALS['super_admins'] rather
		 * than grant_super_admin().
		 *
		 * grant_super_admin() persists to the `site_admins` site option, which
		 * the sandbox exposes as a string rather than an array. is_super_admin()
		 * then hands that string to in_array() and WordPress core fatals:
		 *
		 *   TypeError: in_array(): Argument #2 ($haystack) must be of type
		 *   array, string given (wp-includes/capabilities.php)
		 *
		 * The global is the documented test-suite override and is checked before
		 * the option, so this asserts the same network-admin gate without
		 * depending on how the sandbox seeds that option.
		 */
		$GLOBALS['super_admins'] = array( $user->user_login );
		wp_set_current_user( $administrator );

		$this->assertTrue( $this->ability->check_permission() );

		unset( $GLOBALS['super_admins'] );
	}

	/**
	 * Data Machine's poster template resolves QR images through this class.
	 */
	public function test_poster_qr_filter_returns_a_readable_png(): void {
		$this->assertNotFalse( has_filter( 'datamachine/image_generation/qr_image_path' ) );

		$path = apply_filters( 'datamachine/image_generation/qr_image_path', null, 'https://events.extrachill.com/near-me/', 190 );

		$this->assertIsString( $path );
		$this->assertFileIsReadable( $path );
		$info = getimagesize( $path );
		$this->assertSame( 'image/png', $info['mime'] );
		$this->assertGreaterThanOrEqual( 600, $info[0] );

		wp_delete_file( $path );
	}

	/**
	 * An earlier provider's image wins, and an empty URL yields nothing.
	 */
	public function test_poster_qr_filter_respects_existing_value_and_empty_url(): void {
		$this->assertSame( '/tmp/existing.png', $this->ability->provide_poster_qr_image( '/tmp/existing.png', 'https://extrachill.com', 190 ) );
		$this->assertNull( $this->ability->provide_poster_qr_image( null, '', 190 ) );
	}
}

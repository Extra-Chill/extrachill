<?php
/**
 * RSVP pass QR scoped permission grant (#921).
 *
 * `extrachill/generate-qr-code` denies everyone except network admins, CLI,
 * and Action Scheduler, so the pass QR endpoint answered 503 for attendees
 * and mail-client image proxies. These tests exercise the non-exiting
 * generation helper against a fake ability that runs core's real
 * `wp_ability_permission_result` filter shape over a denying permission
 * callback. They prove the grant renders the QR and never leaks: other
 * abilities and other URLs keep their original result, and the filter is
 * always removed afterwards, even when execution throws.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP shims and a fake ability with the test case class.

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://events.extrachill.com' . $path;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://events.extrachill.com/wp-admin/' . $path;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ): string {
		if ( 2 === count( $args ) && is_array( $args[0] ) ) {
			$pairs = array();
			foreach ( $args[0] as $k => $v ) {
				$pairs[] = $k . '=' . $v;
			}
			$sep = false === strpos( $args[1], '?' ) ? '?' : '&';
			return $args[1] . $sep . implode( '&', $pairs );
		}
		list( $key, $value, $url ) = $args;
		$sep                       = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $sep . $key . '=' . $value;
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( $name ): bool {
		return null !== wp_get_ability( $name );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-pass-qr.php';

/**
 * Fake of the network QR ability: a denying permission callback followed by
 * core's `wp_ability_permission_result` filter, as WP_Ability::check_permissions() does.
 */
final class Ec_Test_Denying_Qr_Ability {

	/** @var array<int, array> */
	public array $calls = array();

	public bool $throw = false;

	public function execute( $input ) {
		$this->calls[] = $input;

		if ( $this->throw ) {
			throw new RuntimeException( 'boom' );
		}

		$permission = apply_filters( 'wp_ability_permission_result', false, 'extrachill/generate-qr-code', $input, $this );
		if ( true !== $permission ) {
			return new WP_Error( 'ability_invalid_permissions', 'denied' );
		}

		return array(
			'image'     => base64_encode( 'PNGBYTES' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Mirrors the ability's documented base64 image contract.
			'mime_type' => 'image/png',
			'url'       => $input['url'],
			'size'      => $input['size'],
		);
	}
}

final class RsvpPassQrGrantTest extends PHPUnit\Framework\TestCase {

	private Ec_Test_Denying_Qr_Ability $ability;

	/** @var mixed */
	private $previous_resolver;

	protected function setUp(): void {
		$this->ability           = new Ec_Test_Denying_Qr_Ability();
		$this->previous_resolver = $GLOBALS['ec_test_ability_resolver'] ?? null;

		$ability                             = $this->ability;
		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) use ( $ability ) {
			return 'extrachill/generate-qr-code' === $name ? $ability : null;
		};

		unset( $GLOBALS['ec_test_filters']['wp_ability_permission_result'] );
	}

	protected function tearDown(): void {
		$GLOBALS['ec_test_ability_resolver'] = $this->previous_resolver;
		unset( $GLOBALS['ec_test_filters']['wp_ability_permission_result'] );
	}

	private function registered_permission_filters(): int {
		$count = 0;
		foreach ( $GLOBALS['ec_test_filters']['wp_ability_permission_result'] ?? array() as $callbacks ) {
			$count += count( $callbacks );
		}
		return $count;
	}

	public function test_denying_ability_still_renders_the_validated_pass_qr(): void {
		$result = extrachill_events_generate_rsvp_pass_qr( 'ABCDE-FGH2J-K3LMN' );

		$this->assertIsArray( $result );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'https://events.extrachill.com/rsvp-verify/?code=ABCDE-FGH2J-K3LMN', $this->ability->calls[0]['url'] );
		$this->assertSame( 500, $this->ability->calls[0]['size'] );
	}

	public function test_grant_is_removed_after_the_call(): void {
		extrachill_events_generate_rsvp_pass_qr( 'ABCDE-FGH2J-K3LMN' );

		$this->assertSame( 0, $this->registered_permission_filters() );
	}

	public function test_grant_is_removed_even_when_the_ability_throws(): void {
		$this->ability->throw = true;

		try {
			extrachill_events_generate_rsvp_pass_qr( 'ABCDE-FGH2J-K3LMN' );
			$this->fail( 'Expected the ability exception to propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( 0, $this->registered_permission_filters() );
	}

	public function test_without_the_grant_the_ability_denies(): void {
		$result = $this->ability->execute(
			array(
				'url'  => 'https://events.extrachill.com/rsvp-verify/?code=ABCDE-FGH2J-K3LMN',
				'size' => 500,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_grant_only_applies_to_the_qr_ability(): void {
		$grant = extrachill_events_rsvp_pass_qr_permission_grant( 'https://events.extrachill.com/rsvp-verify/?code=X' );

		$this->assertFalse( $grant( false, 'extrachill/some-other-ability', array( 'url' => 'https://events.extrachill.com/rsvp-verify/?code=X' ) ) );
	}

	public function test_grant_only_applies_to_the_validated_verify_url(): void {
		$grant = extrachill_events_rsvp_pass_qr_permission_grant( 'https://events.extrachill.com/rsvp-verify/?code=X' );

		$this->assertFalse( $grant( false, 'extrachill/generate-qr-code', array( 'url' => 'https://evil.example/' ) ) );
		$this->assertFalse( $grant( false, 'extrachill/generate-qr-code', array() ) );
		$this->assertFalse( $grant( false, 'extrachill/generate-qr-code', null ) );
		$this->assertTrue( $grant( false, 'extrachill/generate-qr-code', array( 'url' => 'https://events.extrachill.com/rsvp-verify/?code=X' ) ) );
	}

	public function test_missing_ability_returns_null(): void {
		$GLOBALS['ec_test_ability_resolver'] = static fn() => null;

		$this->assertNull( extrachill_events_generate_rsvp_pass_qr( 'ABCDE-FGH2J-K3LMN' ) );
	}
}

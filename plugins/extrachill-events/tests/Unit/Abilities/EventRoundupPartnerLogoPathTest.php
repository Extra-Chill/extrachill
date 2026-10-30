<?php
/**
 * The co-brand path on the staff roundup abilities is confined to uploads.
 *
 * @package ExtraChillEvents\Tests
 */

namespace {
	if ( ! function_exists( 'wp_get_upload_dir' ) ) {
		/**
		 * Test stub: uploads live under a temp directory.
		 *
		 * @return array
		 */
		function wp_get_upload_dir(): array {
			return array( 'basedir' => $GLOBALS['ec_test_upload_basedir'] ?? '' );
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {
	use ExtraChillEvents\Abilities\EventRoundupAbilities;
	use PHPUnit\Framework\TestCase;

	final class EventRoundupPartnerLogoPathTest extends TestCase {

		/**
		 * Temp uploads dir.
		 *
		 * @var string
		 */
		private $uploads;

		protected function setUp(): void {
			parent::setUp();
			$this->uploads = sys_get_temp_dir() . '/ec-uploads-' . uniqid();
			mkdir( $this->uploads . '/2026/10', 0777, true );
			file_put_contents( $this->uploads . '/2026/10/logo.png', 'x' );
			file_put_contents( $this->uploads . '-secret.png', 'x' );
			$GLOBALS['ec_test_upload_basedir'] = $this->uploads;
		}

		protected function tearDown(): void {
			@unlink( $this->uploads . '/2026/10/logo.png' );
			@unlink( $this->uploads . '-secret.png' );
			@rmdir( $this->uploads . '/2026/10' );
			@rmdir( $this->uploads . '/2026' );
			@rmdir( $this->uploads );
			unset( $GLOBALS['ec_test_upload_basedir'] );
			parent::tearDown();
		}

		public function test_a_file_inside_uploads_is_accepted(): void {
			$this->assertSame( realpath( $this->uploads . '/2026/10/logo.png' ), EventRoundupAbilities::uploadsPath( $this->uploads . '/2026/10/logo.png' ) );
		}

		public function test_paths_outside_uploads_are_rejected(): void {
			$this->assertSame( '', EventRoundupAbilities::uploadsPath( '/etc/passwd' ) );
			$this->assertSame( '', EventRoundupAbilities::uploadsPath( $this->uploads . '/../' . basename( $this->uploads ) . '-secret.png' ) );
			$this->assertSame( '', EventRoundupAbilities::uploadsPath( $this->uploads . '-secret.png' ), 'A sibling directory sharing the prefix is not inside uploads.' );
			$this->assertSame( '', EventRoundupAbilities::uploadsPath( '' ) );
		}
	}
}

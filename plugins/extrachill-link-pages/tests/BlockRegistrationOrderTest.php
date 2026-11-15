<?php

use PHPUnit\Framework\TestCase;

final class BlockRegistrationOrderTest extends TestCase {
	/** Build files this test created because build/ is generated, not committed. */
	private array $created_build_files = array();

	protected function setUp(): void {
		WP_Block_Type_Registry::get_instance()->reset();
		$GLOBALS['ec_test']['registered_blocks'] = array();

		$block = EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'build/editor';
		if ( ! is_dir( $block ) ) {
			mkdir( $block, 0777, true );
		}
		foreach ( array( 'block.json' => '{"name":"extrachill/link-page-editor"}', 'index.asset.php' => '<?php return array();' ) as $name => $contents ) {
			if ( ! file_exists( $block . '/' . $name ) ) {
				file_put_contents( $block . '/' . $name, $contents );
				$this->created_build_files[] = $block . '/' . $name;
			}
		}
	}

	protected function tearDown(): void {
		foreach ( $this->created_build_files as $file ) {
			unlink( $file );
		}
		$this->created_build_files = array();
	}

	public function test_shared_runtime_skips_an_already_registered_legacy_block(): void {
		WP_Block_Type_Registry::get_instance()->register( 'extrachill/link-page-editor', 'legacy' );
		ec_register_link_page_editor();

		$this->assertSame( 'legacy', WP_Block_Type_Registry::get_instance()->source( 'extrachill/link-page-editor' ) );
		$this->assertSame( array(), $GLOBALS['ec_test']['registered_blocks'] );
	}

	public function test_shared_runtime_claims_the_block_before_new_consumer_fallback(): void {
		ec_register_link_page_editor();
		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( 'extrachill/link-page-editor' ) );
		$this->assertStringEndsWith( '/build/editor', $registry->source( 'extrachill/link-page-editor' ) );
		$this->assertCount( 1, $GLOBALS['ec_test']['registered_blocks'] );

		if ( ! $registry->is_registered( 'extrachill/link-page-editor' ) ) {
			register_block_type( 'legacy' );
		}
		$this->assertCount( 1, $GLOBALS['ec_test']['registered_blocks'] );
	}
}

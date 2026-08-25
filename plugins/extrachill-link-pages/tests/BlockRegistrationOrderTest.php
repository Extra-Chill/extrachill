<?php

use PHPUnit\Framework\TestCase;

final class BlockRegistrationOrderTest extends TestCase {
	protected function setUp(): void {
		WP_Block_Type_Registry::get_instance()->reset();
		$GLOBALS['ec_test']['registered_blocks'] = array();
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

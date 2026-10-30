<?php
/**
 * Image Voting block registration contract tests.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Image Voting client/server registration boundary.
 */
class ImageVotingContractTest extends TestCase {
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture reads.
	/**
	 * Server and generated metadata must remain byte-for-byte equivalent.
	 */
	public function test_build_preserves_canonical_metadata() {
		$source_path = EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/src/blocks/image-voting/block.json';
		$build_path  = EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/build/blocks/image-voting/block.json';

		$this->assertFileExists( $build_path, 'Run npm run build before the PHP suite.' );
		$this->assertJsonStringEqualsJsonFile( $source_path, file_get_contents( $build_path ) );
	}

	/**
	 * Client registration supplies behavior only; block.json owns metadata.
	 */
	public function test_client_registration_does_not_duplicate_metadata() {
		$metadata = json_decode(
			file_get_contents( EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/src/blocks/image-voting/block.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$source   = file_get_contents( EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/src/blocks/image-voting/index.js' );

		$this->assertSame( 'extrachill/image-voting', $metadata['name'] );
		$this->assertMatchesRegularExpression( "/registerBlockType\\(\\s*'" . preg_quote( $metadata['name'], '/' ) . "'\\s*,\\s*\\{/", $source );
		foreach ( array( 'title', 'category', 'icon', 'attributes' ) as $metadata_key ) {
			$this->assertDoesNotMatchRegularExpression( '/^\\s*' . $metadata_key . '\\s*:/m', $source );
		}
	}

	/**
	 * Dependency extraction must declare every package used by editor behavior.
	 */
	public function test_generated_editor_dependency_manifest_is_exact() {
		$asset_path = EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/build/blocks/image-voting/index.asset.php';

		$this->assertFileExists( $asset_path, 'Run npm run build before the PHP suite.' );
		$asset = require $asset_path;
		$this->assertSame(
			array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element' ),
			$asset['dependencies']
		);
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

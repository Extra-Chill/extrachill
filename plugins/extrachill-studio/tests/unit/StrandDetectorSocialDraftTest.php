<?php
/**
 * Social-draft detector regression tests.
 *
 * Ports the standalone tests/strand-detector-social-draft.php harness to real
 * WordPress: real post meta rows and real metadata_exists() reads.
 *
 * @package ExtraChillStudio
 */

/**
 * Verify ec_studio_post_looks_like_social_draft() against real post meta.
 */
class Test_Strand_Detector_Social_Draft extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	public function test_queued_delivery_reference_identifies_a_social_draft(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, \ExtraChillStudio\META_DELIVERY_REF, 'dop_' . str_repeat( 'a', 64 ) );

		$this->assertTrue( ec_studio_post_looks_like_social_draft( $post_id ), 'A queued delivery reference must identify a social draft.' );
	}

	public function test_empty_delivery_reference_does_not_identify_a_social_draft(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, \ExtraChillStudio\META_DELIVERY_REF, '' );

		$this->assertFalse( ec_studio_post_looks_like_social_draft( $post_id ), 'An empty delivery reference must not identify a social draft.' );
	}
}

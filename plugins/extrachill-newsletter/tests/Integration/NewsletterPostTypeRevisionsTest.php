<?php
/**
 * Newsletter post type revision support (issue #58).
 *
 * @package ExtraChillNewsletter\Tests\Integration
 */

final class NewsletterPostTypeRevisionsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! post_type_exists( 'newsletter' ) ) {
			create_newsletter_post_type();
		}
	}

	public function test_newsletter_post_type_supports_revisions(): void {
		$this->assertTrue( post_type_supports( 'newsletter', 'revisions' ) );
	}

	public function test_editing_a_newsletter_draft_creates_a_revision(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'newsletter',
				'post_status'  => 'draft',
				'post_content' => 'Original body.',
			)
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Edited body.',
			)
		);

		$this->assertNotEmpty( wp_get_post_revisions( $post_id ) );
	}
}

<?php
/**
 * Regression coverage for the logged-out event-source importer teaser.
 *
 * @package ExtraChillEvents
 */

use PHPUnit\Framework\TestCase;

class EventSubmissionJoinPromptTest extends TestCase {

	private function render_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/blocks/event-submission/render.php' );
	}

	public function test_logged_out_branch_promotes_registration_and_login_without_import_controls(): void {
		$source = $this->render_source();
		$this->assertStringContainsString( 'if ( ! is_user_logged_in() ) :', $source );
		$this->assertStringContainsString( 'extrachill_users_get_registration_url()', $source );
		$this->assertStringContainsString( 'ec_users_login_url_with_redirect( $registration_url, $submit_url )', $source );
		$this->assertStringContainsString( "wp_registration_url()", $source );
		$this->assertStringContainsString( 'wp_login_url( $submit_url )', $source );
		$this->assertStringContainsString( "'login/?redirect_to=' . rawurlencode( \$submit_url )", $source );
		$this->assertStringContainsString( 'Import a whole artist tour, venue calendar, or festival schedule at once', $source );
		$this->assertStringContainsString( 'Use the manual form below for one event.', $source );

		preg_match( '/<\?php if \( ! is_user_logged_in\(\) \) : \?>\s*<\?php\s*\$submit_url.*?<\?php endif; \?>/s', $source, $branch );
		$this->assertNotEmpty( $branch, 'The anonymous teaser branch should remain independently identifiable.' );
		$this->assertStringContainsString( 'class="button-1"', $branch[0] );
		$this->assertStringContainsString( 'class="button-2"', $branch[0] );
		$this->assertStringNotContainsString( 'ec-event-submission__url-import-input', $branch[0] );
		$this->assertStringNotContainsString( 'ec-event-submission__url-import-try', $branch[0] );
	}

	public function test_logged_in_importer_controls_remain_in_the_authenticated_branch(): void {
		$source = $this->render_source();
		$this->assertStringContainsString( 'if ( is_user_logged_in() ) : ?>' . "\n\t\t<div class=\"ec-event-submission__url-import\"", $source );
		$this->assertStringContainsString( 'class="ec-event-submission__url-import-input"', $source );
		$this->assertStringContainsString( 'class="button button-secondary ec-event-submission__url-import-try"', $source );
		$this->assertStringContainsString( "'Submit for review'", $source );
	}

	public function test_teaser_classes_follow_the_design_system_allow_list(): void {
		$source = $this->render_source();
		preg_match( '/<\?php if \( ! is_user_logged_in\(\) \) : \?>\s*<\?php\s*\$submit_url.*?<\?php endif; \?>/s', $source, $branch );
		$this->assertNotEmpty( $branch );

		preg_match_all( '/class="([^"]+)"/', $branch[0], $class_attributes );
		$allowed = array(
			'button-1',
			'button-2',
			'ec-event-submission__url-import',
			'ec-event-submission__url-import-actions',
			'ec-event-submission__url-import-hint',
		);
		$used = array();
		foreach ( $class_attributes[1] as $attribute ) {
			$used = array_merge( $used, preg_split( '/\s+/', trim( $attribute ) ) );
		}

		$this->assertSame( array(), array_values( array_diff( $used, $allowed ) ) );
	}
}

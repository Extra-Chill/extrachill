<?php

require_once __DIR__ . '/support/base-test-case.php';

final class LinkPageShareMarkupTest extends EC_Artist_Platform_TestCase {
	private function render_single_link( array $args ): string {
		ob_start();
		require dirname( __DIR__ ) . '/inc/link-pages/live/templates/components/single-link.php';
		return ob_get_clean();
	}

	public function test_link_and_share_button_are_independent_interactive_controls(): void {
		$html = $this->render_single_link(
			array(
				'link_url'  => 'https://example.com/listen',
				'link_text' => 'Listen now',
			)
		);

		$document = new DOMDocument();
		$document->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
		$xpath   = new DOMXPath( $document );
		$wrapper = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " extrch-link-button-wrapper ")]' )->item( 0 );
		$link    = $xpath->query( './a[contains(concat(" ", normalize-space(@class), " "), " extrch-link-page-link ")]', $wrapper )->item( 0 );
		$button  = $xpath->query( './button[contains(concat(" ", normalize-space(@class), " "), " extrch-share-item-trigger ")]', $wrapper )->item( 0 );

		$this->assertNotNull( $wrapper );
		$this->assertNotNull( $link );
		$this->assertNotNull( $button );
		$this->assertSame( $wrapper, $link->parentNode );
		$this->assertSame( $wrapper, $button->parentNode );
		$this->assertSame( 0, $xpath->query( './/a//button', $wrapper )->length );
		$this->assertSame( 'https://example.com/listen', $link->getAttribute( 'href' ) );
		$this->assertSame( 'button', $button->getAttribute( 'type' ) );
		$this->assertSame( 'Share this link', $button->getAttribute( 'aria-label' ) );
	}

	public function test_share_trigger_retains_its_modal_click_handler_without_link_propagation_workaround(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local asset read, not a remote URL.
		$script = file_get_contents( dirname( __DIR__ ) . '/inc/link-pages/live/assets/js/extrch-share-modal.js' );

		$this->assertStringContainsString( "trigger.addEventListener('click'", $script );
		$this->assertStringContainsString( 'e.preventDefault();', $script );
		$this->assertStringContainsString( 'openModal(trigger);', $script );
		$this->assertStringNotContainsString( 'e.stopPropagation();', $script );
	}

	public function test_share_modal_overlay_has_a_valid_background_color_declaration(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local asset read, not a remote URL.
		$styles = file_get_contents( dirname( __DIR__ ) . '/assets/css/extrch-share-modal.css' );

		$this->assertStringContainsString( 'background-color: var(--link-page-overlay-color);', $styles );
		$this->assertStringNotContainsString( 'var(--link-page-overlay-color));', $styles );
	}
}

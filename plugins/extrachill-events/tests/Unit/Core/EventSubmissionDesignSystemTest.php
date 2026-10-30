<?php
/**
 * The event submission block's buttons must use theme classes that render.
 *
 * The block renders inside the theme chrome. `.button`, `.button-secondary`
 * and `.button-link` are wp-admin vocabulary and do not exist in
 * themes/extrachill/style.css, so they rendered unstyled on /submit
 * (extrachill-events#985). Source-level check, same idea as
 * RsvpPassDesignSystemTest.
 *
 * @package ExtraChillEvents
 */

use PHPUnit\Framework\TestCase;

class EventSubmissionDesignSystemTest extends TestCase {

	private const TEMPLATE = 'blocks/event-submission/render.php';

	/** Theme button vocabulary, pinned from themes/extrachill/style.css. */
	private const BUTTON_VARIANTS = array( 'button-1', 'button-2', 'button-3', 'button-danger' );
	private const BUTTON_SIZES    = array( 'button-small', 'button-medium', 'button-large' );

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . self::TEMPLATE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read.
	}

	/**
	 * Class lists of every <button> and every <a> that carries a button class.
	 *
	 * @return string[]
	 */
	private function button_class_lists(): array {
		$source = $this->source();
		preg_match_all( '/<button\b[^>]*\bclass="([^"]*)"/s', $source, $buttons );
		preg_match_all( '/<a\b[^>]*\bclass="([^"]*\bbutton[^"]*)"/s', $source, $links );

		return array_merge( $buttons[1], $links[1] );
	}

	public function test_template_has_buttons(): void {
		$this->assertGreaterThanOrEqual( 4, count( $this->button_class_lists() ) );
	}

	public function test_every_button_has_a_theme_variant_and_size(): void {
		foreach ( $this->button_class_lists() as $list ) {
			$classes = preg_split( '/\s+/', trim( $list ) );
			$this->assertNotEmpty( array_intersect( $classes, self::BUTTON_VARIANTS ), "Button has no theme variant class: {$list}" );
			$this->assertNotEmpty( array_intersect( $classes, self::BUTTON_SIZES ), "Button has no theme size class: {$list}" );
		}
	}

	public function test_no_button_uses_admin_or_invented_button_classes(): void {
		foreach ( $this->button_class_lists() as $list ) {
			$classes = preg_split( '/\s+/', trim( $list ) );
			foreach ( $classes as $class ) {
				$this->assertFalse(
					in_array( $class, array( 'button', 'button-primary', 'button-secondary', 'button-link', 'btn' ), true ) || 0 === strpos( $class, 'btn-' ),
					"Button uses .{$class}, which the extrachill theme does not define: {$list}"
				);
			}
		}
	}
}

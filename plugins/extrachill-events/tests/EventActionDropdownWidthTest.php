<?php
/**
 * Mobile event action dropdowns span their full-width toggles.
 *
 * single-event.css stretches every action-row wrapper to width: 100% on
 * mobile. The Share (theme) and Add to Calendar (data-machine-events) menus
 * are absolutely positioned at left: 0 with a content-sized width, so without
 * a composition rule they open as a narrow strip pinned to the left edge of a
 * full-width button (Extra-Chill/extrachill#102).
 *
 * @package ExtraChillEvents
 */

class EventActionDropdownWidthTest extends WP_UnitTestCase {

	public function test_mobile_action_menus_span_their_toggles(): void {
		$css = (string) file_get_contents( EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/single-event.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level stylesheet read; local plugin file.

		$this->assertMatchesRegularExpression( '/@media \(max-width: 768px\)\s*\{/', $css );
		$mobile = substr( $css, (int) strpos( $css, '@media (max-width: 768px)' ) );

		foreach ( array(
			'.share-dropdown .ec-mini-dropdown-menu',
			'.dm-events-add-to-calendar .dm-events-add-to-calendar-menu',
		) as $menu ) {
			$this->assertMatchesRegularExpression(
				'/\.event-action-buttons > ' . preg_quote( $menu, '/' ) . '[^{]*\{[^}]*\bleft:\s*0;[^}]*\bright:\s*0;[^}]*\bmin-width:\s*0;/',
				$mobile,
				"Mobile {$menu} must span its full-width toggle"
			);
		}
	}
}

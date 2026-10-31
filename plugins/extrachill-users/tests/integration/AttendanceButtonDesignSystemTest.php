<?php
/**
 * The attendance button surface must only use classes that actually render.
 *
 * Same failure mode Test_OAuth_Template_Design_System guards against, for the
 * concert-tracking partial: a class that invents itself renders unstyled, and
 * unstyled HTML is still valid HTML, so nothing in the pipeline notices.
 *
 * This surface differs from the OAuth templates in one way, and the test is
 * honest about it: inc/concert-tracking/buttons.php renders inside the
 * plugin's OWN stylesheet (assets/css/concert-tracking.css, enqueued on
 * single event pages) plus the theme's button vocabulary. So the allowed
 * vocabulary is the union of:
 *
 *   1. Theme classes pinned from themes/extrachill/style.css.
 *   2. Classes the plugin stylesheet actually defines.
 *   3. An explicit, commented list of classes that intentionally carry no
 *      CSS rule (state hooks, aria-only regions, button internals).
 *
 * A class that fits none of the three fails here, at review time.
 *
 * @package ExtraChill\Users
 */

class Test_Attendance_Button_Design_System extends WP_UnitTestCase {

	private const TEMPLATE = 'inc/concert-tracking/buttons.php';

	private const STYLESHEET = 'assets/css/concert-tracking.css';

	/**
	 * Theme classes pinned from themes/extrachill/style.css. The attendance
	 * surface composes with the theme's button system only.
	 *
	 * @var string[]
	 */
	private const THEME_CLASSES = array(
		'button-1',
		'button-2',
		'button-3',
		'button-danger',
		'button-small',
		'button-medium',
		'button-large',
	);

	/**
	 * Classes the template renders with no CSS rule anywhere, on purpose.
	 * Each entry is a deliberate, reviewed decision.
	 *
	 * @var array<string, string>
	 */
	private const CSS_FREE_CLASSES = array(
		// Styled through the theme button classes resolved into $button_class.
		'ec-attendance__button' => 'styled via theme button-* classes',
		// Sits inside the themed button; inherits its typography.
		'ec-attendance__label'  => 'inherits button typography',
		// Empty aria-live region; carries no visible content.
		'ec-attendance__status' => 'aria-live status region',
		// State hook for tests/consumers; intentionally presentation-free.
		'ec-attendance--marked' => 'state hook, no presentation',
	);

	/**
	 * Every class token the plugin stylesheet defines.
	 *
	 * @return string[]
	 */
	private function classes_defined_by_stylesheet(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read; local plugin file.
		$css = (string) file_get_contents( EXTRACHILL_USERS_PLUGIN_DIR . self::STYLESHEET );

		preg_match_all( '/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $css, $matches );

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Every static class name used in the template's class attributes.
	 *
	 * @return string[]
	 */
	private function classes_in_template(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read; local plugin file.
		$source = (string) file_get_contents( EXTRACHILL_USERS_PLUGIN_DIR . self::TEMPLATE );

		// Strip the docblock: it deliberately names wrong classes as a
		// warning, and must not count as usage.
		$source = (string) preg_replace( '#^<\?php\s*/\*\*.*?\*/#s', '', $source );

		preg_match_all( '/class="([^"]+)"/', $source, $matches );

		$classes = array();
		foreach ( $matches[1] as $attr ) {
			foreach ( preg_split( '/\s+/', trim( $attr ) ) as $class ) {
				if ( '' !== $class && false === strpos( $class, '<?php' ) ) {
					$classes[] = $class;
				}
			}
		}

		return array_values( array_unique( $classes ) );
	}

	public function test_template_uses_only_classes_that_actually_render(): void {
		$defined  = $this->classes_defined_by_stylesheet();
		$allowed  = array_merge( self::THEME_CLASSES, $defined, array_keys( self::CSS_FREE_CLASSES ) );
		$unstyled = array_diff( $this->classes_in_template(), $allowed );

		$this->assertSame(
			array(),
			array_values( $unstyled ),
			self::TEMPLATE . ' uses classes with no CSS rule in the theme, the plugin stylesheet, or the pinned CSS-free list; they will render unstyled: ' . implode( ', ', $unstyled )
		);
	}

	public function test_every_new_ec_class_is_either_styled_or_explicitly_css_free(): void {
		$defined = $this->classes_defined_by_stylesheet();

		foreach ( $this->classes_in_template() as $class ) {
			if ( 0 !== strpos( $class, 'ec-' ) || isset( self::CSS_FREE_CLASSES[ $class ] ) ) {
				continue;
			}

			$this->assertContains(
				$class,
				$defined,
				"{$class} is used in " . self::TEMPLATE . ' but not defined in ' . self::STYLESHEET . ' — add the rule, or pin it in CSS_FREE_CLASSES with a reason.'
			);
		}
	}

	public function test_template_does_not_use_the_invented_btn_vocabulary(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read; local plugin file.
		$source = (string) file_get_contents( EXTRACHILL_USERS_PLUGIN_DIR . self::TEMPLATE );
		$source = (string) preg_replace( '#^<\?php\s*/\*\*.*?\*/#s', '', $source );

		$this->assertDoesNotMatchRegularExpression(
			'/class="[^"]*\b(btn|btn--[a-z]+|notice--[a-z]+)\b/',
			$source,
			self::TEMPLATE . ' uses .btn / .btn--* / .notice--*, which do not exist in the extrachill theme'
		);
	}

	public function test_dynamic_button_class_is_limited_to_theme_variants(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read; local plugin file.
		$source = (string) file_get_contents( EXTRACHILL_USERS_PLUGIN_DIR . self::TEMPLATE );

		preg_match_all( '/\\$button_class\s*=\s*([^;]+);/', $source, $matches );

		$this->assertNotEmpty(
			$matches[1],
			self::TEMPLATE . ' must resolve $button_class somewhere.'
		);

		foreach ( $matches[1] as $assignment ) {
			preg_match_all( "/'(button-[a-z0-9]+)'/", $assignment, $variants );

			foreach ( $variants[1] as $variant ) {
				$this->assertContains(
					$variant,
					self::THEME_CLASSES,
					"\$button_class assigns {$variant}, which the extrachill theme does not define."
				);
			}
		}
	}
}

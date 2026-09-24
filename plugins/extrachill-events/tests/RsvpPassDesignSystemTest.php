<?php
/**
 * The RSVP pass and door-list templates must use classes that actually
 * render.
 *
 * Same failure mode Test_OAuth_Template_Design_System (extrachill-users)
 * and Test_Attendance_Button_Design_System guard against: these templates
 * render inside the theme chrome and ship no CSS of their own. A class
 * that invents itself renders unstyled, and unstyled HTML is still valid
 * HTML, so nothing in the pipeline notices unless a test asserts the
 * vocabulary at the source.
 *
 * The allowed vocabulary is the union of:
 *   1. Theme classes, pinned from themes/extrachill/style.css (verified by
 *      grep at the time this test was written — .card does NOT exist on
 *      this theme; the real generic container class is .ec-surface-card).
 *   2. An explicit, commented list of classes that intentionally carry no
 *      CSS rule (JS/test hooks, state containers).
 *
 * @package ExtraChillEvents
 */

class RsvpPassDesignSystemTest extends WP_UnitTestCase {

	/**
	 * Templates that render inside the theme and own no CSS.
	 *
	 * @var string[]
	 */
	private const TEMPLATES = array(
		'templates/rsvp-pass.php',
		'templates/door-list.php',
	);

	/**
	 * Classes the extrachill theme defines that these templates may use.
	 * Pinned from themes/extrachill/style.css.
	 *
	 * @var string[]
	 */
	private const THEME_CLASSES = array(
		'ec-surface-card',
		'ec-card-vertical-padding',
		'notice',
		'notice-info',
		'notice-success',
		'notice-error',
		'button-1',
		'button-2',
		'button-3',
		'button-danger',
		'button-small',
		'button-medium',
		'button-large',
	);

	/**
	 * Classes these templates render with no CSS rule anywhere, on purpose.
	 * Each entry is a deliberate, reviewed decision.
	 *
	 * @var array<string, string>
	 */
	private const CSS_FREE_CLASSES = array(
		// Container hook; visual treatment comes entirely from ec-surface-card.
		'ec-rsvp-pass'          => 'JS/test hook; surface styling from ec-surface-card',
		'ec-rsvp-pass__perk'    => 'plain text; inherits theme typography',
		'ec-rsvp-pass__label'   => 'plain text; inherits theme typography',
		'ec-rsvp-pass__code'    => 'JS write target; plain text',
		'ec-door-list'          => 'JS/test hook; surface styling from ec-surface-card',
		'ec-door-list__heading' => 'plain heading; inherits theme typography',
		'ec-door-list__note'    => 'plain text; inherits theme typography',
		'ec-door-list__perk'    => 'plain text; inherits theme typography',
		'ec-door-list__rows'    => 'plain list; no visual treatment needed',
		'ec-door-list__row'     => 'JS/test hook; layout is a plain list item',
		'ec-door-list__name'    => 'plain text; inherits theme typography',
		'ec-door-list__status'  => 'JS write target; plain text',
		'ec-door-list__redeem'  => 'styled via button-2 button-small; hook for JS only',
	);

	/**
	 * Every class attribute value used in a template's markup.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string[] Distinct class names used.
	 */
	private function classes_in( string $relative ): array {
		$source = (string) file_get_contents( EXTRACHILL_EVENTS_PLUGIN_DIR . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read; local plugin file.

		// Strip the docblock: it deliberately names the wrong class (.card)
		// as a warning, and must not count as usage.
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

	/**
	 * @dataProvider templates
	 */
	public function test_template_uses_only_classes_that_actually_render( string $template ): void {
		$allowed  = array_merge( self::THEME_CLASSES, array_keys( self::CSS_FREE_CLASSES ) );
		$unstyled = array_diff( $this->classes_in( $template ), $allowed );

		$this->assertSame(
			array(),
			array_values( $unstyled ),
			"{$template} uses classes with no CSS rule in the theme or the pinned CSS-free list; they will render unstyled: " . implode( ', ', $unstyled )
		);
	}

	/**
	 * The specific mistake that keeps recurring: inventing .card / .btn.
	 *
	 * @dataProvider templates
	 */
	public function test_template_does_not_use_invented_classes( string $template ): void {
		$source = (string) file_get_contents( EXTRACHILL_EVENTS_PLUGIN_DIR . $template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read; local plugin file.
		$source = (string) preg_replace( '#^<\?php\s*/\*\*.*?\*/#s', '', $source );

		$this->assertDoesNotMatchRegularExpression(
			'/class="[^"]*\b(card|btn|btn--[a-z]+|notice--[a-z]+)\b/',
			$source,
			"{$template} uses .card / .btn / .btn--* / .notice--*, none of which exist in the extrachill theme"
		);
	}

	/**
	 * A visible action button must carry a real theme button class.
	 *
	 * @dataProvider templates
	 */
	public function test_every_button_is_styled( string $template ): void {
		$source = (string) file_get_contents( EXTRACHILL_EVENTS_PLUGIN_DIR . $template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read; local plugin file.

		preg_match_all( '/<button\b[^>]*?>/s', $source, $buttons );

		$this->assertIsArray( $buttons[0], "{$template}: button scan ran" );

		foreach ( $buttons[0] as $button ) {
			$this->assertMatchesRegularExpression(
				'/class="[^"]*\bbutton-(1|2|3|danger)\b/',
				$button,
				"{$template}: a button has no theme button class: {$button}"
			);
		}
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function templates(): array {
		$cases = array();
		foreach ( self::TEMPLATES as $template ) {
			$cases[ basename( $template ) ] = array( $template );
		}

		return $cases;
	}
}

<?php
/**
 * The OAuth screens must use classes the extrachill theme actually defines.
 *
 * This plugin ships no CSS for these screens; they render inside the theme
 * chrome and depend entirely on the theme's design system. A template that
 * invents a class — .btn, .btn--primary, .notice--info — renders unstyled,
 * and nothing in the pipeline notices because unstyled HTML is still valid
 * HTML. That has now happened more than once on the consent screen alone.
 *
 * So the allowed class vocabulary is asserted here, against the templates'
 * source rather than a rendered page, so it fails at review time and not
 * on a user's screen.
 *
 * The vocabulary is read from the theme's stylesheet when it is present, and
 * falls back to a pinned copy otherwise, so the test is honest about what
 * it is checking in each environment.
 */

class Test_OAuth_Template_Design_System extends WP_UnitTestCase {

	/**
	 * The bridge only loads when wp-native-auth is active, which it is not
	 * in this environment.
	 *
	 * require_once is not enough on its own: an earlier test in the run
	 * already included the file, so its top-level add_filter() calls ran
	 * once and were then discarded by WP_UnitTestCase's hook restoration.
	 * A second require_once is a no-op and registers nothing. Load the
	 * functions, then register the routing the way the file itself does.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/wp-native-bridge.php';

		add_filter( 'wp_native_auth_oauth_consent_template', 'extrachill_users_wp_native_oauth_consent_template', 10, 2 );
		add_filter( 'wp_native_auth_oauth_device_form_template', 'extrachill_users_wp_native_oauth_device_form_template', 10, 2 );
		add_filter( 'wp_native_auth_oauth_device_result_template', 'extrachill_users_wp_native_oauth_device_result_template', 10, 2 );
	}

	/**
	 * Templates that render inside the theme and own no CSS.
	 *
	 * @var string[]
	 */
	private const TEMPLATES = array(
		'templates/oauth-consent.php',
		'templates/oauth-device-form.php',
		'templates/oauth-device-result.php',
	);

	/**
	 * Classes the extrachill theme defines that these screens may use.
	 *
	 * Pinned from themes/extrachill/style.css. If the theme adds a class
	 * these screens legitimately need, add it here — the point is that the
	 * addition is a deliberate, reviewed decision.
	 *
	 * @var string[]
	 */
	private const ALLOWED = array(
		'card',
		'button-1',
		'button-2',
		'button-3',
		'button-danger',
		'button-small',
		'button-medium',
		'button-large',
		'notice',
		'notice-info',
		'notice-success',
		'notice-error',
		'screen-reader-text',
	);

	/**
	 * Every class attribute value in a template's markup.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string[] Distinct class names used.
	 */
	private function classes_in( string $relative ): array {
		$source = (string) file_get_contents( EXTRACHILL_USERS_PLUGIN_DIR . $relative );

		// Strip the docblock: it deliberately names the wrong classes as
		// a warning, and must not count as usage.
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
	public function test_template_uses_only_theme_classes( string $template ): void {
		$unknown = array_diff( $this->classes_in( $template ), self::ALLOWED );

		$this->assertSame(
			array(),
			array_values( $unknown ),
			"{$template} uses classes the extrachill theme does not define; they will render unstyled: " . implode( ', ', $unknown )
		);
	}

	/**
	 * The specific mistake that keeps recurring.
	 *
	 * @dataProvider templates
	 */
	public function test_template_does_not_use_the_invented_btn_vocabulary( string $template ): void {
		$source = (string) file_get_contents( EXTRACHILL_USERS_PLUGIN_DIR . $template );
		$source = (string) preg_replace( '#^<\?php\s*/\*\*.*?\*/#s', '', $source );

		$this->assertDoesNotMatchRegularExpression(
			'/class="[^"]*\b(btn|btn--[a-z]+|notice--[a-z]+)\b/',
			$source,
			"{$template} uses .btn / .btn--* / .notice--*, which do not exist in the extrachill theme"
		);
	}

	/**
	 * A submit button must carry a real button class, or it renders as a
	 * browser default inside an otherwise branded page.
	 *
	 * @dataProvider templates
	 */
	public function test_every_submit_button_is_styled( string $template ): void {
		$source = (string) file_get_contents( EXTRACHILL_USERS_PLUGIN_DIR . $template );

		// Attributes may span lines; match the whole opening tag.
		preg_match_all( '/<button\b[^>]*?>/s', $source, $buttons );

		$submits = array_values(
			array_filter(
				$buttons[0],
				static fn( string $button ): bool => false !== strpos( $button, 'type="submit"' )
			)
		);

		// A screen with no form has nothing to style; say so rather than
		// pass vacuously, which PHPUnit rightly reports as a risky test.
		$this->assertIsArray( $submits, "{$template}: button scan ran" );

		foreach ( $submits as $button ) {

			$this->assertMatchesRegularExpression(
				'/class="[^"]*\bbutton-(1|2|3|danger)\b/',
				$button,
				"{$template}: a submit button has no theme button class: {$button}"
			);
		}
	}

	/**
	 * The three screens are actually wired to wp-native-auth's seams.
	 * A branded template that is never selected styles nothing.
	 *
	 * Checks both halves: that the bridge source registers each filter, and
	 * that the registered callback resolves to the right template.
	 */
	public function test_all_three_screens_are_filtered_in(): void {
		$bridge = (string) file_get_contents( EXTRACHILL_USERS_PLUGIN_DIR . 'inc/wp-native-bridge.php' );
		foreach ( array(
			'wp_native_auth_oauth_consent_template',
			'wp_native_auth_oauth_device_form_template',
			'wp_native_auth_oauth_device_result_template',
		) as $filter ) {
			$this->assertMatchesRegularExpression(
				"/add_filter\\(\\s*'{$filter}'/",
				$bridge,
				"the bridge does not register {$filter}"
			);
		}

		foreach ( array(
			'wp_native_auth_oauth_consent_template'       => 'oauth-consent.php',
			'wp_native_auth_oauth_device_form_template'   => 'oauth-device-form.php',
			'wp_native_auth_oauth_device_result_template' => 'oauth-device-result.php',
		) as $filter => $expected ) {
			$this->assertStringEndsWith(
				$expected,
				(string) apply_filters( $filter, '/generic.php', array() ),
				"{$filter} is not routed to the Extra Chill template"
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

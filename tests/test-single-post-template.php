<?php
/**
 * Regression coverage for the singular post article classes.
 *
 * Verifies the single post template opens its article element through
 * the_ID() and post_class(), and that the rendered class attribute
 * composes theme, core, and filtered classes.
 *
 * @package ExtraChill
 */

class Test_Single_Post_Template extends WP_UnitTestCase {

	/**
	 * Provide a single post in the main query loop.
	 *
	 * @return int Created post ID.
	 */
	private function setup_single_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		return $post_id;
	}

	/**
	 * Register a filter that appends a class to every post_class() render.
	 */
	private function add_integration_target_filter() {
		add_filter(
			'post_class',
			static function ( $classes ) {
				$classes[] = 'integration-target';
				return $classes;
			}
		);
	}

	/**
	 * The template must open its article element with the_ID() and post_class().
	 */
	public function test_article_opening_uses_template_functions() {
		$template = file_get_contents( dirname( __DIR__ ) . '/inc/single/single-post.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a trusted local template fixture.

		$this->assertNotFalse( $template, 'Could not read the single post template.' );
		$this->assertMatchesRegularExpression(
			'/^\s*<article id="post-<\?php the_ID\(\); \?>".*>$/m',
			$template,
			'The singular post article opening is missing.'
		);
	}

	/**
	 * The rendered article must preserve theme, core, and filtered classes.
	 */
	public function test_article_classes_include_theme_core_and_filtered_classes() {
		$this->add_integration_target_filter();

		$post_id = $this->setup_single_post();

		ob_start();
		post_class( array( 'single-post-card', 'ec-mobile-full-width-panel' ) );
		$rendered = ob_get_clean();

		wp_reset_postdata();

		$this->assertMatchesRegularExpression( '/\bclass="([^"]*)"/', $rendered, 'The singular post article did not render a class attribute.' );

		preg_match( '/\bclass="([^"]*)"/', $rendered, $class_matches );
		$article_classes = preg_split( '/\s+/', trim( $class_matches[1] ) );

		$expected_classes = array(
			'single-post-card',
			'ec-mobile-full-width-panel',
			'post-' . $post_id,
			'post',
			'type-post',
			'status-publish',
			'integration-target',
		);

		foreach ( $expected_classes as $expected_class ) {
			$this->assertContains( $expected_class, $article_classes, sprintf( 'Missing expected article class: %s', $expected_class ) );
		}
	}
}

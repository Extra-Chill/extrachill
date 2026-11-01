<?php
/**
 * "Did this help?" feedback on docs articles.
 *
 * Renders Yes/No buttons after an article. The clicks are recorded by the
 * network-wide CTA tracker in extrachill-analytics, which records a
 * `cta_click` for any `[data-ec-track]` element together with the page URL.
 * This file only owns the markup; it adds no endpoint, table or event type.
 *
 * @package ExtraChillDocs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current view is a docs article that should ask for feedback.
 *
 * Articles are child pages. The homepage and top-level
 * section landing pages are navigation, not answers, so they are skipped.
 *
 * @return bool
 */
function extrachill_docs_feedback_applies() {
	if ( is_admin() || is_front_page() || ! is_singular( 'page' ) ) {
		return false;
	}

	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return false;
	}

	return (int) $post->post_parent > 0;
}

/**
 * Feedback block markup.
 *
 * @return string
 */
function extrachill_docs_feedback_markup() {
	$support_url = trailingslashit( ec_get_site_url( 'community' ) ) . 'r/tech-support';

	ob_start();
	?>
	<section class="docs-feedback" data-ec-track-placement="doc-feedback" aria-labelledby="docs-feedback-question">
		<p class="docs-feedback__question" id="docs-feedback-question"><?php esc_html_e( 'Did this help?', 'extrachill-docs' ); ?></p>
		<div class="docs-feedback__actions">
			<button type="button" class="button-2 button-small" data-ec-track="doc-helpful-yes" data-docs-feedback="yes"><?php esc_html_e( 'Yes', 'extrachill-docs' ); ?></button>
			<button type="button" class="button-2 button-small" data-ec-track="doc-helpful-no" data-docs-feedback="no"><?php esc_html_e( 'No', 'extrachill-docs' ); ?></button>
		</div>
		<p class="docs-feedback__reply" data-docs-feedback-reply="yes" hidden><?php esc_html_e( 'Thanks for letting us know.', 'extrachill-docs' ); ?></p>
		<p class="docs-feedback__reply" data-docs-feedback-reply="no" hidden>
			<?php esc_html_e( 'Sorry this page missed. Ask in the', 'extrachill-docs' ); ?>
			<a href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Tech Support forum', 'extrachill-docs' ); ?></a>
			<?php esc_html_e( 'and we will help, then fix the page.', 'extrachill-docs' ); ?>
		</p>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Print the feedback block after the article body.
 *
 * The theme fires extrachill_after_post_content inside archive cards too, so
 * only the queried post's own render counts.
 *
 * @return void
 */
function extrachill_docs_render_feedback() {
	if ( ! extrachill_docs_feedback_applies() || get_the_ID() !== get_queried_object_id() ) {
		return;
	}

	static $rendered = false;
	if ( $rendered ) {
		return;
	}
	$rendered = true;

	echo extrachill_docs_feedback_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in extrachill_docs_feedback_markup().
}
add_action( 'extrachill_after_page_content', 'extrachill_docs_render_feedback' );
add_action( 'extrachill_after_post_content', 'extrachill_docs_render_feedback' );

/**
 * Enqueue the feedback script on articles.
 *
 * @return void
 */
function extrachill_docs_enqueue_feedback() {
	if ( ! extrachill_docs_feedback_applies() ) {
		return;
	}

	$js_path = EXTRACHILL_DOCS_PLUGIN_DIR . 'assets/js/docs-feedback.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-docs-feedback',
		EXTRACHILL_DOCS_PLUGIN_URL . 'assets/js/docs-feedback.js',
		array(),
		(string) filemtime( $js_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_docs_enqueue_feedback' );

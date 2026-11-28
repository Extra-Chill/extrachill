<?php
/**
 * "Did this help?" feedback on docs articles.
 *
 * Renders Yes/No buttons after an article. The clicks are recorded by the
 * network-wide CTA tracker; the No form submits through the Abilities API.
 *
 * @package ExtraChillDocs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a post is a public docs child article eligible for feedback.
 *
 * @param object|null $post Candidate queried object or post.
 * @return bool
 */
function extrachill_docs_is_feedback_article( $post ) {
	return function_exists( 'ec_get_blog_id' )
		&& ec_get_blog_id( 'docs' ) === get_current_blog_id()
		&& $post instanceof WP_Post
		&& 'page' === $post->post_type
		&& 'publish' === $post->post_status
		&& '' === $post->post_password
		&& (int) $post->post_parent > 0
		&& ! ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID );
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

	return extrachill_docs_is_feedback_article( get_queried_object() );
}

/**
 * Feedback block markup.
 *
 * @return string
 */
function extrachill_docs_feedback_markup() {
	if ( ! extrachill_docs_feedback_applies() ) {
		return '';
	}

	$support_url = trailingslashit( ec_get_site_url( 'community' ) ) . 'r/tech-support';
	$widget      = function_exists( 'ec_render_turnstile_widget' ) ? ec_render_turnstile_widget( array( 'id' => 'docs-feedback-turnstile' ) ) : '';
	$logged_in   = '' !== $widget && is_user_logged_in();

	ob_start();
	?>
	<section data-docs-feedback-block data-ec-track-placement="doc-feedback" aria-labelledby="docs-feedback-question">
		<p id="docs-feedback-question"><?php esc_html_e( 'Did this help?', 'extrachill-docs' ); ?></p>
		<div data-docs-feedback-actions>
			<button type="button" class="button-2 button-small" data-ec-track="doc-helpful-yes" data-docs-feedback="yes"><?php esc_html_e( 'Yes', 'extrachill-docs' ); ?></button>
			<button type="button" class="button-2 button-small" data-ec-track="doc-helpful-no" data-docs-feedback="no"><?php esc_html_e( 'No', 'extrachill-docs' ); ?></button>
		</div>
		<p data-docs-feedback-reply="yes" hidden><?php esc_html_e( 'Thanks for letting us know.', 'extrachill-docs' ); ?></p>
		<div data-docs-feedback-reply="no" hidden>
			<?php if ( '' !== $widget ) : ?>
				<form class="ec-form-stack" method="post" data-docs-feedback-form
					data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/extrachill/docs-feedback-submit/run' ) ); ?>"
					data-post-id="<?php echo esc_attr( (string) get_queried_object_id() ); ?>"
					data-nonce="<?php echo $logged_in ? esc_attr( wp_create_nonce( 'wp_rest' ) ) : ''; ?>"
					data-error="<?php esc_attr_e( 'Feedback could not be sent. Please try again.', 'extrachill-docs' ); ?>"
					data-challenge-error="<?php esc_attr_e( 'Please complete the security verification.', 'extrachill-docs' ); ?>">
					<label for="docs-feedback-message"><?php esc_html_e( 'What were you looking for?', 'extrachill-docs' ); ?></label>
					<textarea id="docs-feedback-message" name="message" rows="3" required maxlength="2000"></textarea>
					<?php if ( ! $logged_in ) : ?>
						<label for="docs-feedback-email"><?php esc_html_e( 'Email (optional, if you want a reply)', 'extrachill-docs' ); ?></label>
						<input id="docs-feedback-email" type="email" name="email" autocomplete="email" maxlength="254">
					<?php endif; ?>
					<template><?php echo $widget; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the network widget renderer. ?></template>
					<button type="submit" class="button-1 button-small"><?php esc_html_e( 'Send', 'extrachill-docs' ); ?></button>
					<p class="notice notice-error" data-docs-feedback-error role="alert" hidden></p>
				</form>
				<p class="notice notice-success" data-docs-feedback-success role="status" tabindex="-1" hidden><?php esc_html_e( 'Thanks, this goes straight to the person who maintains these pages.', 'extrachill-docs' ); ?></p>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'For help, you can also ask in the', 'extrachill-docs' ); ?>
				<a href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Tech Support forum', 'extrachill-docs' ); ?></a>.
			</p>
		</div>
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

	if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
		ec_enqueue_turnstile_script();
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

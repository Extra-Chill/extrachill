<?php
/**
 * Logged-Out Comment Login CTA
 *
 * Renders a login/register call-to-action above the comments template for
 * logged-out visitors on any singular content with open comments, network-wide.
 *
 * The active theme fires `extrachill_before_comments_template` and renders only
 * a bare "Log in to leave a comment." note for logged-out users, expecting a
 * plugin to supply the actual CTA. This plugin is network-active and owns the
 * `extrachill/login-register` block, so it owns the CTA for every site and
 * post type (blog posts, newsletters, events, etc.).
 *
 * @package ExtraChill\Users
 * @since 0.52.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determine whether the logged-out comment login CTA should render.
 *
 * Requires a singular view with open comments, no password gate, and a
 * logged-out visitor. Sites can opt out via the
 * `extrachill_users_show_comment_login_cta` filter.
 *
 * @return bool True when the CTA should render.
 */
function extrachill_users_should_show_comment_login_cta(): bool {
	if ( is_user_logged_in() ) {
		return false;
	}

	if ( ! is_singular() ) {
		return false;
	}

	if ( ! comments_open() ) {
		return false;
	}

	if ( post_password_required() ) {
		return false;
	}

	/**
	 * Filter whether the logged-out comment login CTA renders.
	 *
	 * Runs only after the structural gates (singular, comments open, not
	 * password-protected, logged out) have already passed, so returning true
	 * cannot force the CTA onto ineligible views.
	 *
	 * @param bool $show Whether to render the CTA. Default true.
	 */
	return (bool) apply_filters( 'extrachill_users_show_comment_login_cta', true );
}

/**
 * Render the login/register CTA before the comments template.
 */
function extrachill_users_render_comment_login_cta(): void {
	if ( ! extrachill_users_should_show_comment_login_cta() ) {
		return;
	}

	echo '<div class="comment-login-cta">';
	echo '<h3>' . esc_html__( 'Login or Register to Comment', 'extrachill-users' ) . '</h3>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() returns block-rendered markup escaped at the source.
	echo do_blocks( '<!-- wp:extrachill/login-register /-->' );
	echo '</div>';
}
add_action( 'extrachill_before_comments_template', 'extrachill_users_render_comment_login_cta' );

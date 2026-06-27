<?php
/**
 * Login / Register CTA — Single Post Bridge (thin consumer)
 *
 * Surfaces the membership login/register affordance to logged-out readers on
 * single posts, just above the comments section. This is the PLACEMENT decision
 * for that CTA — it belongs to the site-specific blog plugin, NOT to the shared
 * theme.
 *
 * The block itself (`extrachill/login-register`) is owned and registered by the
 * extrachill-users plugin. This file only decides WHEN and WHERE it renders by
 * hooking a theme-exposed single-article action, mirroring the pattern in
 * network-bridge.php exactly. The theme stays generic: it exposes hooks; the
 * feature plugins decide what fills them.
 *
 * @package ExtraChillBlog
 * @since 0.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the "Login or Register to Comment" CTA on single posts.
 *
 * Hooked on `extrachill_before_comments_template` so it renders just above the
 * comments section inside the post aside, keeping the membership affordance in
 * its natural comment context — but plugin-owned rather than hardcoded in the
 * theme.
 *
 * Guards:
 * - Single `post` views only.
 * - Logged-out readers only (logged-in users get the native comment form, which
 *   the theme's comments template still renders).
 * - Renders nothing if the login-register block is not registered (extrachill-users
 *   inactive), so the theme never depends on a feature plugin being present.
 */
function extrachill_blog_login_register_cta() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	if ( is_user_logged_in() ) {
		return;
	}

	if ( ! function_exists( 'do_blocks' ) || ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return;
	}

	if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'extrachill/login-register' ) ) {
		return;
	}

	echo '<h3>' . esc_html__( 'Login or Register to Comment', 'extrachill-blog' ) . '</h3>';
	echo do_blocks( '<!-- wp:extrachill/login-register /-->' );
}
add_action( 'extrachill_before_comments_template', 'extrachill_blog_login_register_cta' );

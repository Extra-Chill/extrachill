<?php
/**
 * Emoji Deprecation Silence
 *
 * WordPress core retains the deprecated `print_emoji_styles()` function hooked
 * to `wp_print_styles` / `admin_print_styles` for backwards-compatibility (see
 * wp-includes/default-filters.php). Since WP 6.4 the supported path is
 * `wp_enqueue_emoji_styles()` (hooked to `wp_enqueue_scripts`), which is meant
 * to unhook the deprecated action on normal page loads.
 *
 * On request paths that print styles without firing `wp_enqueue_scripts`
 * (feeds, certain admin-ajax / REST contexts, embeds), the unhook never runs
 * and core calls the deprecated `print_emoji_styles()`, emitting:
 *
 *   "Function print_emoji_styles is deprecated since version 6.4.0!
 *    Use wp_enqueue_emoji_styles instead."
 *
 * With WP_DEBUG logging on, this fired ~640-840 times/day per site and was the
 * single loudest line in debug.log network-wide, burying actionable warnings.
 *
 * Fix: explicitly remove the deprecated `print_emoji_styles` action on both the
 * frontend (`wp_print_styles`) and admin (`admin_print_styles`) hooks. The
 * modern `wp_enqueue_emoji_styles()` action stays registered on
 * `wp_enqueue_scripts`, so emoji styles continue to load via the supported path
 * on normal page loads — only the deprecated code path (and its notice) is
 * silenced. Network-active, so this applies to all sites.
 *
 * @package ExtraChill_Network
 * @since 1.20.0
 */

/**
 * Remove the deprecated `print_emoji_styles` action so core stops emitting the
 * WP 6.4 deprecation notice on request paths that skip `wp_enqueue_scripts`.
 *
 * Emoji rendering is preserved via core's modern `wp_enqueue_emoji_styles()`
 * (registered on `wp_enqueue_scripts`), which remains untouched.
 */
function extrachill_network_silence_emoji_deprecation() {
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
}
add_action( 'init', 'extrachill_network_silence_emoji_deprecation' );

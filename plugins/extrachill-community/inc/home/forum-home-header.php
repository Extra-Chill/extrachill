<?php
/**
 * Forum Homepage Header
 *
 * Template component loaded via extrachill_community_home_header action hook (not via extrachill_community_init).
 * Provides the page header for community.extrachill.com homepage.
 * Registered by inc/home/actions.php.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<h1><?php esc_html_e('Extra Chill Community', 'extra-chill-community'); ?></h1>

<p><?php esc_html_e('A hub for the underground music community, bringing artists and fans of DIY music together', 'extra-chill-community'); ?></p>

<div class="homepage-top-actions">
    <?php if (is_user_logged_in()) : ?>
        <a href="<?php echo bbp_get_user_profile_url(get_current_user_id()); ?>" class="button profile-btn"><?php esc_html_e('View Profile', 'extra-chill-community'); ?></a>
        <a href="/settings" class="button settings-btn"><?php esc_html_e('Settings', 'extra-chill-community'); ?></a>
    <?php else : ?>
        <a href="/login" class="button login-btn"><?php esc_html_e('Log In', 'extra-chill-community'); ?></a>
        <a href="/login?register=1" class="button signup-btn"><?php esc_html_e('Sign Up', 'extra-chill-community'); ?></a>
    <?php endif; ?>
</div>

<?php do_action('extrachill_community_below_home_header'); ?>

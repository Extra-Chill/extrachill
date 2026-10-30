<?php
/**
 * User Profile
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

do_action('bbp_template_before_user_profile');
?>
	
<div id="bbp-user-profile" class="bbp-user-profile">
	<?php bbp_get_template_part( 'user-details' ); ?>
	
	<?php do_action( 'bbp_template_before_user_details_menu_items' ); ?>

<?php
// The hero owns all identity/summary data now: the meta line (join date +
// activity counts, ex-Community Activity card) and the identity line
// (Local Scene + artist memberships, ex-Artists card). The only body card
// left in this template is the bio.
$about_description = bbp_get_displayed_user_field('description');
if ( ! empty( $about_description ) ) :
	?>
<div class="bbp-user-profile-card ec-about-card">
	<h3><?php esc_html_e( 'About', 'extra-chill-community' ); ?></h3>
	<p class="bbp-user-description"><?php echo wp_kses_post( bbp_rel_nofollow( $about_description ) ); ?></p>
</div>
<?php endif; ?>

</div><!-- #bbp-user-profile -->

<?php do_action('bbp_template_after_user_profile'); ?>

<?php
/**
 * Homepage Community Card
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$is_logged_in = is_user_logged_in();
$profile_url  = '';

if ( $is_logged_in && function_exists( 'ec_get_user_profile_url' ) ) {
	$profile_url = ec_get_user_profile_url( get_current_user_id() );
}
?>
<div class="home-network-card" aria-labelledby="community-header">
	<h2 class="home-network-card-header" id="community-header">Community</h2>
	<p class="home-network-card-description">
		Talk music, share your work, and connect with fans and fellow artists. Our forums are open to everyone in the Extra Chill network.
	</p>
	<div class="home-network-card-cta" style="display: flex; gap: var(--spacing-sm); flex-wrap: wrap; justify-content: center;">
		<?php if ( $is_logged_in && $profile_url ) : ?>
			<a href="<?php echo esc_url( $profile_url ); ?>" class="button-2 button-medium">Your Profile</a>
		<?php else : ?>
			<a href="https://community.extrachill.com" class="button-2 button-medium">Join the Conversation</a>
		<?php endif; ?>
		<a href="https://community.extrachill.com/recent" class="button-3 button-medium">Recent Activity</a>
	</div>
</div>

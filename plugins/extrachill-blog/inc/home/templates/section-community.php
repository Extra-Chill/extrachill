<?php
/**
 * Homepage Community Card
 *
 * Shows the latest items of the one community activity feed (forum topics
 * and replies plus article and event comments) via the extrachill-network
 * community-activity adapter (a cached cross-site read of the feed), with
 * CTAs below.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$is_logged_in = is_user_logged_in();
$profile_url  = '';

if ( $is_logged_in && function_exists( 'extrachill_get_user_profile_url' ) ) {
	$profile_url = extrachill_get_user_profile_url( get_current_user_id() );
	if ( $profile_url ) {
		$profile_url = extrachill_blog_bridge_url( $profile_url, 'community' );
	}
}

// Every item type: forum topics and replies, article comments, and event
// comments, in feed order.
$community_activity = function_exists( 'extrachill_get_community_activity_items' )
	? extrachill_get_community_activity_items( 3 )
	: array();
?>
<div class="home-network-card" aria-labelledby="community-header">
	<h2 class="home-network-card-header" id="community-header"><?php esc_html_e( 'Community', 'extrachill-blog' ); ?></h2>
	<p class="home-network-card-description">
		<?php esc_html_e( 'Talk music, share your work, and connect with fans and fellow artists. Our forums are open to everyone in the Extra Chill network.', 'extrachill-blog' ); ?>
	</p>
	<?php if ( ! empty( $community_activity ) ) : ?>
		<div class="home-community-activity">
			<?php
			foreach ( $community_activity as $activity ) :
				// Article comments link within this site; everything else
				// (forum items, event comments) is a bridged cross-site link.
				$activity_site = isset( $activity['site_key'] ) ? (string) $activity['site_key'] : 'community';
				$is_cross_site = 'main' !== $activity_site;
				$activity_url  = $is_cross_site ? extrachill_blog_bridge_url( $activity['topic_url'], $activity_site ) : $activity['topic_url'];
				?>
				<a class="<?php echo esc_attr( $is_cross_site ? 'home-community-activity-item ec-cross-site-link' : 'home-community-activity-item' ); ?>" href="<?php echo esc_url( $activity_url ); ?>">
					<span class="home-community-activity-title"><?php echo esc_html( $activity['topic_title'] ); ?></span>
					<span class="home-community-activity-meta">
						<?php
						printf(
							/* translators: 1: username, 2: activity type (Topic/Reply/Comment) */
							esc_html__( '%1$s · %2$s', 'extrachill-blog' ),
							esc_html( $activity['username'] ),
							esc_html( $activity['type'] )
						);
						?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<div class="home-network-card-cta home-network-card-cta-row">
		<?php if ( $is_logged_in && $profile_url ) : ?>
			<a href="<?php echo esc_url( $profile_url ); ?>" class="button-2 button-medium ec-cross-site-link"><?php esc_html_e( 'Your Profile', 'extrachill-blog' ); ?></a>
		<?php else : ?>
			<a href="<?php echo esc_url( extrachill_blog_bridge_url( 'https://community.extrachill.com', 'community' ) ); ?>" class="button-2 button-medium ec-cross-site-link"><?php esc_html_e( 'Join the Conversation', 'extrachill-blog' ); ?></a>
		<?php endif; ?>
		<a href="<?php echo esc_url( extrachill_blog_bridge_url( 'https://community.extrachill.com/recent', 'community' ) ); ?>" class="button-3 button-medium ec-cross-site-link"><?php esc_html_e( 'Recent Activity', 'extrachill-blog' ); ?></a>
	</div>
</div>

<?php
/**
 * New Topic Button
 *
 * Renders the "New Post" button that triggers the topic-creation modal on
 * the community homepage.
 *
 * No-JS fallback: the button's href points at the Music Discussion forum
 * permalink, where bbPress renders a real "new topic" form
 * (bbpress/content-single-forum.php → form/topic). The previous fallback
 * pointed at the homepage ("/"), but the feed-first homepage (#66) no longer
 * carries an inline topic form, so a no-JS user had no way to create a topic.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined('ABSPATH') ) {
	exit;
}
?>

<?php
$music_discussion_forum = get_page_by_path( 'music-discussion', OBJECT, bbp_get_forum_post_type() );

// No-JS fallback target: the forum permalink (which renders a topic form).
// Fall back to the recent activity page if the forum can't be resolved.
$new_topic_fallback_url = ( $music_discussion_forum instanceof WP_Post )
	? get_permalink( $music_discussion_forum )
	: home_url( '/recent' );
?>

<div class="community-section-header">
	<div class="community-home-topic-actions">
		<a href="<?php echo esc_url( $new_topic_fallback_url ); ?>" id="new-topic-modal-trigger" class="button-1 button-medium"><?php esc_html_e( 'New Post', 'extra-chill-community' ); ?></a>
	</div>
</div>

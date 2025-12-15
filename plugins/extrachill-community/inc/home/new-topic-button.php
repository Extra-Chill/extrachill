<?php
/**
 * New Topic Button
 *
 * Renders the "New Topic" button that triggers the modal on the community homepage.
 * Fallback href for no-JS navigates to homepage where forum topic forms exist.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<?php
$music_discussion_forum = get_page_by_path( 'music-discussion', OBJECT, bbp_get_forum_post_type() );
?>

<div class="community-home-topic-actions">
    <a href="/" id="new-topic-modal-trigger" class="button-1 button-medium" data-modal-mode="discussion"><?php esc_html_e( 'Create Discussion', 'extra-chill-community' ); ?></a>

    <?php if ( $music_discussion_forum instanceof WP_Post ) : ?>
        <a href="/" id="share-music-modal-trigger" class="button-2 button-medium" data-modal-mode="share_music" data-forum-id="<?php echo esc_attr( (string) $music_discussion_forum->ID ); ?>"><?php esc_html_e( 'Share Music', 'extra-chill-community' ); ?></a>
    <?php endif; ?>
</div>

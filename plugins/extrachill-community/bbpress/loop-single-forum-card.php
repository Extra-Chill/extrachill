<?php

/**
 * Forums Loop - Single Forum Card
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<?php
$forum_card_classes    = array( 'bbp-forum-card', 'ec-surface-card', 'ec-mobile-edge-surface' );
$forum_card_style      = '';
$forum_location_terms  = wp_get_object_terms( bbp_get_forum_id(), 'location', array( 'fields' => 'slugs' ) );
if ( ! empty( $forum_location_terms ) && ! is_wp_error( $forum_location_terms ) ) {
	$location_slug         = $forum_location_terms[0];
	$forum_card_classes[]  = 'location-' . $location_slug;
	$forum_card_style      = '--forum-location-border-color: var(--badge-location-' . esc_attr( $location_slug ) . '-bg);';
}
?>
<div id="bbp-forum-card-<?php bbp_forum_id(); ?>" class="<?php echo esc_attr( implode( ' ', $forum_card_classes ) ); ?>"<?php echo $forum_card_style ? ' style="' . esc_attr( $forum_card_style ) . '"' : ''; ?>>
    <div class="bbp-forum-info">
        <?php do_action( 'bbp_theme_before_forum_title' ); ?>
        <a class="bbp-forum-title" href="<?php bbp_forum_permalink(); ?>"><?php bbp_forum_title(); ?></a>
        <?php do_action( 'bbp_theme_after_forum_title' ); ?>

        <?php do_action( 'bbp_theme_before_forum_description' ); ?>
        <div class="bbp-forum-content"><?php bbp_forum_content(); ?></div>
        <?php do_action( 'bbp_theme_after_forum_description' ); ?>

        <?php do_action( 'bbp_theme_before_forum_sub_forums' ); ?>
        <?php bbp_list_forums(); ?>
        <?php do_action( 'bbp_theme_after_forum_sub_forums' ); ?>
    </div>

    <div class="bbp-forum-stats">
        <div class="bbp-forum-topic-count">
            <?php bbp_forum_topic_count(); ?> Topics
        </div>
        <div class="bbp-forum-reply-count">
            <?php bbp_show_lead_topic() ? bbp_forum_reply_count() : bbp_forum_post_count(); ?> Replies
        </div>
    </div>

    <div class="bbp-forum-freshness">
        <?php 
        $forum_id = bbp_get_forum_id();
        $active_id = bbp_get_forum_last_active_id($forum_id);
        $last_active_time = bbp_get_forum_last_active_time($forum_id);
        
        if ($last_active_time && $active_id) {
            $link_url = bbp_is_reply($active_id) ? bbp_get_reply_url($active_id) : bbp_get_topic_permalink($active_id);
            echo '<p class="bbp-forum-last-active-time"><a href="' . esc_url($link_url) . '">' . esc_html($last_active_time) . '</a></p>';
            
            $active_forum_id = bbp_is_reply($active_id) 
                ? bbp_get_reply_forum_id($active_id) 
                : bbp_get_topic_forum_id($active_id);
            
            if ($active_forum_id && $active_forum_id != $forum_id) {
                echo '<p class="bbp-forum-activity-location">in <a href="' . esc_url(bbp_get_forum_permalink($active_forum_id)) . '">' . esc_html(bbp_get_forum_title($active_forum_id)) . '</a></p>';
            }
        }
        ?>
        <p class="bbp-topic-meta">
            <?php 
            if ($active_id) {
                echo bbp_get_author_link(array('post_id' => $active_id, 'type' => 'both', 'size' => 14)); 
            }
            ?>
        </p>
    </div>
</div><!-- #bbp-forum-card-<?php bbp_forum_id(); ?> -->

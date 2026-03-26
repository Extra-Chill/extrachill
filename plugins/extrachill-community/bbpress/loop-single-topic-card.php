<?php

/**
 * Topics Loop - Single Topic Card
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

$topic_id = isset( $topic_id ) ? (int) $topic_id : bbp_get_topic_id();

if ( ! $topic_id ) {
	return;
}

?>

<div id="bbp-topic-card-<?php echo esc_attr( $topic_id ); ?>" class="bbp-topic-card ec-surface-card ec-edge-surface<?php echo ( bbp_is_topic_sticky( $topic_id ) ? ' bbp-topic-card-sticky' : '' ); ?>">
    <div class="bbp-topic-info ec-edge-gutter">
        <?php do_action( 'bbp_theme_before_topic_title' ); ?>
		<div class="topic-card-header-area">
			<div class="bbp-topic-header-inner">
				<div class="bbp-meta-upvote">
					<?php
					$user_id = get_current_user_id();
					// Determine icon based on user's upvote status
					$upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
					$is_upvoted = is_array($upvoted_posts) && in_array($topic_id, $upvoted_posts);
                    $icon_id = $is_upvoted ? 'circle-up' : 'circle-up-outline';

                    // Fetch the upvote count.
                    $upvote_count = get_upvote_count($topic_id); // Ensure this function is adapted to your setup

                    // Add 1 to the upvote count for display purposes
                    $display_upvote_count = $upvote_count + 1;
                    ?>

                    <div class="upvote-date">
                        <div class="upvote">
                            <span class="upvote-icon" 
                                  data-post-id="<?php echo esc_attr($topic_id); ?>" 
                                  data-type="topic"
                                  data-upvoted="<?php echo $is_upvoted ? 'true' : 'false'; ?>">
                                <?php echo ec_icon($icon_id); ?>
                            </span>
                            <span class="upvote-count"><?php echo esc_html($display_upvote_count); ?></span>
                        </div> 
                    </div> 
                </div><!-- .bbp-meta-upvote -->
                <a class="bbp-topic-title" href="<?php echo esc_url( bbp_get_topic_permalink( $topic_id ) ); ?>"><?php echo esc_html( bbp_get_topic_title( $topic_id ) ); ?></a>
                <?php
                // Forum name display REMOVED from here
                ?>
            </div><!-- bbp-topic-header-inner -->
        </div><!-- topic-card-header-area -->
        <?php do_action( 'bbp_theme_after_topic_title' ); ?>
    </div>

	<div class="bbp-topic-stats ec-edge-gutter">
		<span class="topic-views"><?php echo esc_html( number_format( ec_get_post_views( $topic_id ) ) ); ?> views</span>
        <div class="bbp-topic-voice-count">
            <?php echo esc_html( bbp_get_topic_voice_count( $topic_id ) ); ?> Voices
        </div>
        <div class="bbp-topic-reply-count">
            <?php echo esc_html( bbp_show_lead_topic() ? bbp_get_topic_reply_count( $topic_id ) : bbp_get_topic_post_count( $topic_id ) ); ?> Replies
        </div>
    </div>

    <div class="bbp-topic-freshness ec-edge-gutter">
        <?php do_action( 'bbp_theme_before_topic_freshness_link' ); ?>
        <?php bbp_topic_freshness_link( $topic_id ); ?>
        <?php do_action( 'bbp_theme_after_topic_freshness_link' ); ?>
        <div class="bbp-topic-meta">
            <?php do_action( 'bbp_theme_before_topic_author' ); ?>
	            <span class="bbp-topic-freshness-author"><?php bbp_author_link( array( 'post_id' => bbp_get_topic_last_active_id( $topic_id ), 'size' => 24 ) ); ?></span>
            <?php do_action( 'bbp_theme_after_topic_author' ); ?>
        </div>
        <?php
        // Display forum name if topic has one AND it's a relevant page context
	        $forum_id_for_topic = bbp_get_topic_forum_id( $topic_id );

		$show_forum_name_on_card = false;
		if ( is_page_template('page-templates/recent-feed-template.php') ||
		     is_front_page() ||
		     is_search() ||
		     bbp_is_search() ) {
			$show_forum_name_on_card = true;
        }

	        if ( $show_forum_name_on_card && ! empty( $forum_id_for_topic ) ) :
            ?>
            <div class="bbp-topic-forum-origin"> 
                <span class="bbp-topic-started-in">
                    <?php printf(
                        esc_html__( 'in %1$s', 'bbpress' ), 
                        '<a href="' . esc_url( bbp_get_forum_permalink( $forum_id_for_topic ) ) . '">' . esc_html( bbp_get_forum_title( $forum_id_for_topic ) ) . '</a>' 
                    ); ?>
                </span>
            </div>
        <?php
        endif;
        ?>
    </div>
</div><!-- #bbp-topic-card-<?php echo esc_attr( $topic_id ); ?> -->

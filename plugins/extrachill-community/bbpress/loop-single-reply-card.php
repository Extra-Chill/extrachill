<?php
/**
 * Replies Loop - Single Reply Card
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;
?>

<div id="post-<?php bbp_reply_id(); ?>" 
     class="bbp-reply-card <?php if ( bbp_get_reply_author_id() == bbp_get_topic_author_id( bbp_get_topic_id() ) ) echo 'is-topic-author'; ?>"
     data-reply-id="<?php bbp_reply_id(); ?>">

    <?php do_action( 'bbp_template_before_reply_content' ); ?>

    <div <?php bbp_reply_class(); ?>>

        <div class="bbp-reply-header">
            <div class="bbp-reply-header-content">
                <?php
                // =====================
                // UPVOTE &amp; DATE SECTION
                // =====================
                $user_id       = get_current_user_id();
                $reply_id      = bbp_get_reply_id();
                $upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
                $icon_class    = ( is_array($upvoted_posts) && in_array($reply_id, $upvoted_posts ) ) ? 'fa-solid' : 'fa-regular';

                // Local upvote count function
                $upvote_count = get_upvote_count($reply_id); // Ensure this function is adapted to your setup

                // Example offset for display
                $display_upvote_count = $upvote_count + 1;
                ?>

                <div class="upvote-date">
                    <div class="upvote">
                        <span class="upvote-icon"
                              data-post-id="<?php echo esc_attr($reply_id); ?>"
                              data-type="reply"
                              data-nonce="<?php echo esc_attr(wp_create_nonce('upvote_nonce')); ?>"
                              <?php if (!empty($main_site_post_id)) echo 'data-main-site-post-id="' . esc_attr($main_site_post_id) . '"'; ?>>
                            <i class="<?php echo esc_attr($icon_class); ?> fa-circle-up"></i>
                        </span>
                        <span class="upvote-count"><?php echo esc_html($display_upvote_count); ?></span>
                    </div>
                    <a href="<?php bbp_reply_url(); ?>" class="bbp-reply-post-date" id="bbp-reply-permalink">
    <?php bbp_reply_post_date(); ?>
</a>

                    <?php
$reply_id  = bbp_get_reply_id();
$topic_id  = bbp_get_topic_id();

// If the "reply" is actually the lead topic (ID == topic ID), treat it as a topic
$is_lead_topic = ( $reply_id === $topic_id );

// If you want the same top bar for both, just decide which functions to call:
if ( $is_lead_topic ) {
    // LEAD TOPIC
    // - Use topic-based "Reply" and "Edit" links

    // Topic Reply Link
    // This anchors to the reply form for the topic
    if ( ! bbp_is_topic_closed( $topic_id ) ) {
        $reply_link = bbp_get_topic_reply_link( array(
            'id'         => $topic_id,
            'reply_text' => __( 'Reply', 'bbpress' )
        ) );
    }

    // Topic Edit Link
    if ( current_user_can( 'edit_topic', $topic_id ) ) {
        $edit_link = bbp_get_topic_edit_link( array(
            'id'        => $topic_id,
            'edit_text' => __( 'Edit', 'bbpress' )
        ) );
    }

} else {
    // REGULAR REPLY
    // - Use reply-based "Reply" and "Edit" links

    // Reply-to Link
    if ( ! bbp_is_topic_closed( bbp_get_reply_topic_id( $reply_id ) ) ) {
        $reply_link = bbp_get_reply_to_link( array(
            'id'         => $reply_id,
            'reply_text' => __( 'Reply', 'bbpress' )
        ) );
    }

    // Reply Edit Link
    if ( current_user_can( 'edit_reply', $reply_id ) ) {
        $edit_link = bbp_get_reply_edit_link( array(
            'id'        => $reply_id,
            'edit_text' => __( 'Edit', 'bbpress' )
        ) );
    }
}

// Now output them in the top bar
?>
<div class="bbp-reply-meta-top">
    <?php if ( ! empty( $reply_link ) ) : ?>
        <?php echo $reply_link; ?>
    <?php endif; ?>

    <?php if ( ! empty( $edit_link ) ) : ?>
        <?php echo $edit_link; ?>
    <?php endif; ?>
</div>




                </div>


                <?php
                // ============================
                // MANUAL AUTHOR DETAILS SETUP
                // ============================
                $author_id     = bbp_get_reply_author_id( $reply_id );
                $author_name   = bbp_get_reply_author_display_name( $reply_id );
                $author_avatar = bbp_get_reply_author_avatar( $reply_id, 80 ); // 80px
                $author_url    = bbp_get_reply_author_url( $reply_id );

                // If you want to show the user's forum role below the username:
                $author_role = bbp_get_user_display_role( $author_id );
                ?>

                <div class="author-header-column">
                    <!-- AVATAR + USERNAME + BADGES -->
                    <div class="author-details-header">
                        <div class="bbp-author-avatar">
                            <a href="<?php echo esc_url( $author_url ); ?>" title="View profile">
                                <?php echo $author_avatar; ?>
                            </a>
                        </div>

                        <div class="author-name-badges">
                            <a href="<?php echo esc_url( $author_url ); ?>" class="bbp-author-name">
                                <?php echo esc_html( $author_name ); ?>
                            </a>

                            <!-- Inline forum badges -->
                            <div class="forum-badges">
                                <?php
                                // This hook might output user badges or extra details
                                // If you have custom logic for badges, place it here
                                do_action( 'bbp_theme_after_reply_author_details' );
                                ?>
                            </div>
                            <div class="author-follow-button">
                        <?php if ( function_exists('extrachill_follow_button') ) {
                            extrachill_follow_button( bbp_get_reply_author_id() );
                        } ?>
                        
                    </div>

                        </div>
                    </div><!-- .author-details-header -->

                    <?php if ( ! empty( $author_role ) ) : ?>
                        <div class="bbp-author-role">
                            <?php echo esc_html( $author_role ); ?>
                        </div>
                    <?php endif; ?>

                    <div class="header-rankpoints">

                        <?php
                        // This calls your custom function to display rank &amp; points
                        extrachill_add_rank_and_points_to_reply();
                        ?>
                    </div>
                </div><!-- .author-header-column -->

                <?php if ( bbp_is_single_user_replies() ) : ?>
                    <span class="bbp-header">
                        <?php esc_html_e( 'in reply to: ', 'bbpress' ); ?>
                        <a class="bbp-topic-permalink" href="<?php bbp_topic_permalink( bbp_get_reply_topic_id() ); ?>">
                            <?php bbp_topic_title( bbp_get_reply_topic_id() ); ?>
                        </a>
                    </span>
                <?php endif; ?>        

            </div><!-- .bbp-reply-header-content -->
        </div><!-- .bbp-reply-header -->

        <!-- MAIN CONTENT AREA -->
        <div class="bbp-reply-content-area">
            <div class="bbp-reply-content" data-reply-id="<?php bbp_reply_id(); ?>">
                <?php do_action( 'bbp_theme_before_reply_content' ); ?>
                <?php bbp_reply_content(); ?>
                <?php do_action( 'bbp_theme_after_reply_content' ); ?>

            </div><!-- .bbp-reply-content -->
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <div class="bbp-reply-meta-right">
                    <div class="bbp-reply-ip"><?php bbp_author_ip( bbp_get_reply_id() ); ?></div>
                    <?php do_action( 'bbp_theme_before_reply_admin_links' ); ?>
                    <?php bbp_reply_admin_links(); ?> 
                    <?php do_action( 'bbp_theme_after_reply_admin_links' ); ?>
                </div>
                <?php endif; ?>
                <!-- .bbp-reply-meta-right -->
        </div> <!-- .bbp-reply-content-area -->

    </div><!-- .bbp-reply-content-area -->

    <?php do_action( 'bbp_template_after_reply_content' ); ?>
</div><!-- #bbp-reply-card-<?php bbp_reply_id(); ?> -->

<?php
// === Add Quick Reply Section If This Card is the Lead Topic ===
if ( bbp_get_reply_id() === bbp_get_topic_id() ) {
    $topic_id = bbp_get_topic_id(); // Already have topic ID
    $can_reply = is_user_logged_in() && 
                 current_user_can( 'publish_replies', $topic_id ) && 
                 !bbp_is_topic_closed( $topic_id );

    if ( $can_reply ) {
        ?>
        <div class="quick-reply-section-after-lead">
            <?php // --- Desktop Section (Button & Form) --- ?>
            <?php 
            // Only show Desktop Quick Reply if there are actual replies
            $reply_count = bbp_get_topic_reply_count( $topic_id ); 
            if ( $reply_count > 0 ) : 
            ?>
            <div class="quick-reply-container quick-reply-desktop" style="margin-top: 20px; margin-bottom: 20px;">
                 <button id="quick-reply-button-desktop" 
                         class="button quick-reply-button" 
                         data-topic-id="<?php echo esc_attr( $topic_id ); ?>">
                     Quick Reply
                 </button>
                 <div id="quick-reply-form-placeholder-desktop" style="display: none; margin-top: 15px;">
                     <?php bbp_get_template_part( 'form', 'reply-quick' ); ?>
                 </div>
            </div>
            <?php 
            endif; // End check for reply_count > 0
            ?>

            <?php // --- Mobile Section (Button ONLY) --- ?>
            <?php // REMOVE Mobile button rendering from here - Moved to footer function ?>
            <?php /*
            <div class="quick-reply-container quick-reply-mobile quick-reply-mobile-button-only">
                 <button id="quick-reply-button-mobile" 
                         class="button quick-reply-button-float" 
                         data-topic-id="<?php echo esc_attr( $topic_id ); ?>">
                     <i class="fa-solid fa-reply"></i>
                 </button>
            </div>
            */ ?>
            <?php // Form container is now rendered in the footer ?>
        </div> <?php // .quick-reply-section-after-lead ?>
        <?php
    }
}
// ============================================================
?>

<?php do_action( 'bbp_template_after_single_card' ); ?>

<?php
/**
 * Single Topic Content Part
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<div id="bbpress-forums" class="bbpress-wrapper">
    <div class="topic-with-sidebar">
        <div class="topic-main-content">
            <?php
            bbp_topic_subscription_link();

            bbp_topic_favorite_link();

            do_action( 'bbp_template_before_single_topic' );

            if ( post_password_required() ) :

                bbp_get_template_part( 'form', 'protected' );

            else :

                bbp_topic_tag_list();

                bbp_single_topic_description();

                if ( bbp_show_lead_topic() ) :

                    bbp_get_template_part( 'content', 'single-topic-lead' );

                endif;

                if ( bbp_has_replies() ) :

                    bbp_get_template_part( 'pagination', 'replies' );

                    bbp_get_template_part( 'loop',       'replies' );

                    bbp_get_template_part( 'pagination', 'replies' );

                endif;

                bbp_get_template_part( 'form', 'reply' );

            endif;

            bbp_get_template_part( 'alert', 'topic-lock' );

            do_action( 'bbp_template_after_single_topic' );
            ?>
        </div><!-- .topic-main-content -->

        <?php bbp_get_template_part( 'topic-sidebar' ); ?>

    </div><!-- .topic-with-sidebar -->
</div>

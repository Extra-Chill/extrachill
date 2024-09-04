<?php

/**
 * Single Forum Content Part
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<div id="bbpress-forums" class="bbpress-wrapper">

    <?php bbp_breadcrumb(); ?>

    <?php bbp_forum_subscription_link(); ?>

    <?php do_action( 'bbp_template_before_single_forum' ); ?>

    <?php if ( post_password_required() ) : ?>

        <?php bbp_get_template_part( 'form', 'protected' ); ?>

    <?php else : ?>

        <?php bbp_single_forum_description(); ?>

        <?php if ( bbp_has_forums() ) : ?>

            <?php bbp_get_template_part( 'loop', 'forums' ); ?>

        <?php endif; ?>

        <?php if ( ! bbp_is_forum_category() && bbp_has_topics() ) : ?>

            <?php bbp_get_template_part( 'pagination', 'topics' ); ?>

            <?php bbp_get_template_part( 'loop', 'topics' ); ?>

            <?php bbp_get_template_part( 'pagination', 'topics' ); ?>

            <?php
            // Get the current forum ID
            $current_forum_id = bbp_get_forum_id();
            // Check if the current forum is NOT 1494 before showing the topic form
            if ($current_forum_id != 1494) :
                bbp_get_template_part( 'form', 'topic' );
            endif;
            ?>

        <?php elseif ( ! bbp_is_forum_category() ) : ?>

            <?php bbp_get_template_part( 'feedback', 'no-topics' ); ?>

            <?php
            // Get the current forum ID
            $current_forum_id = bbp_get_forum_id();
            // Same check here to conditionally show the topic form if the forum is not 1494
            if ($current_forum_id != 1494) :
                bbp_get_template_part( 'form', 'topic' );
            endif;
            ?>

        <?php endif; ?>

    <?php endif; ?>

    <?php do_action( 'bbp_template_after_single_forum' ); ?>

</div>

<?php

/**
 * Replies Loop
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_replies_loop' ); 

// Get the latest reply ID for the current topic
$latest_reply_id = bbp_get_topic_last_reply_id( bbp_get_topic_id() );
$latest_reply_url = esc_url( bbp_get_reply_url( $latest_reply_id ) );

?>

<ul id="topic-<?php bbp_topic_id(); ?>-replies" class="forums bbp-replies">
    <li class="bbp-header">
        <?php if ( bbp_is_single_topic() ) : ?>
            <button id="jump-to-latest" class="jump-to-latest" data-latest-reply-url="<?php echo $latest_reply_url; ?>">Jump to Latest</button>
        <?php endif; ?>
    </li><!-- .bbp-header -->

    <li class="bbp-body">

        <?php if ( bbp_thread_replies() ) : ?>

            <?php bbp_list_replies(); ?>

        <?php else : ?>

            <?php while ( bbp_replies() ) : bbp_the_reply(); ?>

                <?php bbp_get_template_part( 'loop', 'single-reply' ); ?>

            <?php endwhile; ?>

        <?php endif; ?>

    </li><!-- .bbp-body -->

    <li class="bbp-footer">
    </li><!-- .bbp-footer -->
</ul><!-- #topic-<?php bbp_topic_id(); ?>-replies -->

<?php do_action( 'bbp_template_after_replies_loop' ); ?>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    var jumpButton = document.getElementById('jump-to-latest');
    if (jumpButton) {
        jumpButton.addEventListener('click', function() {
            var latestReplyUrl = this.getAttribute('data-latest-reply-url');
            if (latestReplyUrl) {
                window.location.href = latestReplyUrl;
            }
        });
    }
});
</script>

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
			// Share button, view count, and jump to latest button
			?>
			<div class="views-container">
				<div class="views-left">
					<?php do_action( 'extrachill_share_button' ); ?>
					<p class="topic-views"><?php ec_the_post_views(); ?></p>
				</div>
				<?php
				// Get the reply count for the current topic
				$reply_count = bbp_get_topic_reply_count( bbp_get_topic_id() );

				// Only show the "Jump to Latest" button if there are more than 2 replies
				if ( $reply_count > 2 ) {
					// Get the latest reply ID for the current topic
					$latest_reply_id  = bbp_get_topic_last_reply_id( bbp_get_topic_id() );
					$latest_reply_url = bbp_get_reply_url( $latest_reply_id );

					// Display the "Jump to Latest" button
					echo '<button id="jump-to-latest" class="jump-to-latest button-3 button-small" data-latest-reply-url="' . esc_url( $latest_reply_url ) . '">Jump to Latest</button>';
				}
				?>
			</div>
			<?php
			$topic_actions = array_filter(
				array(
					bbp_get_topic_subscription_link(
						array(
							'before' => '',
							'after'  => '',
						)
					),
					bbp_get_topic_favorite_link(
						array(
							'before' => '',
							'after'  => '',
						)
					),
				)
			);

			if ( $topic_actions ) :
				?>
				<nav class="topic-actions" aria-label="<?php esc_attr_e( 'Topic actions', 'extra-chill-community' ); ?>">
					<?php foreach ( array_values( $topic_actions ) as $index => $topic_action ) : ?>
						<?php if ( $index > 0 ) : ?>
							<span class="topic-actions__separator" aria-hidden="true">|</span>
						<?php endif; ?>
						<span class="topic-actions__item"><?php echo wp_kses_post( $topic_action ); ?></span>
					<?php endforeach; ?>
				</nav>
				<?php
			endif;

			do_action( 'bbp_template_before_single_topic' );

			if ( post_password_required() ) :

				bbp_get_template_part( 'form', 'protected' );

			else :

				bbp_topic_tag_list();

				if ( bbp_show_lead_topic() ) :

					bbp_get_template_part( 'content', 'single-topic-lead' );

				endif;

				/** @var bool $has_replies */
				$has_replies = bbp_has_replies();
				if ( $has_replies ) :

					bbp_get_template_part( 'loop', 'replies' );

					$bbp = bbpress();
					if ( $bbp->reply_query instanceof WP_Query && bbp_get_topic_reply_count() > 0 ) {
						extrachill_pagination( $bbp->reply_query, 'bbpress' );
					}

				endif;

				bbp_get_template_part( 'form', 'reply' );

				bbp_get_template_part( 'form', 'reply-inline' );

			endif;

			bbp_get_template_part( 'alert', 'topic-lock' );

			do_action( 'bbp_template_after_single_topic' );
			?>
		</div><!-- .topic-main-content -->

		<?php bbp_get_template_part( 'topic-sidebar' ); ?>

	</div><!-- .topic-with-sidebar -->
</div>

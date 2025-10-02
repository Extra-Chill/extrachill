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


	<?php bbp_forum_subscription_link(); ?>

	<?php do_action( 'bbp_template_before_single_forum' ); ?>

	<?php if ( post_password_required() ) : ?>

		<?php bbp_get_template_part( 'form', 'protected' ); ?>

	<?php else : ?>

		<?php bbp_single_forum_description(); ?>

		<?php if ( bbp_has_forums() ) : ?>

			<?php bbp_get_template_part( 'loop', 'subforums' ); ?>

		<?php endif; ?>

		<?php if ( ! bbp_is_forum_category() ) : ?>

			<?php if ( bbp_has_topics() ) : ?>

				<?php
				global $bbp_topic_query;
				if ( ! empty( $bbp_topic_query ) ) {
					extrachill_pagination( $bbp_topic_query, 'bbpress' );
				}
				?>
				<?php bbp_get_template_part( 'loop', 'topics' ); ?>
				<?php bbp_get_template_part( 'form', 'topic' ); ?>

			<?php else : ?>

				<?php bbp_get_template_part( 'feedback', 'no-topics' ); ?>
				<?php bbp_get_template_part( 'form', 'topic' ); ?>

			<?php endif; ?>

		<?php endif; ?>

	<?php endif; ?>

	<?php do_action( 'bbp_template_after_single_forum' ); ?>

</div>

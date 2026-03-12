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

		<?php
		$forum_id = function_exists( 'bbp_get_forum_id' ) ? (int) bbp_get_forum_id() : 0;
		$has_subforums = $forum_id > 0 && function_exists( 'bbp_forum_get_subforums' )
			? ! empty( bbp_forum_get_subforums( $forum_id ) )
			: false;
		?>

		<?php if ( ! bbp_is_forum_category() && ! $has_subforums && bbp_current_user_can_access_create_topic_form() ) : ?>
			<p class="ec-single-forum-create-topic">
				<a class="button-1 button-large" href="#new-post"><?php esc_html_e( 'Create Topic', 'extrachill-community' ); ?></a>
			</p>
		<?php endif; ?>

		<?php if ( bbp_has_forums() ) : ?>

			<?php bbp_get_template_part( 'loop', 'subforums' ); ?>

		<?php endif; ?>

		<?php if ( ! bbp_is_forum_category() ) : ?>

			<?php if ( bbp_has_topics() ) : ?>

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

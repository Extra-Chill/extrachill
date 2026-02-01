<?php
/**
 * User Topics Created
 *
 * @package bbPress
 * @subpackage Theme
 */

defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_user_topics_created' );
?>

<div id="bbp-user-topics-started" class="bbp-user-topics-started">
	<h2 class="entry-title"><?php esc_html_e( 'Forum Topics Started', 'bbpress' ); ?></h2>
	<div class="bbp-user-section">
		<?php if ( bbp_get_user_topics_started() ) : ?>
			<?php bbp_get_template_part( 'loop', 'topics' ); ?>
			<?php
			$bbp = bbpress();
			if ( ! empty( $bbp->topic_query ) ) {
				extrachill_pagination( $bbp->topic_query, 'bbpress' );
			}
			?>
		<?php else : ?>
			<?php bbp_get_template_part( 'feedback', 'no-topics' ); ?>
		<?php endif; ?>
	</div>
</div>

<?php do_action( 'bbp_template_after_user_topics_created' );

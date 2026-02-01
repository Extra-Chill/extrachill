<?php
/**
 * User Replies Created
 *
 * @package bbPress
 * @subpackage Theme
 */

defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_user_replies' );
?>

<div id="bbp-user-replies-created" class="bbp-user-replies-created">
	<h2 class="entry-title"><?php esc_html_e( 'Forum Replies Created', 'bbpress' ); ?></h2>
	<div class="bbp-user-section">
		<?php if ( bbp_get_user_replies_created() ) : ?>
			<?php bbp_get_template_part( 'loop', 'replies' ); ?>
			<?php
			$bbp = bbpress();
			if ( ! empty( $bbp->reply_query ) ) {
				extrachill_pagination( $bbp->reply_query, 'bbpress' );
			}
			?>
		<?php else : ?>
			<?php bbp_get_template_part( 'feedback', 'no-replies' ); ?>
		<?php endif; ?>
	</div>
</div>

<?php do_action( 'bbp_template_after_user_replies' );

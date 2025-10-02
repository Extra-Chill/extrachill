<?php

/**
 * Single View Content Part
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

?>

<div id="bbpress-forums" class="bbpress-wrapper">

	<?php bbp_set_query_name( bbp_get_view_rewrite_id() ); ?>

	<?php if ( bbp_view_query() ) : ?>

		<?php
		global $bbp_topic_query;
		if ( ! empty( $bbp_topic_query ) ) {
			extrachill_pagination( $bbp_topic_query, 'bbpress' );
		}
		?>

		<?php bbp_get_template_part( 'loop',       'topics'    ); ?>

	<?php else : ?>

		<?php bbp_get_template_part( 'feedback',   'no-topics' ); ?>

	<?php endif; ?>

	<?php bbp_reset_query_name(); ?>

</div>

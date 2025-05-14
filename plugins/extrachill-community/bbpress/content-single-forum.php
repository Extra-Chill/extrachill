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

			<?php bbp_get_template_part( 'loop', 'forums' ); ?>

		<?php endif; ?>

		<?php
		// --- START Custom Logic for Forum 5432 / Standard Forums ---
		$band_directory_forum_id = 5432;
		$current_forum_id = bbp_get_forum_id();

		if ( $current_forum_id === $band_directory_forum_id ) {

			// --- Display for Band Directory Forum (ID: 5432) ---
			// Load our custom template part for band profiles, bypassing bbp_has_topics()
			bbp_get_template_part( 'loop', 'band-profiles' );
			
			// Do NOT display the standard "Create New Topic" form here by default
			// It could be added back conditionally if needed: 
			// bbp_get_template_part( 'form', 'topic' );
			// --- End Display for Band Directory Forum ---

		} elseif ( ! bbp_is_forum_category() ) {

			// --- Standard Display for Other Forums ---
			if ( bbp_has_topics() ) :
				
				// Forum has standard topics
				bbp_get_template_part( 'pagination', 'topics' );
				bbp_get_template_part( 'loop', 'topics' );

				// Show topic form (excluding forum 1494)
			if ($current_forum_id != 1494) :
				bbp_get_template_part( 'form', 'topic' );
			endif;

			else :

				// Forum has no standard topics
				bbp_get_template_part( 'feedback', 'no-topics' );

				// Show topic form (excluding forum 1494)
			if ($current_forum_id != 1494) :
				bbp_get_template_part( 'form', 'topic' );
			endif;

			endif; // End bbp_has_topics() check
			// --- End Standard Display for Other Forums ---
			
		} // --- END Custom Logic ---
			?>

	<?php endif; ?>

	<?php do_action( 'bbp_template_after_single_forum' ); ?>

</div>

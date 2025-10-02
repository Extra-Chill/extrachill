<?php

/**
 * Forums Loop - Subforums
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;
?>

<!-- Display Subforums Only -->
<ul id="forums-list-subforums-<?php bbp_forum_id(); ?>" class="bbp-forums">
	<li class="bbp-body">
		<?php while ( bbp_forums() ) : bbp_the_forum(); ?>
			<?php bbp_get_template_part( 'loop', 'single-forum-card' ); ?>
		<?php endwhile; ?>
	</li><!-- .bbp-body -->
</ul>

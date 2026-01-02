<?php
/**
 * Share Card for Single Posts
 *
 * Displays share CTA after post content, before newsletter form.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render share card on single blog posts
 *
 * Hooked at priority 5 to appear before newsletter form (priority 10).
 */
function extrachill_blog_share_card() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	wp_enqueue_style( 'extrachill-blog-share-card' );
	?>
	<div class="share-card">
		<p>Enjoyed this article? As an independent publication, your shares mean a lot to us. Please consider sharing it with your friends and followers.</p>
		<?php extrachill_share_button(); ?>
	</div>
	<?php
}
add_action( 'extrachill_after_post_content', 'extrachill_blog_share_card', 5 );

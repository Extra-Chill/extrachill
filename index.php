<?php
/**
 * Fallback Template - Emergency fallback only
 *
 * This file should rarely be reached. Template routing is handled by
 * inc/core/template-router.php via WordPress's template_include filter.
 *
 * @package ExtraChill
 * @since 69.58
 */

get_header();
?>

<div class="content-area">
	<main id="main" class="site-main">
		<?php
		if ( have_posts() ) :
			while ( have_posts() ) :
				the_post();
				the_content();
			endwhile;
		else :
			echo '<p>No content found.</p>';
		endif;
		?>
	</main>
</div>

<?php
get_footer();
?>

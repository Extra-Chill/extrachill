<?php
/**
 * Displays the searchform of the theme.
 *
 * @package ExtraChill
 * @since ExtraChill 1.0
 */
?>
<form action="<?php echo esc_url( home_url( '/' ) ); ?>" class="search-form searchform clearfix" method="get">
	<div class="search-wrap">
		<input type="search" placeholder="<?php esc_attr_e( 'Enter search terms...', 'extrachill' ); ?>" class="s field" name="s" value="<?php echo esc_attr( get_search_query() ); ?>">
		<button class="search" type="submit" aria-label="<?php esc_attr_e( 'Search', 'extrachill' ); ?>"><svg class="search-top">
    <use href="<?php echo get_template_directory_uri(); ?>/assets/fonts/fontawesome.svg#magnifying-glass-solid"></use>
</svg></button>
	</div>
</form><!-- .searchform -->

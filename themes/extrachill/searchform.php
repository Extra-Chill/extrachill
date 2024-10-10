<?php
/**
 * Displays the searchform of the theme.
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */
?>
<form action="<?php echo esc_url( home_url( '/' ) ); ?>" class="search-form searchform clearfix" method="get">
	<div class="search-wrap">
		<input type="text" placeholder="<?php esc_attr_e( 'Search', 'colormag-pro' ); ?>" class="s field" name="s">
		<button class="search" type="submit"><svg class="search-top">
    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#magnifying-glass-solid"></use>
</svg></button>
	</div>
</form><!-- .searchform -->

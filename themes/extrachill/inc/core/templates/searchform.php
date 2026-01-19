<?php
/**
 * Search Form Template
 *
 * @package ExtraChill
 * @since 1.0.0
 */

/**
 * Display search form
 */
if ( ! function_exists( 'extrachill_search_form' ) ) {
	function extrachill_search_form() {
		?>
		<form action="<?php echo esc_url( home_url( '/' ) ); ?>" class="search-form searchform" method="get">
			<div class="search-wrap">
				<input type="search" placeholder="<?php esc_attr_e( 'Enter search terms...', 'extrachill' ); ?>" class="s field" name="s" value="<?php echo esc_attr( get_search_query() ); ?>">
				<button class="button-1 button-medium" type="submit" aria-label="<?php esc_attr_e( 'Search', 'extrachill' ); ?>"><?php echo ec_icon( 'search', 'search-top' ); ?></button>
			</div>
		</form><!-- .searchform -->
		<?php
	}
}
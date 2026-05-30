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
		// Resolve the active scope from the search plugin when available so the
		// toggle reflects the current search. Defaults to the current site.
		$scope = function_exists( 'extrachill_resolve_search_scope' )
			? extrachill_resolve_search_scope()
			: 'site';

		$site_name = get_bloginfo( 'name' );
		?>
		<form action="<?php echo esc_url( home_url( '/' ) ); ?>" class="search-form searchform" method="get">
			<div class="search-wrap">
				<input type="search" placeholder="<?php esc_attr_e( 'Enter search terms...', 'extrachill' ); ?>" class="s field" name="s" value="<?php echo esc_attr( get_search_query() ); ?>">
				<button class="button-1 button-medium" type="submit" aria-label="<?php esc_attr_e( 'Search', 'extrachill' ); ?>"><?php echo ec_icon( 'search', 'search-top' ); ?></button>
			</div>
			<fieldset class="search-scope" role="radiogroup" aria-label="<?php esc_attr_e( 'Search scope', 'extrachill' ); ?>">
				<legend class="screen-reader-text"><?php esc_html_e( 'Choose where to search', 'extrachill' ); ?></legend>
				<label class="search-scope__option">
					<input type="radio" name="search_scope" value="site" <?php checked( $scope, 'site' ); ?>>
					<span class="search-scope__label">
						<?php
						/* translators: %s: current site name. */
						echo esc_html( sprintf( __( 'This site (%s)', 'extrachill' ), $site_name ) );
						?>
					</span>
				</label>
				<label class="search-scope__option">
					<input type="radio" name="search_scope" value="network" <?php checked( $scope, 'network' ); ?>>
					<span class="search-scope__label"><?php esc_html_e( 'Entire network', 'extrachill' ); ?></span>
				</label>
			</fieldset>
		</form><!-- .searchform -->
		<?php
	}
}
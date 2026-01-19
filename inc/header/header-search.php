<?php
/**
 * Header Search Overlay Trigger
 *
 * Renders the minimalist search affordance + overlay inside the header.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

add_action(
	'extrachill_header_top_right',
	function () {
		?>
	<button type="button" class="search-icon header-right-icon" aria-haspopup="dialog" aria-expanded="false" aria-controls="header-search-panel">
		<span class="screen-reader-text"><?php esc_html_e( 'Open site search', 'extrachill' ); ?></span>
		<?php echo ec_icon( 'search', 'search-top' ); ?>
	</button>

	<div id="header-search-panel" class="header-search-panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Site Search', 'extrachill' ); ?>">
		<div class="header-search-panel__inner">
			<button type="button" class="search-close-button">
				<span class="screen-reader-text"><?php esc_html_e( 'Close site search', 'extrachill' ); ?></span>
				<?php echo ec_icon( 'close', 'search-close-icon' ); ?>
			</button>
			<?php extrachill_search_form(); ?>
		</div>
	</div>
		<?php
	},
	10
);

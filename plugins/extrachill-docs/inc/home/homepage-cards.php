<?php
/**
 * Homepage Platform Cards
 *
 * Renders dynamic platform cards on the docs homepage via extrachill_homepage_content hook.
 *
 * @package ExtraChillDocs
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_homepage_content', 'extrachill_docs_render_homepage_cards' );

/**
 * Renders platform cards grid on the homepage.
 *
 * Dynamically displays all ec_doc_platform terms with their descriptions and doc counts.
 * Platforms without docs are still shown to indicate available documentation areas.
 *
 * @since 0.1.0
 * @return void
 */
function extrachill_docs_render_homepage_cards() {
	$platforms = get_terms(
		array(
			'taxonomy'   => 'ec_doc_platform',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( empty( $platforms ) || is_wp_error( $platforms ) ) {
		return;
	}
	?>
	<div class="docs-homepage">
		<div class="docs-intro">
			<h1><?php esc_html_e( 'Extra Chill Documentation', 'extrachill-docs' ); ?></h1>
			<p><?php esc_html_e( 'Learn how to use the Extra Chill platform. Choose a topic below to get started.', 'extrachill-docs' ); ?></p>
		</div>

		<div class="docs-category-grid">
			<?php foreach ( $platforms as $platform ) : ?>
				<a href="<?php echo esc_url( get_term_link( $platform ) ); ?>" class="docs-category-card">
					<h2><?php echo esc_html( $platform->name ); ?></h2>
					<?php if ( ! empty( $platform->description ) ) : ?>
						<p class="docs-category-description"><?php echo esc_html( $platform->description ); ?></p>
					<?php endif; ?>
					<span class="docs-category-count">
						<?php
						printf(
							/* translators: %d: number of articles */
							esc_html( _n( '%d article', '%d articles', $platform->count, 'extrachill-docs' ) ),
							intval( $platform->count )
						);
						?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

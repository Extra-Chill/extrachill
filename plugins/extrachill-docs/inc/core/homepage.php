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
 * Dynamically displays parent pages that have published documentation.
 * Only platforms with content are shown to keep the homepage focused and useful.
 *
 * @since 0.1.0
 * @return void
 */
function extrachill_docs_render_homepage_cards() {
	$platforms = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => 0, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );

	if ( empty( $platforms ) || is_wp_error( $platforms ) ) {
		return;
	}

	extrachill_breadcrumbs();
	?>
	<div class="docs-homepage">
		<div class="docs-intro">
			<h1><?php esc_html_e( 'Extra Chill Documentation', 'extrachill-docs' ); ?></h1>
			<p><?php esc_html_e( 'Learn how to use the Extra Chill platform. Choose a topic below to get started.', 'extrachill-docs' ); ?></p>
		</div>

		<div class="docs-category-grid">
			<?php foreach ( $platforms as $platform ) : ?>
				<?php
				$recent_docs = get_posts(
					array(
						'post_type' => 'page',
						'posts_per_page' => -1,
						'post_parent' => $platform->ID,
					)
				);
				if ( empty( $recent_docs ) ) { continue; }
				?>
				<div class="docs-category-card">
					<h2><?php echo esc_html( $platform->post_title ); ?></h2>
					<?php if ( ! empty( $platform->post_excerpt ) ) : ?>
						<p class="docs-category-description"><?php echo esc_html( $platform->post_excerpt ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $recent_docs ) ) : ?>
						<ul class="docs-recent-list">
							<?php foreach ( array_slice( $recent_docs, 0, 3 ) as $doc ) : ?>
								<li><a href="<?php echo esc_url( get_permalink( $doc ) ); ?>"><?php echo esc_html( $doc->post_title ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<a href="<?php echo esc_url( get_permalink( $platform ) ); ?>" class="docs-view-all">
						<?php
						printf(
							/* translators: %d: number of articles */
							esc_html( _n( 'See all %d article →', 'See all %d articles →', count( $recent_docs ), 'extrachill-docs' ) ),
							intval( count( $recent_docs ) )
						);
						?>
					</a>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

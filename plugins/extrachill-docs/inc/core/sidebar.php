<?php
/**
 * Related Docs Sidebar
 *
 * Displays related documentation posts from the same platform.
 *
 * @package ExtraChillDocs
 * @since 0.2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate related docs sidebar content.
 *
 * @param int $post_id Current post ID.
 * @return string HTML content for sidebar, empty string if no related docs.
 */
function extrachill_docs_generate_sidebar( $post_id ) {
	$platforms = get_the_terms( $post_id, 'ec_doc_platform' );

	if ( ! $platforms || is_wp_error( $platforms ) ) {
		return '';
	}

	$platform = $platforms[0];

	$related_docs = get_posts( [
		'post_type'      => 'ec_doc',
		'posts_per_page' => 5,
		'post_status'    => 'publish',
		'post__not_in'   => [ $post_id ],
		'tax_query'      => [
			[
				'taxonomy' => 'ec_doc_platform',
				'field'    => 'term_id',
				'terms'    => $platform->term_id,
			],
		],
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	] );

	if ( empty( $related_docs ) ) {
		return '';
	}

	ob_start();
	?>
	<div class="sidebar-card docs-related-sidebar">
		<h3 class="widget-title docs-sidebar-title"><span>In This Section</span></h3>
		<nav class="docs-related-nav">
			<ul class="docs-related-list">
				<?php foreach ( $related_docs as $doc ) : ?>
					<li class="docs-related-item">
						<a href="<?php echo esc_url( get_permalink( $doc->ID ) ); ?>">
							<?php echo esc_html( get_the_title( $doc->ID ) ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</div>
	<?php
	return ob_get_clean();
}

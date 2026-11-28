<?php
/**
 * Code-rendered landing pages for docs sections.
 *
 * @package ExtraChillDocs
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the active section and its direct, published articles in display order.
 *
 * @return array|null Section data, or null when the page body should be left alone.
 */
function extrachill_docs_get_section_landing(): ?array {
	if ( is_admin() || is_front_page() || ! is_singular( 'page' ) || ! is_main_query() || ! in_the_loop() ) {
		return null;
	}

	$page = get_queried_object();
	if ( ! $page instanceof WP_Post
		|| get_the_ID() !== $page->ID
		|| 0 !== (int) $page->post_parent
		|| 'login' === $page->post_name
		|| post_password_required( $page )
	) {
		return null;
	}

	$description = extrachill_docs_get_section_description( $page->post_name );
	if ( null === $description && '1' !== (string) get_post_meta( $page->ID, '_extrachill_docs_platform_parent', true ) ) {
		return null;
	}

	static $articles = array();
	if ( ! isset( $articles[ $page->ID ] ) ) {
		$articles[ $page->ID ] = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_parent'    => $page->ID,
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);
	}

	if ( empty( $articles[ $page->ID ] ) ) {
		return null;
	}

	return array(
		'description' => $description ?? '',
		'articles'    => $articles[ $page->ID ],
	);
}

/**
 * Skip the stored section body before WordPress renders its legacy blocks.
 *
 * @param string $content Stored page content.
 * @return string
 */
function extrachill_docs_skip_section_content( string $content ): string {
	return null === extrachill_docs_get_section_landing() ? $content : '';
}
add_filter( 'the_content', 'extrachill_docs_skip_section_content', 8 );

/** Print the section intro and article list after the theme's page content. */
function extrachill_docs_render_section_landing(): void {
	$section = extrachill_docs_get_section_landing();
	if ( null === $section ) {
		return;
	}
	?>
	<div class="entry-content docs-section-landing">
		<?php if ( '' !== $section['description'] ) : ?>
			<p><?php echo esc_html( $section['description'] ); ?></p>
		<?php endif; ?>
		<ul class="docs-section-articles">
			<?php foreach ( $section['articles'] as $article ) : ?>
				<li><a href="<?php echo esc_url( (string) get_permalink( $article ) ); ?>"><?php echo esc_html( get_the_title( $article ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}
add_action( 'extrachill_after_page_content', 'extrachill_docs_render_section_landing' );

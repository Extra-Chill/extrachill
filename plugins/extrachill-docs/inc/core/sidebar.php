<?php
/**
 * Documentation Table of Contents Sidebar
 *
 * Generates a nested TOC from post content headers.
 *
 * @package ExtraChillDocs
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate TOC sidebar from post content headers.
 *
 * @param int $post_id Current post ID.
 * @return string HTML content for sidebar, empty string if no headers.
 */
function extrachill_docs_generate_sidebar( $post_id ) {
	$content = get_post_field( 'post_content', $post_id );

	if ( empty( $content ) ) {
		return '';
	}

	// Extract h2 headers with IDs.
	preg_match_all(
		'/<h2[^>]*id=["\']([^"\']+)["\'][^>]*>(.*?)<\/h2>/i',
		$content,
		$matches,
		PREG_SET_ORDER
	);

	if ( empty( $matches ) ) {
		return '';
	}

	// Build array of headers.
	$headers = [];
	foreach ( $matches as $match ) {
		$headers[] = [
			'id'   => $match[1],
			'text' => wp_strip_all_tags( $match[2] ),
		];
	}

	ob_start();
	?>
	<nav class="docs-toc sidebar-card" aria-label="Table of Contents">
		<h3 class="docs-toc-title"><span>On This Page</span></h3>
		<?php echo extrachill_docs_build_toc_list( $headers ); ?>
	</nav>
	<?php
	return ob_get_clean();
}

/**
 * Build TOC list from headers array.
 *
 * @param array $headers Array of header data.
 * @return string HTML list.
 */
function extrachill_docs_build_toc_list( $headers ) {
	if ( empty( $headers ) ) {
		return '';
	}

	$html = '<ul class="docs-toc-list">';

	foreach ( $headers as $header ) {
		$html .= sprintf(
			'<li><a href="#%s" class="docs-toc-link">%s</a></li>',
			esc_attr( $header['id'] ),
			esc_html( $header['text'] )
		);
	}

	$html .= '</ul>';

	return $html;
}

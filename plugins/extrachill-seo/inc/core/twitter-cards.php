<?php
/**
 * Twitter Card Meta Tags
 *
 * Outputs Twitter Card tags for social sharing on X/Twitter.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output Twitter Card meta tags
 */
add_action(
	'wp_head',
	function () {
		$og_data = ec_seo_get_open_graph_data();

		echo "\n<!-- Twitter Card -->\n";

		// Card type - always summary_large_image for visual impact
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";

		// Site Twitter handle
		echo '<meta name="twitter:site" content="@extra_chill" />' . "\n";

		// Title
		if ( ! empty( $og_data['og:title'] ) ) {
			printf(
				'<meta name="twitter:title" content="%s" />' . "\n",
				esc_attr( $og_data['og:title'] )
			);
		}

		// Description
		if ( ! empty( $og_data['og:description'] ) ) {
			printf(
				'<meta name="twitter:description" content="%s" />' . "\n",
				esc_attr( $og_data['og:description'] )
			);
		}

		// Image
		if ( ! empty( $og_data['og:image'] ) ) {
			printf(
				'<meta name="twitter:image" content="%s" />' . "\n",
				esc_attr( $og_data['og:image'] )
			);

			// Image alt text
			if ( ! empty( $og_data['og:image:alt'] ) ) {
				printf(
					'<meta name="twitter:image:alt" content="%s" />' . "\n",
					esc_attr( $og_data['og:image:alt'] )
				);
			}
		}
	},
	5
);

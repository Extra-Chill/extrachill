<?php
/**
 * Share Button Component
 *
 * Self-contained share button with native sharing and a social dropdown fallback.
 * Automatically enqueues required CSS and JS assets when called.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

/**
 * Decode the title once for native and social sharing.
 *
 * @param string $title Plain or HTML-encoded page title.
 * @return string
 */
function extrachill_share_title_text( $title ) {
	return html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

if ( ! function_exists( 'extrachill_share_urls' ) ) :
	/**
	 * Build the share destination URLs for a page.
	 *
	 * Titles usually arrive HTML-encoded (get_the_title() turns "&" into
	 * "&#038;", post_title stores "&amp;"). Decode once to plain text, then
	 * URL-encode each value as a query argument. HTML escaping belongs to the
	 * href attribute at output time (esc_url), never to the query value, or a
	 * title with "&" splits the query string and truncates the shared text.
	 *
	 * @param string $share_url   Page URL.
	 * @param string $share_title Page title, plain or HTML-encoded.
	 * @return array<string,string> Unescaped URLs keyed by destination.
	 */
	function extrachill_share_urls( $share_url, $share_title ) {
		$title = extrachill_share_title_text( $share_title );
		$urls  = array();

		foreach ( array( 'native', 'copy_link', 'facebook', 'twitter', 'reddit', 'bluesky', 'email' ) as $destination ) {
			$campaign = array(
				'utm_source'   => $destination,
				'utm_medium'   => 'share',
				'utm_campaign' => 'share',
			);
			// These fixed slugs are also valid when the analytics plugin is inactive.
			if ( function_exists( 'extrachill_analytics_campaign_from_input' ) ) {
				$campaign = extrachill_analytics_campaign_from_input( $campaign );
			}
			$urls[ $destination ] = add_query_arg( $campaign, (string) $share_url );
		}

		$urls['facebook'] = 'https://www.facebook.com/sharer.php?u=' . rawurlencode( $urls['facebook'] );
		$urls['twitter']  = 'https://twitter.com/intent/tweet?url=' . rawurlencode( $urls['twitter'] ) . '&text=' . rawurlencode( $title );
		$urls['reddit']   = 'https://reddit.com/submit?url=' . rawurlencode( $urls['reddit'] ) . '&title=' . rawurlencode( $title );
		$urls['bluesky']  = 'https://bsky.app/intent/compose?text=' . rawurlencode( $title . ' ' . $urls['bluesky'] );
		$urls['email']    = 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( 'Check out this: ' . $urls['email'] );

		return $urls;
	}
endif;

if ( ! function_exists( 'extrachill_share_button' ) ) :
	/**
	 * Display share button
	 *
	 * @param array $args Optional arguments (share_url, share_title, button_size)
	 */
	function extrachill_share_button( $args = array() ) {
		wp_enqueue_script( 'extrachill-share' );

		$share_url   = isset( $args['share_url'] ) ? $args['share_url'] : '';
		$share_title = isset( $args['share_title'] ) ? $args['share_title'] : '';
		$button_size = isset( $args['button_size'] ) ? $args['button_size'] : 'button-small';

		if ( is_array( $share_url ) ) {
			$share_url = reset( $share_url );
		}

		if ( is_array( $share_title ) ) {
			$share_title = reset( $share_title );
		}

		$share_url   = $share_url ? (string) $share_url : (string) get_permalink();
		$share_title = $share_title ? (string) $share_title : get_the_title();
		if ( ! in_array( $button_size, array( 'button-small', 'button-medium', 'button-large' ), true ) ) {
			$button_size = 'button-small';
		}
		$share_urls = extrachill_share_urls( $share_url, $share_title );
		?>
		<div class="ec-mini-dropdown share-dropdown" aria-expanded="false" data-post-id="<?php echo esc_attr( (string) get_the_ID() ); ?>" data-blog-id="<?php echo esc_attr( (string) get_current_blog_id() ); ?>">
			<button type="button" class="ec-mini-dropdown-toggle button-2 <?php echo esc_attr( $button_size ); ?>" data-share-title="<?php echo esc_attr( extrachill_share_title_text( $share_title ) ); ?>" data-native-share-url="<?php echo esc_url( $share_urls['native'] ); ?>">
				<?php echo ec_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns SVG markup built from a fixed template with esc_attr()'d values. ?> Share
			</button>
			<ul class="ec-mini-dropdown-menu" role="menu">
				<li role="menuitem">
					<a href="<?php echo esc_url( $share_urls['facebook'] ); ?>" data-share-destination="facebook" target="_blank" rel="noopener noreferrer">Facebook</a>
				</li>
				<li role="menuitem">
					<a href="<?php echo esc_url( $share_urls['twitter'] ); ?>" data-share-destination="twitter" target="_blank" rel="noopener noreferrer">X</a>
				</li>
				<li role="menuitem">
					<a href="<?php echo esc_url( $share_urls['reddit'] ); ?>" data-share-destination="reddit" target="_blank" rel="noopener noreferrer">Reddit</a>
				</li>
				<li role="menuitem">
					<a href="<?php echo esc_url( $share_urls['bluesky'] ); ?>" data-share-destination="bluesky" target="_blank" rel="noopener noreferrer">Bluesky</a>
				</li>
				<li role="menuitem">
					<a href="<?php echo esc_url( $share_urls['email'], array( 'mailto' ) ); ?>" data-share-destination="email">Email</a>
				</li>
				<li role="menuitem">
					<a href="#" data-share-destination="copy_link" data-share-url="<?php echo esc_url( $share_urls['copy_link'] ); ?>">Copy Link</a>
				</li>
				<li role="menuitem">
					<a href="#" data-share-destination="copy_markdown">Copy Markdown</a>
				</li>
			</ul>
		</div>
		<?php
	}
endif;

add_action( 'extrachill_share_button', 'extrachill_share_button', 10 );

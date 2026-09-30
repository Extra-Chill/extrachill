<?php
/**
 * Share Button Component
 *
 * Self-contained share button with dropdown for social sharing.
 * Supports Facebook, X, Reddit, Bluesky, email, and copy link.
 * Automatically enqueues required CSS and JS assets when called.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

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
		$url   = (string) $share_url;
		$title = html_entity_decode( wp_strip_all_tags( (string) $share_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			'facebook' => 'https://www.facebook.com/sharer.php?u=' . rawurlencode( $url ),
			'twitter'  => 'https://twitter.com/intent/tweet?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ),
			'reddit'   => 'https://reddit.com/submit?url=' . rawurlencode( $url ) . '&title=' . rawurlencode( $title ),
			'bluesky'  => 'https://bsky.app/intent/compose?text=' . rawurlencode( $title . ' ' . $url ),
			'email'    => 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( 'Check out this: ' . $url ),
		);
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
		$button_size = esc_attr( $button_size );
		$share_urls  = extrachill_share_urls( $share_url, $share_title );
		?>
		<div class="ec-mini-dropdown share-dropdown" aria-expanded="false" data-post-id="<?php echo esc_attr( (string) get_the_ID() ); ?>" data-blog-id="<?php echo esc_attr( (string) get_current_blog_id() ); ?>">
			<button class="ec-mini-dropdown-toggle button-2 <?php echo esc_attr( $button_size ); ?>">
				<?php echo ec_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns SVG markup built from a fixed template with esc_attr()'d values. ?> Share
			</button>
			<ul class="ec-mini-dropdown-menu" role="menu">
				<li role="menuitem" class="share-option facebook">
					<a href="<?php echo esc_url( $share_urls['facebook'] ); ?>" target="_blank" rel="noopener noreferrer">Facebook</a>
				</li>
				<li role="menuitem" class="share-option twitter">
					<a href="<?php echo esc_url( $share_urls['twitter'] ); ?>" target="_blank" rel="noopener noreferrer">X</a>
				</li>
				<li role="menuitem" class="share-option reddit">
					<a href="<?php echo esc_url( $share_urls['reddit'] ); ?>" target="_blank" rel="noopener noreferrer">Reddit</a>
				</li>
				<li role="menuitem" class="share-option bluesky">
					<a href="<?php echo esc_url( $share_urls['bluesky'] ); ?>" target="_blank" rel="noopener noreferrer">Bluesky</a>
				</li>
				<li role="menuitem" class="share-option email">
					<a href="<?php echo esc_url( $share_urls['email'], array( 'mailto' ) ); ?>">Email</a>
				</li>
				<li role="menuitem" class="share-option copy-link">
					<a href="#" data-share-url="<?php echo esc_url( $share_url ); ?>">Copy Link</a>
				</li>
				<li role="menuitem" class="share-option copy-markdown">
					<a href="#">Copy Markdown</a>
				</li>
			</ul>
		</div>
		<?php
	}
endif;

add_action( 'extrachill_share_button', 'extrachill_share_button', 10 );

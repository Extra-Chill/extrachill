<?php
/**
 * Share Button Component - Clean Function-Only Pattern
 *
 * Interactive share component with dropdown for Facebook, Twitter/X, email, and copy link.
 * Follows clean sidebar architecture with function-only approach and action hooks.
 *
 * @package ExtraChill
 * @since 69.57
 */

if ( ! function_exists( 'extrachill_share_button' ) ) :
    /**
     * Display share button component
     *
     * Interactive share component with dropdown for social media sharing.
     * Includes JavaScript for toggle functionality and clipboard copy with fallback.
     *
     * @param array $args Optional. Array of arguments for share button.
     *                   - share_url: URL to be shared (defaults to current permalink)
     *                   - share_title: Title to be shared (defaults to current post title)
     *                   - share_description: Description for sharing (optional)
     *                   - share_image: Featured image URL (optional)
     * @since 69.57
     */
    function extrachill_share_button( $args = array() ) {
        // Extract variables from the arguments array
        if ( isset( $args ) && is_array( $args ) ) {
            extract( $args );
        }

        // Ensure $share_url is a string (use the first element if it's an array)
        if ( isset( $share_url ) && is_array( $share_url ) ) {
            $share_url = reset( $share_url );
        }

        // Ensure $share_title is a string (use the first element if it's an array)
        if ( isset( $share_title ) && is_array( $share_title ) ) {
            $share_title = reset( $share_title );
        }

        // Default values (can be overridden via $args)
        $share_url   = isset( $share_url ) ? esc_url( $share_url ) : get_permalink();
        $share_title = isset( $share_title ) ? esc_attr( $share_title ) : get_the_title();

        $svg_file_path = get_template_directory() . '/assets/fonts/fontawesome.svg';
        $svg_version   = file_exists( $svg_file_path ) ? filemtime( $svg_file_path ) : time();

        ?>
        <div class="share-button-container">

            <!-- Main Share Button (Icon) -->
            <button class="button share-button">
                <svg>
                    <use href="<?php echo esc_attr( get_template_directory_uri() . '/assets/fonts/fontawesome.svg?v=' . $svg_version ); ?>#share"></use>
                </svg> Share
            </button>

            <!-- Share Options Dropdown (initially hidden) -->
            <div class="share-options" style="display: none;">
                <ul class="share-options-list">
                    <li class="share-option facebook">
                        <a href="https://www.facebook.com/sharer.php?u=<?php echo esc_url( $share_url ); ?>" target="_blank" rel="noopener noreferrer">Facebook</a>
                    </li>
                    <li class="share-option twitter">
                        <a href="https://twitter.com/intent/tweet?url=<?php echo esc_url( $share_url ); ?>&text=<?php echo esc_attr( $share_title ); ?>" target="_blank" rel="noopener noreferrer">X</a>
                    </li>
                    <li class="share-option email">
                        <a href="mailto:?subject=<?php echo esc_attr( $share_title ); ?>&body=Check out this event: <?php echo esc_url( $share_url ); ?>">Email</a>
                    </li>
                    <li class="share-option copy-link">
                        <a id="copy-link-button" onclick="copyLinkToClipboard('<?php echo esc_url( $share_url ); ?>');">Copy Link</a>
                    </li>
                </ul>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const shareButton = document.querySelector('.share-button-container .share-button');
            const shareOptions = document.querySelector('.share-button-container .share-options');

            if (shareButton && shareOptions) {
                shareButton.addEventListener('click', function() {
                    shareOptions.style.display = shareOptions.style.display === 'block' ? 'none' : 'block';
                });

                // Close share options when clicking outside the container
                document.addEventListener('click', function(event) {
                    if (!shareButton.contains(event.target) && !shareOptions.contains(event.target)) {
                        shareOptions.style.display = 'none';
                    }
                });
            }
        });

        function copyLinkToClipboard(url) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url)
                    .then(() => {
                        const copyButton = document.getElementById('copy-link-button');
                        if (copyButton) {
                            copyButton.textContent = 'Copied!';
                            setTimeout(() => { copyButton.textContent = 'Copy Link'; }, 2000); // Revert text after 2 seconds
                        }
                    })
                    .catch(err => {
                        console.error('Failed to copy link: ', err);
                        promptFallback(url);
                    });
            } else {
                promptFallback(url);
            }
        }

        function promptFallback(url) {
            window.prompt('Copy this link:', url);
        }
        </script>
        <?php
    }
endif;

// Hook registration for share button
add_action( 'extrachill_share_button', 'extrachill_share_button', 10 );

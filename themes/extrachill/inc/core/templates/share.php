<?php
/**
 * Share Button Component
 *
 * Self-contained share button with dropdown for Facebook, Twitter/X, email, and copy link.
 * Automatically enqueues required CSS and JS assets when called.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! function_exists( 'extrachill_share_button' ) ) :
    /**
     * Display share button
     *
     * @param array $args Optional arguments (share_url, share_title, button_size)
     */
    function extrachill_share_button( $args = array() ) {
        wp_enqueue_style( 'extrachill-share' );
        wp_enqueue_script( 'extrachill-share' );

        if ( isset( $args ) && is_array( $args ) ) {
            extract( $args );
        }

        if ( isset( $share_url ) && is_array( $share_url ) ) {
            $share_url = reset( $share_url );
        }

        if ( isset( $share_title ) && is_array( $share_title ) ) {
            $share_title = reset( $share_title );
        }

        $share_url    = isset( $share_url ) ? esc_url( $share_url ) : get_permalink();
        $share_title  = isset( $share_title ) ? esc_attr( $share_title ) : get_the_title();
        $button_size  = isset( $button_size ) ? esc_attr( $button_size ) : 'button-small';
        ?>
        <div class="share-button-container">

            <button class="share-button button-2 <?php echo esc_attr( $button_size ); ?>">
                <?php echo ec_icon('share'); ?> Share
            </button>

            <div class="share-options">
                <ul class="share-options-list">
                    <li class="share-option facebook">
                        <a href="https://www.facebook.com/sharer.php?u=<?php echo esc_url( $share_url ); ?>" target="_blank" rel="noopener noreferrer">Facebook</a>
                    </li>
                    <li class="share-option twitter">
                        <a href="https://twitter.com/intent/tweet?url=<?php echo esc_url( $share_url ); ?>&text=<?php echo esc_attr( $share_title ); ?>" target="_blank" rel="noopener noreferrer">X</a>
                    </li>
                    <li class="share-option email">
                        <a href="mailto:?subject=<?php echo esc_attr( $share_title ); ?>&body=Check out this: <?php echo esc_url( $share_url ); ?>">Email</a>
                    </li>
                    <li class="share-option copy-link">
                        <a href="#" data-share-url="<?php echo esc_url( $share_url ); ?>">Copy Link</a>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }
endif;

add_action( 'extrachill_share_button', 'extrachill_share_button', 10 );

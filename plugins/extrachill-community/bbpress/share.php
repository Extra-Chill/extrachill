<?php
/**
 * Component: Share Button - Modern and Modular (bbPress Version)
 *
 * @var string|array $share_url URL to be shared (if an array, the first element will be used)
 * @var string|array $share_title Title of the content being shared (optional, if an array, the first element will be used)
 * @var string       $share_description Description of the content (optional)
 * @var string       $share_image URL of a featured image (optional)
 */

// Extract variables from the arguments array
if ( is_array( $args ) ) {
    extract( $args );
}

// Ensure $share_url is a string (use the first element if it’s an array)
if ( isset( $share_url ) && is_array( $share_url ) ) {
    $share_url = reset( $share_url );
}

// Ensure $share_title is a string (use the first element if it’s an array)
if ( isset( $share_title ) && is_array( $share_title ) ) {
    $share_title = reset( $share_title );
}

// Default values (can be overridden when including the template part)
$share_url   = isset( $share_url ) ? esc_url( $share_url ) : get_permalink();
$share_title = isset( $share_title ) ? esc_attr( $share_title ) : ''; // Default to empty string if not set

?>
<div class="share-button-container">

    <!-- Main Share Button (Icon) -->
    <button class="share-button">
        <i class="fas fa-share-alt"></i> Share
    </button>

    <!-- Share Options Dropdown (initially hidden) -->
    <div class="share-options" style="display: none;">
        <ul class="share-options-list">
            <li class="share-option facebook">
                <a href="https://www.facebook.com/sharer.php?u=<?php echo esc_url( $share_url ); ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-facebook-f"></i> Facebook</a>
            </li>
            <li class="share-option twitter">
                <a href="https://twitter.com/intent/tweet?url=<?php echo esc_url( $share_url ); ?><?php if ( !empty( $share_title ) ) : ?>&text=<?php echo esc_attr( $share_title ); ?><?php endif; ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-x-twitter"></i> X</a>
            </li>
            <li class="share-option reddit">
                <a href="https://www.reddit.com/submit?url=<?php echo esc_url( $share_url ); ?><?php if ( !empty( $share_title ) ) : ?>&title=<?php echo esc_attr( $share_title ); ?><?php endif; ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-reddit"></i> Reddit</a>
            </li>
            <li class="share-option whatsapp">
                <a href="https://api.whatsapp.com/send?text=<?php if ( !empty( $share_title ) ) : ?> <?php echo esc_attr( $share_title ); ?><?php endif; ?> <?php echo esc_url( $share_url ); ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp"></i> WhatsApp</a>
            </li>
            <li class="share-option email">
                <a href="mailto:?subject=<?php echo esc_attr( $share_title ); ?><?php if ( !empty( $share_title ) ) : ?>&body=Check out this event: <?php echo esc_url( $share_url ); ?><?php endif; ?>">Email</a>
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
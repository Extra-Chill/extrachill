// extrch-share-modal.js
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('extrch-share-modal');
    if (!modal) {
        return;
    }

    /**
     * Track share click via analytics endpoint.
     *
     * @param {string} destination - Share destination (facebook, twitter, etc.)
     * @param {string} shareUrl - URL being shared
     */
    function trackShare(destination, shareUrl) {
        const endpoint = '/wp-json/extrachill/v1/analytics/click';
        const data = {
            click_type: 'share',
            share_destination: destination,
            source_url: window.location.href,
            destination_url: shareUrl || window.location.href
        };

        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([JSON.stringify(data)], { type: 'application/json' }));
        } else {
            fetch(endpoint, {
                method: 'POST',
                body: JSON.stringify(data),
                headers: { 'Content-Type': 'application/json' },
                keepalive: true
            }).catch(() => {});
        }
    }

    const overlay = modal.querySelector('.extrch-share-modal-overlay');
    const closeButton = modal.querySelector('.extrch-share-modal-close');
    const copyLinkButton = modal.querySelector('.extrch-share-option-copy-link');
    const shareTriggers = document.querySelectorAll('.extrch-share-trigger');
    
    const nativeShareOptionButton = modal.querySelector('.extrch-share-option-native');

    // Selectors for social media fallback links (to hide them when native share is used)
    const socialMediaShareButtons = modal.querySelectorAll('.extrch-share-option-facebook, .extrch-share-option-twitter, .extrch-share-option-linkedin, .extrch-share-option-email');

    // Modal Header Elements
    const modalProfileImg = modal.querySelector('.extrch-share-modal-profile-img');
    const modalMainTitle = modal.querySelector('.extrch-share-modal-main-title');
    const modalSubtitle = modal.querySelector('.extrch-share-modal-subtitle');

    // Social Media Link Placeholders
    const facebookLink = modal.querySelector('.extrch-share-option-facebook');
    const twitterLink = modal.querySelector('.extrch-share-option-twitter');
    const linkedinLink = modal.querySelector('.extrch-share-option-linkedin');
    const emailLink = modal.querySelector('.extrch-share-option-email');

    let currentShareUrl = '';
    let currentShareTitle = '';
    let currentShareType = 'page'; // 'page' or 'link'
    let mainPageProfileImgUrl = ''; // To store the main page's profile image

    function openModal(triggerButton) {
        currentShareUrl = triggerButton.dataset.shareUrl || window.location.href;
        currentShareTitle = triggerButton.dataset.shareTitle || document.title;
        currentShareType = triggerButton.dataset.shareType || 'page';

        // Update modal header
        if (modalMainTitle) {
            modalMainTitle.textContent = currentShareTitle; 
        }
        if (modalSubtitle) {
            try {
                const urlObj = new URL(currentShareUrl);
                modalSubtitle.textContent = urlObj.hostname + urlObj.pathname.replace(/^\/(.*)\/$/, '$1'); // Remove trailing/leading slashes from path
            } catch (e) {
                modalSubtitle.textContent = currentShareUrl.replace(/^https?:\/\//, '');
            }
        }

        // Profile image logic
        if (modalProfileImg) {
            let imgUrl = '';
            if (mainPageProfileImgUrl) { // Use cached main page profile image
                imgUrl = mainPageProfileImgUrl;
            } else {
                // Try to find the main page profile image if not cached
                const mainProfileImgElement = document.querySelector('.extrch-link-page-profile-img img');
                if (mainProfileImgElement && mainProfileImgElement.src) {
                    imgUrl = mainProfileImgElement.src;
                    mainPageProfileImgUrl = imgUrl;
                }
            }
            if (imgUrl && imgUrl.trim() !== '' && !imgUrl.match(/\/default\.(png|jpg|jpeg|gif)$/i)) {
                modalProfileImg.src = imgUrl;
                modalProfileImg.style.display = 'block';
            } else {
                modalProfileImg.src = '';
                modalProfileImg.style.display = 'none';
            }
        }

        // Update social media links
        if (facebookLink) facebookLink.href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(currentShareUrl)}`;
        if (twitterLink) twitterLink.href = `https://twitter.com/intent/tweet?url=${encodeURIComponent(currentShareUrl)}&text=${encodeURIComponent(currentShareTitle)}`;
        if (linkedinLink) linkedinLink.href = `https://www.linkedin.com/shareArticle?mini=true&url=${encodeURIComponent(currentShareUrl)}&title=${encodeURIComponent(currentShareTitle)}`;
        if (emailLink) emailLink.href = `mailto:?subject=${encodeURIComponent(currentShareTitle)}&body=${encodeURIComponent(currentShareUrl)}`;

        // Show/hide buttons based on navigator.share availability
        if (navigator.share && nativeShareOptionButton) {
            nativeShareOptionButton.classList.remove('extrch-modal-hidden');
            socialMediaShareButtons.forEach(btn => { btn.classList.remove('extrch-share-option-visible'); btn.classList.add('extrch-modal-hidden'); });
        } else {
            if (nativeShareOptionButton) nativeShareOptionButton.classList.add('extrch-modal-hidden');
            socialMediaShareButtons.forEach(btn => { btn.classList.add('extrch-share-option-visible'); btn.classList.remove('extrch-modal-hidden'); });
        }
        modal.classList.remove('extrch-modal-hidden'); // Make the modal container visible
        // Timeout to allow the display change to take effect before adding class for transition
        setTimeout(() => {
            modal.classList.add('active');
        }, 10); 
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }

    function closeModal() {
        modal.classList.remove('active');
        // Add a timeout to allow the fade-out transition to complete before hiding
        setTimeout(() => {
            modal.classList.add('extrch-modal-hidden'); 
            document.body.style.overflow = ''; // Restore background scrolling
        }, 300); // Match CSS transition duration

        // Reset copy button text if needed
        if (copyLinkButton) {
            const icon = copyLinkButton.querySelector('.extrch-share-option-icon i');
            const label = copyLinkButton.querySelector('.extrch-share-option-label');
            if (icon && label && label.textContent === 'Copied!') {
                icon.className = 'fas fa-copy';
                label.textContent = 'Copy Link';
            }
        }
    }

    if (shareTriggers.length > 0) {
        shareTriggers.forEach(trigger => {
            // Only open the share modal for share-page or share-item triggers, not the bell/subscribe trigger
            if (trigger.classList.contains('extrch-bell-page-trigger')) return;
            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation(); // Prevent link navigation if button is inside <a>
                openModal(trigger); // Pass the trigger element directly
            });
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeModal);
    }
    
    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }

    // Copy link functionality
    if (copyLinkButton) {
        const icon = copyLinkButton.querySelector('.extrch-share-option-icon i');
        const label = copyLinkButton.querySelector('.extrch-share-option-label');

        copyLinkButton.addEventListener('click', () => {
            if (!icon || !label) {
                return;
            }
            if (!currentShareUrl) {
                return;
            }

            trackShare('copy_link', currentShareUrl);
            navigator.clipboard.writeText(currentShareUrl)
                .then(() => {
                    const originalIconClass = icon.className;
                    const originalLabelText = label.textContent;
                    icon.className = 'fas fa-check';
                    label.textContent = 'Copied!';
                    copyLinkButton.disabled = true;

                    setTimeout(() => {
                        icon.className = originalIconClass;
                        label.textContent = originalLabelText;
                        copyLinkButton.disabled = false;
                    }, 2000);
                })
                .catch(err => {
                    label.textContent = 'Error'; // Basic error feedback
                    setTimeout(() => {
                        label.textContent = 'Copy Link';
                    }, 2000);
                });
        });
    }

    // Native Web Share API - now targets nativeShareOptionButton
    if (nativeShareOptionButton && navigator.share) {
        nativeShareOptionButton.addEventListener('click', async () => {
            try {
                trackShare('native', currentShareUrl);
                await navigator.share({
                    title: currentShareTitle,
                    url: currentShareUrl,
                });
                closeModal();
            } catch (err) {
                if (err.name !== 'AbortError') {
                    // Handle other errors if needed
                }
            }
        });
    }

    // Track social media link clicks (these open in new tabs)
    [facebookLink, twitterLink, linkedinLink, emailLink].forEach(link => {
        if (link) {
            link.addEventListener('click', () => {
                let destination = 'unknown';
                if (link === facebookLink) destination = 'facebook';
                else if (link === twitterLink) destination = 'twitter';
                else if (link === linkedinLink) destination = 'linkedin';
                else if (link === emailLink) destination = 'email';
                trackShare(destination, currentShareUrl);
            });
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });
}); 
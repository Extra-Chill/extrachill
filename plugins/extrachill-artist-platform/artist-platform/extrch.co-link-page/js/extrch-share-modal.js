// extrch-share-modal.js
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('extrch-share-modal');
    if (!modal) {
        return;
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
            nativeShareOptionButton.style.display = 'flex'; // Or 'inline-flex' if that's the grid default
            socialMediaShareButtons.forEach(btn => { btn.style.display = 'none'; });
            if (copyLinkButton) copyLinkButton.style.display = 'flex'; // Ensure copy link is visible
        } else {
            if (nativeShareOptionButton) nativeShareOptionButton.style.display = 'none';
            socialMediaShareButtons.forEach(btn => { btn.style.display = 'flex'; }); // Or 'inline-flex'
            if (copyLinkButton) copyLinkButton.style.display = 'flex'; // Ensure copy link is visible
        }
        modal.style.display = 'flex'; // Make the modal container visible
        // Timeout to allow the display change to take effect before adding class for transition
        setTimeout(() => {
            modal.classList.add('active');
        }, 10); 
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }

    function closeModal() {
        modal.classList.remove('active');
        // Add a timeout to allow the fade-out transition to complete before hiding with display:none
        setTimeout(() => {
            modal.style.display = 'none'; 
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

    // Close modal with Escape key
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });
}); 
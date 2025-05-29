// extrch-share-modal.js
document.addEventListener('DOMContentLoaded', () => {
    // console.log('[ShareModal] DOMContentLoaded');
    const modal = document.getElementById('extrch-share-modal');
    if (!modal) {
        // console.error('[ShareModal] Modal element #extrch-share-modal not found!');
        return;
    }
    // console.log('[ShareModal] Modal element found:', modal);

    const overlay = modal.querySelector('.extrch-share-modal-overlay');
    const closeButton = modal.querySelector('.extrch-share-modal-close');
    const copyLinkButton = modal.querySelector('.extrch-share-option-copy-link');
    const shareTriggers = document.querySelectorAll('.extrch-share-trigger');
    // console.log('[ShareModal] Share triggers found:', shareTriggers.length, shareTriggers);
    
    const nativeShareOptionButton = modal.querySelector('.extrch-share-option-native');
    // console.log('[ShareModal] Native share option button found:', nativeShareOptionButton);

    // Selectors for social media fallback links (to hide them when native share is used)
    const socialMediaShareButtons = modal.querySelectorAll('.extrch-share-option-facebook, .extrch-share-option-twitter, .extrch-share-option-linkedin, .extrch-share-option-email');
    // console.log('[ShareModal] Social media share buttons found:', socialMediaShareButtons.length, socialMediaShareButtons);

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
        // console.log('[ShareModal] openModal called with trigger:', triggerButton);
        currentShareUrl = triggerButton.dataset.shareUrl || window.location.href;
        currentShareTitle = triggerButton.dataset.shareTitle || document.title;
        currentShareType = triggerButton.dataset.shareType || 'page';
        // console.log('[ShareModal] Sharing URL:', currentShareUrl, 'Title:', currentShareTitle, 'Type:', currentShareType);

        // Update modal header
        if (modalMainTitle) {
            // For a more Linktree-like feel, use the page title as the main share title
            // If sharing a specific link, the title is of that link. If page, title of page.
            modalMainTitle.textContent = currentShareTitle; 
        }
        if (modalSubtitle) {
            // Subtitle should be the URL being shared, cleaned up a bit
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
            // console.log('[ShareModal] Native Share API is available. Showing native button, hiding social icons.');
            nativeShareOptionButton.style.display = 'flex'; // Or 'inline-flex' if that's the grid default
            socialMediaShareButtons.forEach(btn => { btn.style.display = 'none'; });
            if (copyLinkButton) copyLinkButton.style.display = 'flex'; // Ensure copy link is visible
        } else {
            // console.log('[ShareModal] Native Share API NOT available or button missing. Hiding native button, showing social icons.');
            if (nativeShareOptionButton) nativeShareOptionButton.style.display = 'none';
            socialMediaShareButtons.forEach(btn => { btn.style.display = 'flex'; }); // Or 'inline-flex'
            if (copyLinkButton) copyLinkButton.style.display = 'flex'; // Ensure copy link is visible
        }
        // console.log('[ShareModal] Setting modal display to flex and adding active class.');
        modal.style.display = 'flex'; // Make the modal container visible
        // Timeout to allow the display change to take effect before adding class for transition
        setTimeout(() => {
            modal.classList.add('active');
        }, 10); 
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
        // console.log('[ShareModal] Modal style display:', modal.style.display, 'Class list:', modal.classList);
    }

    function closeModal() {
        // console.log('[ShareModal] closeModal called.');
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
            trigger.addEventListener('click', (e) => {
                // console.log('[ShareModal] Share trigger clicked:', trigger);
                e.preventDefault();
                e.stopPropagation(); // Prevent link navigation if button is inside <a>
                openModal(trigger); // Pass the trigger element directly
            });
        });
    } else {
        // console.warn('[ShareModal] No share triggers found on the page.');
    }

    if (overlay) {
        overlay.addEventListener('click', closeModal);
    } else {
        // console.warn('[ShareModal] Modal overlay not found.');
    }
    
    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    } else {
        // console.warn('[ShareModal] Modal close button not found.');
    }

    // Copy link functionality
    if (copyLinkButton) {
        // console.log('[ShareModal] Copy link button found:', copyLinkButton);
        const icon = copyLinkButton.querySelector('.extrch-share-option-icon i');
        const label = copyLinkButton.querySelector('.extrch-share-option-label');

        // if (!icon) console.warn('[ShareModal] Copy link icon element not found!');
        // if (!label) console.warn('[ShareModal] Copy link label element not found!');

        copyLinkButton.addEventListener('click', () => {
            // console.log('[ShareModal] Copy Link button clicked.');
            if (!icon || !label) {
                // console.error('[ShareModal] Copy link icon or label was not found when button was clicked. Aborting copy.');
                return;
            }
            // console.log('[ShareModal] URL to copy:', currentShareUrl);
            if (!currentShareUrl) {
                // console.error('[ShareModal] currentShareUrl is empty or undefined. Cannot copy.');
                return;
            }

            navigator.clipboard.writeText(currentShareUrl)
                .then(() => {
                    // console.log('[ShareModal] Link copied to clipboard successfully! URL:', currentShareUrl);
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
                    // console.error('[ShareModal] Failed to copy link to clipboard. Error:', err, 'URL was:', currentShareUrl);
                    label.textContent = 'Error'; // Basic error feedback
                    setTimeout(() => {
                        label.textContent = 'Copy Link';
                    }, 2000);
                });
        });
    } else {
        // console.warn('[ShareModal] Copy link button (element with class .extrch-share-option-copy-link) not found on DOM load.');
    }

    // Native Web Share API - now targets nativeShareOptionButton
    if (nativeShareOptionButton && navigator.share) {
        // console.log('[ShareModal] Setting up Native Share API for the new option button.');
        nativeShareOptionButton.addEventListener('click', async () => {
            // console.log('[ShareModal] Native share (.extrch-share-option-native) button clicked.');
            try {
                await navigator.share({
                    title: currentShareTitle,
                    url: currentShareUrl,
                });
                // console.log('[ShareModal] Native share successful.');
                closeModal();
            } catch (err) {
                // console.error('[ShareModal] Error using Web Share API:', err);
                if (err.name !== 'AbortError') {
                    // Handle other errors if needed
                }
            }
        });
    } else {
        // if (!nativeShareOptionButton) console.warn('[ShareModal] Native share option button (.extrch-share-option-native) not found.');
        // if (!navigator.share) console.log('[ShareModal] Native Share API not supported by this browser.');
        // If the button exists but API not supported, it will be hidden by openModal logic.
    }

    // Close modal with Escape key
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });
}); 
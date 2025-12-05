/**
 * Jump to Preview Button
 * 
 * Mobile-only floating button that allows users to quickly navigate between
 * the edit section and preview section on the link page management interface.
 * Dynamically updates arrow direction based on scroll position.
 */
(function() {
    const MOBILE_BREAKPOINT = 768;
    
    const button = document.getElementById('extrch-jump-to-preview-btn');
    const editSection = document.querySelector('.manage-link-page-edit');
    const previewSection = document.querySelector('.manage-link-page-preview');
    
    if (!button || !editSection || !previewSection) {
        return;
    }
    
    const mainIconWrapper = button.querySelector('.main-icon-wrapper');
    const directionalArrow = button.querySelector('.directional-arrow');
    
    if (!mainIconWrapper || !directionalArrow) {
        return;
    }

    function isMobile() {
        return window.innerWidth < MOBILE_BREAKPOINT;
    }

    function getAdminBarHeight() {
        const adminBar = document.getElementById('wpadminbar');
        if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
            return adminBar.offsetHeight;
        }
        return 0;
    }

    function isNearPreview() {
        const previewRect = previewSection.getBoundingClientRect();
        const viewportHeight = window.innerHeight;
        const adminBarHeight = getAdminBarHeight();
        
        // Consider "near preview" when preview section top is in upper half of viewport
        return previewRect.top < (viewportHeight / 2) + adminBarHeight;
    }

    function updateButtonState() {
        const nearPreview = isNearPreview();
        
        if (nearPreview) {
            // At preview: show settings icon + up arrow to go back to edit
            mainIconWrapper.innerHTML = '<i class="fas fa-cog"></i>';
            directionalArrow.classList.remove('fa-arrow-down');
            directionalArrow.classList.add('fa-arrow-up');
            button.setAttribute('title', 'Scroll to Settings');
            button.setAttribute('aria-label', 'Scroll to Settings');
        } else {
            // At edit: show magnifying glass + down arrow to go to preview
            mainIconWrapper.innerHTML = '<i class="fas fa-magnifying-glass"></i>';
            directionalArrow.classList.remove('fa-arrow-up');
            directionalArrow.classList.add('fa-arrow-down');
            button.setAttribute('title', 'Scroll to Preview');
            button.setAttribute('aria-label', 'Scroll to Preview');
        }
    }

    function updateVisibility() {
        if (isMobile()) {
            button.classList.add('visible');
            updateButtonState();
        } else {
            button.classList.remove('visible');
        }
    }

    function scrollToSection(section) {
        const adminBarHeight = getAdminBarHeight();
        const sectionTop = section.getBoundingClientRect().top + window.pageYOffset - adminBarHeight - 10;
        
        window.scrollTo({
            top: sectionTop,
            behavior: 'smooth'
        });
    }

    function handleClick() {
        if (isNearPreview()) {
            scrollToSection(editSection);
        } else {
            scrollToSection(previewSection);
        }
    }

    function handleScroll() {
        if (isMobile()) {
            updateButtonState();
        }
    }

    function handleResize() {
        updateVisibility();
    }

    // Event listeners
    button.addEventListener('click', handleClick);
    window.addEventListener('scroll', handleScroll, { passive: true });
    window.addEventListener('resize', handleResize);

    // Initialize
    updateVisibility();
})();

function toggleForumCollapse(icon, containerClass) {
    const container = document.querySelector('.' + containerClass);
 
    // Immediately remove any existing transitionend event listener to prevent stacking
    container.removeEventListener('transitionend', handleTransitionEnd);
 
    function handleTransitionEnd() {
        // If expanded, remove fixed height to allow for dynamic content resizing
        if (!container.classList.contains('collapsed')) {
            container.style.height = null;
        }
    }
 
    // Determine action based on collapsed state
    if (container.classList.contains('collapsed')) {
        // Preparing to expand
        const sectionHeight = container.scrollHeight + "px";
        container.style.height = sectionHeight;
        // Wait for next frame to ensure the transition can occur
        requestAnimationFrame(() => {
            // Remove collapsed state and update icon
            container.classList.remove('collapsed');
            icon.className = "fa-solid fa-square-minus"; // 'Minus' icon for expanded state
        });
    } else {
        // Preparing to collapse
        // Set a fixed height first to enable transition from auto to 0
        container.style.height = container.scrollHeight + "px";
        container.offsetWidth; // Force reflow to ensure transition plays
        container.style.height = "0px";
        // Add collapsed state and update icon
        container.classList.add('collapsed');
        icon.className = "fa-solid fa-square-plus"; // 'Plus' icon for collapsed state
    }
 
    // Listen for the end of the transition to remove fixed height or handle rapid toggles
    container.addEventListener('transitionend', handleTransitionEnd, { once: true });
}
window.toggleForumCollapse = toggleForumCollapse;

// Content expansion toggle for recent activity feed
function toggleContentExpansion(replyId, button) {
    const container = document.getElementById('content-' + replyId);
    const preview = container.querySelector('.content-preview');
    const fullContent = container.querySelector('.content-full');
    const readMoreText = button.querySelector('.read-more-text');
    const readLessText = button.querySelector('.read-less-text');
    
    if (fullContent.classList.contains('collapsed')) {
        // Expand content
        preview.style.display = 'none';
        fullContent.style.height = fullContent.scrollHeight + 'px';
        fullContent.classList.remove('collapsed');
        fullContent.classList.add('expanded');
        readMoreText.style.display = 'none';
        readLessText.style.display = 'inline';
    } else {
        // Collapse content
        fullContent.style.height = '0';
        fullContent.classList.add('collapsed');
        fullContent.classList.remove('expanded');
        preview.style.display = 'block';
        readMoreText.style.display = 'inline';
        readLessText.style.display = 'none';
    }
}
window.toggleContentExpansion = toggleContentExpansion;

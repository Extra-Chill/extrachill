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

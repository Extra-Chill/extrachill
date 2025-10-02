/**
 * Content Expansion for Activity Feed
 *
 * Provides content expansion toggle functionality for activity feed items.
 */

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

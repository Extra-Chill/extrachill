/**
 * Content Expansion for Truncated Content
 *
 * Provides Show More/Less toggle functionality for long content in:
 * - Recent activity feed (replies)
 * - Main blog comments feed
 */

document.addEventListener('DOMContentLoaded', function() {
	document.addEventListener('click', function(e) {
		var button = e.target.closest('.read-more-toggle');
		if (!button) return;

		var replyId = button.dataset.replyId;
		var container = document.getElementById('content-' + replyId);
		if (!container) return;

		var preview = container.querySelector('.content-preview');
		var fullContent = container.querySelector('.content-full');
		var readMoreText = button.querySelector('.read-more-text');
		var readLessText = button.querySelector('.read-less-text');

		if (fullContent.classList.contains('collapsed')) {
			preview.style.display = 'none';
			fullContent.style.height = fullContent.scrollHeight + 'px';
			fullContent.classList.remove('collapsed');
			fullContent.classList.add('expanded');
			readMoreText.style.display = 'none';
			readLessText.style.display = 'inline';
		} else {
			fullContent.style.height = '0';
			fullContent.classList.add('collapsed');
			fullContent.classList.remove('expanded');
			preview.style.display = 'block';
			readMoreText.style.display = 'inline';
			readLessText.style.display = 'none';
		}
	});
});

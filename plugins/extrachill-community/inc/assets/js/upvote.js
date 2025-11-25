/**
 * Upvote System Handler
 *
 * Handles upvoting/downvoting of forum topics and replies with optimistic UI updates
 * and server-side validation.
 */

document.addEventListener('DOMContentLoaded', function() {
	document.addEventListener('click', function(e) {
		const upvoteIcon = e.target.closest('.upvote-icon');
		if (!upvoteIcon) return;

		e.preventDefault();
		e.stopPropagation();

		const postId = upvoteIcon.dataset.postId;
		const postType = upvoteIcon.dataset.type;
		const restNonce = extrachillCommunity.restNonce;

		if (!postId || !restNonce || !postType) {
			console.error('Post ID, nonce, or post type is missing.');
			console.log('Debug info:', {postId: postId, nonce: restNonce, postType: postType});
			return;
		}

		const icon = upvoteIcon.querySelector('i');
		const isUpvoted = icon.classList.contains('fa-solid');
		const upvoteContainer = upvoteIcon.closest('.upvote');
		const countSpan = upvoteContainer.querySelector('.upvote-count');
		const currentCount = parseInt(countSpan.textContent, 10) || 0;

		// Optimistic UI update
		const updatedCount = isUpvoted ? currentCount - 1 : currentCount + 1;
		countSpan.textContent = updatedCount;
		icon.classList.toggle('fa-solid');
		icon.classList.toggle('fa-regular');

		// Send request to REST API
		fetch('/wp-json/extrachill/v1/community/upvote', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': restNonce
			},
			body: JSON.stringify({
				post_id: parseInt(postId, 10),
				type: postType
			})
		})
		.then(response => response.json())
		.then(data => {
			if (!data.success) {
				// Rollback on error
				countSpan.textContent = currentCount;
				icon.classList.toggle('fa-solid');
				icon.classList.toggle('fa-regular');
				console.error('Error: ' + (data.message || 'Unknown error'));
			}
		})
		.catch(error => {
			// Rollback on network error
			countSpan.textContent = currentCount;
			icon.classList.toggle('fa-solid');
			icon.classList.toggle('fa-regular');
			console.error('Network error:', error);
		});
	});
});

/**
 * Image Voting Block - Frontend View Script
 * Combines voting functionality and vote-based block reordering
 */

import $ from 'jquery';

$(document).ready(function() {
	// ============================================
	// VOTING FUNCTIONALITY
	// ============================================
	const STORAGE_KEY = 'extrachill_voter_email';

	// Get saved email from localStorage
	function getSavedEmail() {
		return localStorage.getItem(STORAGE_KEY) || '';
	}

	// Save email to localStorage
	function saveEmail(email) {
		localStorage.setItem(STORAGE_KEY, email);
	}

	// Change button to "Voted" state
	function markAsVoted($button) {
		$button
			.prop('disabled', true)
			.text('Voted ✓')
			.addClass('voted');
	}

	// Display error/info message with auto-fade
	function showMessage($container, message, type) {
		const $messageBox = $container.find('.extrachill-voting-message');
		const isError = type === 'error';

		$messageBox
			.removeClass('message-error message-info')
			.addClass(isError ? 'message-error' : 'message-info')
			.text(message)
			.fadeIn(200);

		// Auto-fade after 3.5 seconds
		setTimeout(() => {
			$messageBox.fadeOut(400);
		}, 3500);
	}

	// Submit vote with email
	function submitVote($container, email) {
		const $button = $container.find('.extrachill-blocks-image-voting-button');
		const $voteCount = $container.find('.vote-number');
		const instanceId = $button.data('block-instance-id');
		const postId = $container.data('post-id');

		// Disable button during submission
		$button.prop('disabled', true).text('Voting...');

		$.ajax({
			url: extraChillBlocksImageVoting.ajaxurl,
			type: 'POST',
			data: {
				action: 'extrachill_blocks_image_vote',
				nonce: extraChillBlocksImageVoting.nonce,
				post_id: postId,
				instance_id: instanceId,
				email_address: email
			},
			success: function(response) {
				if (response.success) {
					// Save email for future votes
					saveEmail(email);

					// Update vote count
					$voteCount.text(response.data.vote_count);

					// Mark as voted
					markAsVoted($button);

					// Hide form if visible
					$container.find('.extrachill-blocks-image-voting-form').hide();
				} else {
					// Check if already voted
					if (response.data && response.data.code === 'already_voted') {
						markAsVoted($button);
						$container.find('.extrachill-blocks-image-voting-form').hide();
					} else {
						// Other error - re-enable button
						$button.prop('disabled', false).text('Vote');
						showMessage($container, response.data.message || 'An error occurred', 'error');
					}
				}
			},
			error: function() {
				$button.prop('disabled', false).text('Vote');
				showMessage($container, 'Network error. Please try again.', 'error');
			}
		});
	}

	// Initialize each voting block
	$('.extrachill-blocks-image-voting-container').each(function() {
		const $container = $(this);
		const $button = $container.find('.extrachill-blocks-image-voting-button');
		const $form = $container.find('.extrachill-blocks-image-voting-form');
		const $emailInput = $form.find('.extrachill-blocks-email-input');
		const $submitBtn = $form.find('.extrachill-blocks-submit-vote');
		const savedEmail = getSavedEmail();

		// Pre-fill email if saved
		if (savedEmail) {
			$emailInput.val(savedEmail);
		}

		// Vote button click
		$button.on('click', function() {
			if (savedEmail) {
				// Auto-submit with saved email
				submitVote($container, savedEmail);
			} else {
				// Show email form
				$form.show();
				$emailInput.focus();
			}
		});

		// Submit vote with email from form
		$submitBtn.on('click', function() {
			const email = $emailInput.val().trim();

			// Simple email validation
			if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				showMessage($container, 'Please enter a valid email address', 'error');
				return;
			}

			submitVote($container, email);
		});

		// Enter key submits form
		$emailInput.on('keypress', function(e) {
			if (e.which === 13) {
				e.preventDefault();
				$submitBtn.click();
			}
		});
	});

	// ============================================
	// BLOCK REORDERING BY VOTE COUNT
	// ============================================

	// Find all image voting blocks on the page
	const $blocks = $('.extrachill-blocks-image-voting-container');

	// Only proceed if there are multiple blocks to sort
	if ($blocks.length <= 1) {
		return;
	}

	// Group blocks by their immediate parent container
	const blocksByParent = new Map();
	$blocks.each(function(index) {
		const $block = $(this);
		const $parent = $block.parent();
		const parentKey = $parent.get(0); // Use DOM element as key

		if (!blocksByParent.has(parentKey)) {
			blocksByParent.set(parentKey, []);
		}

		const voteCount = parseInt($block.find('.vote-number').text(), 10) || 0;
		blocksByParent.get(parentKey).push({
			element: $block,
			voteCount: voteCount,
			originalIndex: index
		});
	});

	// Sort blocks within each parent container independently
	blocksByParent.forEach((blocksData, parentElement) => {
		// Only sort if this parent has multiple blocks
		if (blocksData.length <= 1) {
			return;
		}

		// Sort by vote count (descending), maintaining original order for ties
		blocksData.sort((a, b) => {
			if (b.voteCount !== a.voteCount) {
				return b.voteCount - a.voteCount;
			}
			// If vote counts are equal, maintain original order
			return a.originalIndex - b.originalIndex;
		});

		// Detach all blocks from this parent
		blocksData.forEach(data => data.element.detach());

		// Re-append blocks in sorted order
		const $parent = $(parentElement);
		blocksData.forEach(data => {
			$parent.append(data.element);
		});
	});
});

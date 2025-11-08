import $ from 'jquery';

$(document).ready(function() {
	const STORAGE_KEY = 'extrachill_voter_email';

	function getSavedEmail() {
		return localStorage.getItem(STORAGE_KEY) || '';
	}

	function saveEmail(email) {
		localStorage.setItem(STORAGE_KEY, email);
	}

	function markAsVoted($button) {
		$button
			.removeClass('button-1')
			.addClass('button-2')
			.prop('disabled', true)
			.text('Voted ✓');
	}

	function showMessage($container, message, type) {
		const $messageBox = $container.find('.extrachill-voting-message');
		const isError = type === 'error';

		$messageBox
			.removeClass('message-error message-info')
			.addClass(isError ? 'message-error' : 'message-info')
			.text(message)
			.fadeIn(200);

		setTimeout(() => {
			$messageBox.fadeOut(400);
		}, 3500);
	}

	function submitVote($container, email) {
		const $button = $container.find('.extrachill-blocks-image-voting-button');
		const $voteCount = $container.find('.vote-number');
		const instanceId = $button.data('block-instance-id');
		const postId = $container.data('post-id');

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
					saveEmail(email);
					$voteCount.text(response.data.vote_count);
					markAsVoted($button);
					$container.find('.extrachill-blocks-image-voting-form').hide();
				} else {
					if (response.data && response.data.code === 'already_voted') {
						markAsVoted($button);
						$container.find('.extrachill-blocks-image-voting-form').hide();
					} else {
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

	$('.extrachill-blocks-image-voting-container').each(function() {
		const $container = $(this);
		const $button = $container.find('.extrachill-blocks-image-voting-button');
		const $form = $container.find('.extrachill-blocks-image-voting-form');
		const $emailInput = $form.find('.extrachill-blocks-email-input');
		const $submitBtn = $form.find('.extrachill-blocks-submit-vote');
		const savedEmail = getSavedEmail();

		const voters = JSON.parse($container.attr('data-voters') || '[]');
		const hasVoted = savedEmail && voters.includes(savedEmail);

		if (hasVoted) {
			markAsVoted($button);
			$form.hide();
		}

		if (savedEmail) {
			$emailInput.val(savedEmail);
		}

		$button.on('click', function() {
			if (hasVoted) {
				return;
			}

			if (savedEmail) {
				submitVote($container, savedEmail);
			} else {
				$form.show();
				$emailInput.focus();
			}
		});

		$submitBtn.on('click', function() {
			const email = $emailInput.val().trim();

			if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				showMessage($container, 'Please enter a valid email address', 'error');
				return;
			}

			submitVote($container, email);
		});

		$emailInput.on('keypress', function(e) {
			if (e.which === 13) {
				e.preventDefault();
				$submitBtn.click();
			}
		});
	});

	const $blocks = $('.extrachill-blocks-image-voting-container');

	if ($blocks.length <= 1) {
		return;
	}

	const blocksByParent = new Map();
	$blocks.each(function(index) {
		const $block = $(this);
		const $parent = $block.parent();
		const parentKey = $parent.get(0);

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

	blocksByParent.forEach((blocksData, parentElement) => {
		if (blocksData.length <= 1) {
			return;
		}

		blocksData.sort((a, b) => {
			if (b.voteCount !== a.voteCount) {
				return b.voteCount - a.voteCount;
			}
			return a.originalIndex - b.originalIndex;
		});

		blocksData.forEach(data => data.element.detach());

		const $parent = $(parentElement);
		blocksData.forEach(data => {
			$parent.append(data.element);
		});
	});
});

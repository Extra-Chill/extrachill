import apiFetch from '@wordpress/api-fetch';

document.addEventListener('DOMContentLoaded', function() {
	const STORAGE_KEY = 'extrachill_voter_email';

	function getSavedEmail() {
		return localStorage.getItem(STORAGE_KEY) || '';
	}

	function saveEmail(email) {
		localStorage.setItem(STORAGE_KEY, email);
	}

	function markAsVoted(button) {
		button.classList.remove('button-1');
		button.classList.add('button-2');
		button.disabled = true;
		button.textContent = 'Voted ✓';
	}

	function showMessage(container, message, type) {
		const messageBox = container.querySelector('.extrachill-voting-message');
		const isError = type === 'error';

		messageBox.classList.remove('message-error', 'message-info');
		messageBox.classList.add(isError ? 'message-error' : 'message-info');
		messageBox.textContent = message;
		messageBox.classList.add('fade-in');
		messageBox.style.display = 'block';

		setTimeout(() => {
			messageBox.classList.remove('fade-in');
			messageBox.classList.add('fade-out');
			setTimeout(() => {
				messageBox.style.display = 'none';
				messageBox.classList.remove('fade-out');
			}, 400);
		}, 3500);
	}

	async function submitVote(container, email) {
		const button = container.querySelector('.extrachill-blocks-image-voting-button');
		const voteCount = container.querySelector('.vote-number');
		const instanceId = button.dataset.blockInstanceId;
		const postId = container.dataset.postId;
		const form = container.querySelector('.extrachill-blocks-image-voting-form');

		button.disabled = true;
		button.textContent = 'Voting...';

		try {
			const response = await apiFetch({
				path: '/extrachill/v1/blocks/image-voting/vote',
				method: 'POST',
				data: {
					post_id: parseInt(postId, 10),
					instance_id: instanceId,
					email_address: email
				}
			});

			saveEmail(email);
			voteCount.textContent = response.vote_count;
			markAsVoted(button);
			form.style.display = 'none';
		} catch (error) {
			if (error.code === 'already_voted') {
				markAsVoted(button);
				form.style.display = 'none';
			} else {
				button.disabled = false;
				button.textContent = 'Vote';
				showMessage(container, error.message || 'An error occurred', 'error');
			}
		}
	}

	document.querySelectorAll('.extrachill-blocks-image-voting-container').forEach(function(container) {
		const button = container.querySelector('.extrachill-blocks-image-voting-button');
		const form = container.querySelector('.extrachill-blocks-image-voting-form');
		const emailInput = form.querySelector('.extrachill-blocks-email-input');
		const submitBtn = form.querySelector('.extrachill-blocks-submit-vote');
		const savedEmail = getSavedEmail();

		const voters = JSON.parse(container.getAttribute('data-voters') || '[]');
		const hasVoted = savedEmail && voters.includes(savedEmail);

		if (hasVoted) {
			markAsVoted(button);
			form.style.display = 'none';
		}

		if (savedEmail) {
			emailInput.value = savedEmail;
		}

		button.addEventListener('click', function() {
			if (hasVoted) {
				return;
			}

			if (savedEmail) {
				submitVote(container, savedEmail);
			} else {
				form.style.display = 'block';
				emailInput.focus();
			}
		});

		submitBtn.addEventListener('click', function() {
			const email = emailInput.value.trim();

			if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				showMessage(container, 'Please enter a valid email address', 'error');
				return;
			}

			submitVote(container, email);
		});

		emailInput.addEventListener('keypress', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				submitBtn.click();
			}
		});
	});

	// Sort voting blocks by vote count
	const blocks = Array.from(document.querySelectorAll('.extrachill-blocks-image-voting-container'));

	if (blocks.length <= 1) {
		return;
	}

	const blocksByParent = new Map();
	blocks.forEach(function(block, index) {
		const parent = block.parentElement;

		if (!blocksByParent.has(parent)) {
			blocksByParent.set(parent, []);
		}

		const voteCount = parseInt(block.querySelector('.vote-number').textContent, 10) || 0;
		blocksByParent.get(parent).push({
			element: block,
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

		blocksData.forEach(data => data.element.remove());
		blocksData.forEach(data => {
			parentElement.appendChild(data.element);
		});
	});
});

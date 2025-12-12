/**
 * Band Name Generator Block - Frontend View Script
 */

import apiFetch from '@wordpress/api-fetch';

document.addEventListener('DOMContentLoaded', function() {
	document.querySelectorAll('.extrachill-blocks-generator-form[data-generator-type="band"]').forEach(function(form) {
		const container = form.closest('.extrachill-blocks-band-name-generator');
		const button = form.querySelector('button[type="submit"]');
		const resultContainer = container.querySelector('.extrachill-blocks-generator-result');
		const messageContainer = container.querySelector('.extrachill-generator-message');

		// Store original button text
		if (!button.dataset.originalText) {
			button.dataset.originalText = button.textContent;
		}

		form.addEventListener('submit', async function(e) {
			e.preventDefault();

			const input = form.querySelector('#input').value.trim();
			const genre = form.querySelector('#genre').value;
			const numberOfWords = parseInt(form.querySelector('#number_of_words').value, 10);
			const firstThe = form.querySelector('input[name="first-the"]').checked;
			const andThe = form.querySelector('input[name="and-the"]').checked;

			if (!input) {
				showMessage('Please enter your name or word', 'error');
				return;
			}

			// Disable button and show loading state
			button.disabled = true;
			button.textContent = 'Generating...';

			try {
				const response = await apiFetch({
					path: '/extrachill/v1/blog/band-name',
					method: 'POST',
					data: {
						input,
						genre,
						number_of_words: numberOfWords,
						first_the: firstThe,
						and_the: andThe
					}
				});

				if (response.name) {
					displayResult(response.name);
				}
			} catch (error) {
				showMessage(error.message || 'An error occurred', 'error');
				button.disabled = false;
				button.textContent = button.dataset.originalText || 'Generate Band Name';
			}
		});

		function displayResult(name) {
			resultContainer.innerHTML = '<div class="generated-name-wrap">Your band name is:<br><div class="actual-name">' + name + '</div></div>';
			resultContainer.classList.add('fade-in');
			resultContainer.style.display = 'block';
			button.disabled = false;
			button.textContent = button.dataset.originalText || 'Generate Band Name';
		}

		function showMessage(message, type) {
			if (!messageContainer) {
				return;
			}

			const isError = type === 'error';
			messageContainer.classList.remove('message-error', 'message-info');
			messageContainer.classList.add(isError ? 'message-error' : 'message-info');
			messageContainer.textContent = message;
			messageContainer.classList.add('fade-in');
			messageContainer.style.display = 'block';

			setTimeout(() => {
				messageContainer.classList.remove('fade-in');
				messageContainer.classList.add('fade-out');
				setTimeout(() => {
					messageContainer.style.display = 'none';
					messageContainer.classList.remove('fade-out');
				}, 400);
			}, 3500);
		}
	});
});

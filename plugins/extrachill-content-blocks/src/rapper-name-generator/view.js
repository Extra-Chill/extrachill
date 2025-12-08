/**
 * Rapper Name Generator Block - Frontend View Script
 */

import apiFetch from '@wordpress/api-fetch';

document.addEventListener('DOMContentLoaded', function() {
	document.querySelectorAll('.extrachill-blocks-generator-form[data-generator-type="rapper"]').forEach(function(form) {
		const container = form.closest('.extrachill-blocks-rapper-name-generator');
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
			const gender = form.querySelector('#gender').value;
			const style = form.querySelector('#style').value;
			const numberOfWords = parseInt(form.querySelector('#number_of_words').value, 10);

			if (!input) {
				showMessage('Please enter your name', 'error');
				return;
			}

			// Disable button and show loading state
			button.disabled = true;
			button.textContent = 'Generating...';

			try {
				const response = await apiFetch({
					path: '/extrachill/v1/blocks/rapper-name',
					method: 'POST',
					data: {
						input,
						gender,
						style,
						number_of_words: numberOfWords
					}
				});

				if (response.name) {
					displayResult(response.name);
				}
			} catch (error) {
				showMessage(error.message || 'An error occurred', 'error');
				button.disabled = false;
				button.textContent = button.dataset.originalText || 'Generate Rapper Name';
			}
		});

		function displayResult(name) {
			resultContainer.innerHTML = '<div class="generated-name-wrap">Your rapper name is:<br><div class="actual-name">' + name + '</div></div>';
			resultContainer.classList.add('fade-in');
			resultContainer.style.display = 'block';
			button.disabled = false;
			button.textContent = button.dataset.originalText || 'Generate Rapper Name';
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

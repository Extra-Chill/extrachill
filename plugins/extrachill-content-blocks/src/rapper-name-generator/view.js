/**
 * Rapper Name Generator Block - Frontend View Script
 */

import $ from 'jquery';

$(document).ready(function() {
    $('.extrachill-blocks-generator-form[data-generator-type="rapper"]').each(function() {
        const $form = $(this);
        const $container = $form.closest('.extrachill-blocks-rapper-name-generator');
        const $button = $form.find('button[type="submit"]');
        const $resultContainer = $container.find('.extrachill-blocks-generator-result');
        const $messageContainer = $container.find('.extrachill-generator-message');

        $form.on('submit', function(e) {
            e.preventDefault();

            const input = $form.find('#input').val().trim();
            const gender = $form.find('#gender').val();
            const style = $form.find('#style').val();
            const numberOfWords = $form.find('#number_of_words').val();

            if (!input) {
                showMessage('Please enter your name', 'error');
                return;
            }

            // Disable button and show loading state
            $button.prop('disabled', true).text('Generating...');

            $.ajax({
                url: extraChillRapperNameGenerator.ajaxurl,
                type: 'POST',
                data: {
                    action: 'extrachill_blocks_rapper_name',
                    nonce: extraChillRapperNameGenerator.nonce,
                    input: input,
                    gender: gender,
                    style: style,
                    number_of_words: numberOfWords
                },
                success: function(response) {
                    if (response.success) {
                        displayResult(response.data.name);
                    } else {
                        showMessage(response.data.message || 'An error occurred', 'error');
                        $button.prop('disabled', false).text($button.data('original-text') || 'Generate Rapper Name');
                    }
                },
                error: function() {
                    showMessage('Network error. Please try again.', 'error');
                    $button.prop('disabled', false).text($button.data('original-text') || 'Generate Rapper Name');
                }
            });
        });

        function displayResult(name) {
            $resultContainer.html('<div class="generated-name-wrap">Your rapper name is:<br><div class="actual-name">' + name + '</div></div>');
            $resultContainer.fadeIn(300);
            $button.prop('disabled', false).text($button.data('original-text') || 'Generate Rapper Name');
        }

        function showMessage(message, type) {
            if (!$messageContainer.length) {
                return;
            }

            const isError = type === 'error';
            $messageContainer
                .removeClass('message-error message-info')
                .addClass(isError ? 'message-error' : 'message-info')
                .text(message)
                .fadeIn(200);

            setTimeout(() => {
                $messageContainer.fadeOut(400);
            }, 3500);
        }

        // Store original button text
        if (!$button.data('original-text')) {
            $button.data('original-text', $button.text());
        }
    });
});

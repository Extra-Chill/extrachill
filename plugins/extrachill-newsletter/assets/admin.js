/**
 * Newsletter Admin JavaScript
 *
 * Handles admin interface interactions for the newsletter settings page.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

jQuery(document).ready(function($) {
	$('#test-connection').on('click', function() {
		var button = $(this);
		var result = $('#connection-result');
		var apiKey = $('input[name="extrachill_newsletter_settings[sendy_api_key]"]').val();
		var sendyUrl = $('input[name="extrachill_newsletter_settings[sendy_url]"]').val();

		if (!apiKey || !sendyUrl) {
			result.html('<div class="notice notice-error"><p>' + newsletterAdmin.messages.missing_fields + '</p></div>');
			return;
		}

		button.prop('disabled', true).text(newsletterAdmin.messages.testing);
		result.empty();

		$.post(newsletterAdmin.ajaxurl, {
			action: 'newsletter_test_connection',
			api_key: apiKey,
			sendy_url: sendyUrl,
			nonce: newsletterAdmin.nonce
		}, function(response) {
			if (response.success) {
				result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
			} else {
				result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
			}
		}).fail(function() {
			result.html('<div class="notice notice-error"><p>' + newsletterAdmin.messages.connection_failed + '</p></div>');
		}).always(function() {
			button.prop('disabled', false).text(newsletterAdmin.messages.test_connection);
		});
	});
});
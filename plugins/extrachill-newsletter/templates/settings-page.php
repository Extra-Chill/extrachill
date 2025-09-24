<?php
/**
 * Newsletter Sendy Settings Page Template
 *
 * Admin interface for managing newsletter and Sendy configuration.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_newsletter_settings();
?>

<div class="wrap">
	<h1><?php _e('Newsletter Sendy Settings', 'extrachill-newsletter'); ?></h1>

	<?php settings_errors(); ?>

	<form method="post" action="options.php" id="newsletter-settings-form">
		<?php
		settings_fields('newsletter_settings_group');
		do_settings_sections('newsletter_settings');
		?>

		<div class="newsletter-connection-test">
			<h3><?php _e('Connection Test', 'extrachill-newsletter'); ?></h3>
			<p><?php _e('Test your Sendy API connection before saving settings.', 'extrachill-newsletter'); ?></p>
			<button type="button" id="test-connection" class="button button-secondary">
				<?php _e('Test Connection', 'extrachill-newsletter'); ?>
			</button>
			<div id="connection-result"></div>
		</div>

		<hr>

		<?php submit_button(); ?>
	</form>

	<div class="newsletter-help-section">
		<h3><?php _e('Help & Instructions', 'extrachill-newsletter'); ?></h3>

		<div class="help-box">
			<h4><?php _e('Getting Started', 'extrachill-newsletter'); ?></h4>
			<ol>
				<li><?php _e('Enter your Sendy API key and installation URL', 'extrachill-newsletter'); ?></li>
				<li><?php _e('Configure list IDs for different subscription forms', 'extrachill-newsletter'); ?></li>
				<li><?php _e('Set your email sender information', 'extrachill-newsletter'); ?></li>
				<li><?php _e('Choose which features to enable', 'extrachill-newsletter'); ?></li>
				<li><?php _e('Test the connection and save settings', 'extrachill-newsletter'); ?></li>
			</ol>
		</div>

		<div class="help-box">
			<h4><?php _e('Finding Your List IDs', 'extrachill-newsletter'); ?></h4>
			<p><?php _e('In your Sendy admin:', 'extrachill-newsletter'); ?></p>
			<ol>
				<li><?php _e('Go to "View all lists"', 'extrachill-newsletter'); ?></li>
				<li><?php _e('Click on a list name', 'extrachill-newsletter'); ?></li>
				<li><?php _e('Copy the list ID from the URL or page info', 'extrachill-newsletter'); ?></li>
			</ol>
		</div>

		<div class="help-box">
			<h4><?php _e('Subscription Forms', 'extrachill-newsletter'); ?></h4>
			<ul>
				<li><strong><?php _e('Archive Page:', 'extrachill-newsletter'); ?></strong> <?php _e('Form on newsletter archive page (/newsletters)', 'extrachill-newsletter'); ?></li>
				<li><strong><?php _e('Homepage:', 'extrachill-newsletter'); ?></strong> <?php _e('Newsletter signup section on homepage', 'extrachill-newsletter'); ?></li>
				<li><strong><?php _e('Popup:', 'extrachill-newsletter'); ?></strong> <?php _e('Site-wide popup subscription form', 'extrachill-newsletter'); ?></li>
				<li><strong><?php _e('Navigation:', 'extrachill-newsletter'); ?></strong> <?php _e('Newsletter form in site navigation menu', 'extrachill-newsletter'); ?></li>
				<li><strong><?php _e('Campaigns:', 'extrachill-newsletter'); ?></strong> <?php _e('Main list for sending newsletter campaigns', 'extrachill-newsletter'); ?></li>
			</ul>
		</div>

		<div class="help-box">
			<h4><?php _e('Current Status', 'extrachill-newsletter'); ?></h4>
			<div id="status-info">
				<p><strong><?php _e('API Configuration:', 'extrachill-newsletter'); ?></strong>
					<span class="status-indicator <?php echo !empty($settings['sendy_api_key']) && !empty($settings['sendy_url']) ? 'configured' : 'not-configured'; ?>">
						<?php echo !empty($settings['sendy_api_key']) && !empty($settings['sendy_url']) ? __('Configured', 'extrachill-newsletter') : __('Not Configured', 'extrachill-newsletter'); ?>
					</span>
				</p>
				<p><strong><?php _e('Lists Configured:', 'extrachill-newsletter'); ?></strong>
					<?php
					$list_fields = array('archive_list_id', 'homepage_list_id', 'popup_list_id', 'navigation_list_id', 'campaign_list_id');
					$configured_lists = 0;
					foreach ($list_fields as $field) {
						if (!empty($settings[$field])) {
							$configured_lists++;
						}
					}
					echo $configured_lists . ' / ' . count($list_fields);
					?>
				</p>
				<p><strong><?php _e('Features Enabled:', 'extrachill-newsletter'); ?></strong>
					<?php
					$enabled_features = array();
					if ($settings['enable_popup']) {
						$enabled_features[] = __('Popup', 'extrachill-newsletter');
					}
					if ($settings['enable_navigation']) {
						$enabled_features[] = __('Navigation', 'extrachill-newsletter');
					}
					echo !empty($enabled_features) ? implode(', ', $enabled_features) : __('None', 'extrachill-newsletter');
					?>
				</p>
			</div>
		</div>
	</div>
</div>


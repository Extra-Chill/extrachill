<?php
/**
 * Homepage Newsletter Section Template
 *
 * Template for the newsletter signup section displayed on the homepage.
 * This template is provided by the ExtraChill Newsletter plugin.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Don't display if not on front page
if ( ! is_front_page() ) {
    return;
}
?>

<div class="home-newsletter-about-row newsletter-homepage-section">
    <div class="home-about-box">
        <h2 class="home-about-header"><?php _e('About Extra Chill', 'extrachill-newsletter'); ?></h2>
        <div class="home-about-bio">
            <?php _e('Founded in 2011 in Charleston, SC, and now local to Austin, TX, Extra Chill is a laid-back corner of the music industry. We value storytelling and believe in the power of community. Our platform is a place for the underground to thrive, connect, and grow.', 'extrachill-newsletter'); ?>
        </div>
        <div class="home-about-links">
            <a href="/about" class="home-about-link">
                <?php _e('Learn More About Extra Chill', 'extrachill-newsletter'); ?>
            </a>
        </div>
    </div>

    <div class="home-newsletter-signup">
        <h2 class="home-newsletter-header"><?php _e('A Note from the Editor', 'extrachill-newsletter'); ?></h2>
        <p class="home-newsletter-subhead">
            <?php _e('Stories, reflections, and music industry insights from the underground.', 'extrachill-newsletter'); ?>
        </p>

        <form id="homepageNewsletterForm" class="newsletter-form home-newsletter-form">
            <label for="newsletter-email-home" class="sr-only">
                <?php _e('Email address for newsletter', 'extrachill-newsletter'); ?>
            </label>
            <input
                type="email"
                id="newsletter-email-home"
                name="email"
                required
                placeholder="<?php esc_attr_e('Your email for the inside scoop...', 'extrachill-newsletter'); ?>"
                aria-label="<?php esc_attr_e('Email address', 'extrachill-newsletter'); ?>"
            >
            <input type="hidden" name="action" value="subscribe_to_sendy_home">
            <?php wp_nonce_field('subscribe_to_sendy_home_nonce', 'subscribe_to_sendy_home_nonce_field'); ?>
            <button type="submit"><?php _e('Get the Letter', 'extrachill-newsletter'); ?></button>
        </form>

        <p class="newsletter-feedback" style="display:none;" aria-live="polite"></p>

        <div class="newsletter-homepage-links">
            <?php $archive_url = get_post_type_archive_link('newsletter'); ?>
            <?php if ( $archive_url ) : ?>
                <p class="past-newsletters-link">
                    <a href="<?php echo esc_url( $archive_url ); ?>">
                        <?php _e('Browse past newsletters', 'extrachill-newsletter'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('homepageNewsletterForm');
    if (!form) return;

    const emailInput = document.getElementById('newsletter-email-home');
    const feedback = document.querySelector('.newsletter-feedback');
    const submitButton = form.querySelector('button[type="submit"]');
    const nonceField = document.getElementById('subscribe_to_sendy_home_nonce_field');

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Disable button and show loading state
        const originalButtonText = submitButton.textContent;
        submitButton.disabled = true;
        submitButton.textContent = '<?php echo esc_js(__('Subscribing...', 'extrachill-newsletter')); ?>';
        feedback.style.display = 'none';

        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'subscribe_to_sendy_home');
        formData.append('email', emailInput.value);
        formData.append('nonce', nonceField ? nonceField.value : '');

        // Use the localized AJAX URL if available, otherwise fallback
        const ajaxUrl = window.extrachill_ajax_object?.ajax_url || '<?php echo admin_url('admin-ajax.php'); ?>';

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            feedback.style.display = 'block';

            if (data.success) {
                feedback.textContent = data.data || '<?php echo esc_js(__('Successfully subscribed!', 'extrachill-newsletter')); ?>';
                feedback.style.color = '#28a745';
                feedback.className = 'newsletter-feedback success';
                emailInput.value = '';

                // Update localStorage for popup script coordination
                if (window.localStorage) {
                    localStorage.setItem('subscribed', 'true');
                    localStorage.setItem('lastSubscribedTime', Date.now().toString());
                }
            } else {
                feedback.textContent = data.data || '<?php echo esc_js(__('Subscription failed. Please try again.', 'extrachill-newsletter')); ?>';
                feedback.style.color = '#dc3545';
                feedback.className = 'newsletter-feedback error';
            }
        })
        .catch(error => {
            console.error('Newsletter subscription error:', error);
            feedback.style.display = 'block';
            feedback.textContent = '<?php echo esc_js(__('An error occurred. Please try again.', 'extrachill-newsletter')); ?>';
            feedback.style.color = '#dc3545';
            feedback.className = 'newsletter-feedback error';
        })
        .finally(() => {
            // Reset button state
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        });
    });
});
</script>
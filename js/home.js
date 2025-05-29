document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('homepageNewsletterForm');
    if (form) {
        var emailInput = document.getElementById('newsletter-email');
        var feedback = document.querySelector('.newsletter-feedback');
        var submitButton = form.querySelector('button[type="submit"]');
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitButton.disabled = true;
            feedback.style.display = 'none';
            var formData = new FormData(form);
            formData.append('nonce', document.getElementById('subscribe_to_sendy_home_nonce_field').value);
            fetch(extrachill_ajax_object.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                feedback.style.display = 'block';
                if (data.success) {
                    feedback.textContent = data.data;
                    feedback.style.color = 'green';
                    emailInput.value = '';
                    localStorage.setItem('subscribed', 'true');
                } else {
                    feedback.textContent = data.data || 'An error occurred. Please try again.';
                    feedback.style.color = 'red';
                }
                submitButton.disabled = false;
            })
            .catch(error => {
                feedback.style.display = 'block';
                feedback.textContent = 'An error occurred. Please try again.';
                feedback.style.color = 'red';
                submitButton.disabled = false;
            });
        });
    }
}); 
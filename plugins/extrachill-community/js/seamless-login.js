jQuery(document).ready(function($) {
    // Retrieve and check for the session token
    function getCookie(name) {
        let cookieArray = document.cookie.split(';');
        for (let i = 0; i < cookieArray.length; i++) {
            let cookiePair = cookieArray[i].split('=');
            if (name === cookiePair[0].trim()) {
                return decodeURIComponent(cookiePair[1]);
            }
        }
        return null;
    }

function setSessionToken(token) {
    const expires = new Date(Date.now() + 86400 * 1000); // 1 day from now
    document.cookie = `ecc_user_session_token=${token};expires=${expires.toUTCString()};path=/;domain=.extrachill.com;Secure;SameSite=None`;
}


    function hideLoginForm() {
        $('.community-login-form').hide(); // Hide the login form container after successful login
    }

    function fetchAndDisplayLoginForm() {
        $.ajax({
            url: 'https://community.extrachill.com/wp-json/extrachill/v1/serve_login_form',
            type: 'GET',
            success: function(html) {
                $('.community-login-form').html(html).show();
                bindLoginFormSubmission(); // Ensure form submission is bound after loading
            },
            error: function() {
                console.error('Failed to fetch login form');
            }
        });
    }

function bindLoginFormSubmission() {
        $(document).on('submit', '#ecc_ajax_login_form', function(e) {
            e.preventDefault();
            var formData = $(this).serializeArray(); // Serialize data for easy manipulation
            var credentials = {
                username: '',
                password: '',
                rememberme: false
            };
            
            // Convert form data to structured object
            $.each(formData, function(_, kv) {
                if (kv.name === 'username') credentials.username = kv.value;
                if (kv.name === 'password') credentials.password = kv.value;
                if (kv.name === 'rememberme') credentials.rememberme = kv.value === '1';
            });

            // Directly interact with the REST API endpoint on community.extrachill.com
            $.ajax({
                type: 'POST',
                url: 'https://community.extrachill.com/wp-json/extrachill/v1/handle_external_login',
                contentType: "application/json",
                data: JSON.stringify(credentials),
                success: function(response) {
                    if (response.success && response.ecc_user_session_token) {
                        setSessionToken(response.ecc_user_session_token);
                        hideLoginForm(); // Hide login form on success
                        $(document).trigger('ecc:loginSuccess'); // Custom event on successful login
                    } else {
                        console.error('Login failed:', response.message);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Login request failed:', textStatus, errorThrown);
                }
            });
        });
    }

    if (!getCookie('ecc_user_session_token')) {
        fetchAndDisplayLoginForm();
    } else {
        bindLoginFormSubmission(); // Bind form submission even if the cookie exists for dynamic login handling
    }
});

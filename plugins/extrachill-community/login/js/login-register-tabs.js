document.addEventListener('DOMContentLoaded', function() {
    const signUpLink = document.querySelector('.js-switch-to-register');
    if (signUpLink) {
        signUpLink.addEventListener('click', function(event) {
            event.preventDefault(); // Prevent the default link behavior
            
            // Find the parent shared-tabs-component
            const tabsComponent = signUpLink.closest('.shared-tabs-component');
            
            if (tabsComponent) {
                // Find the register tab button within this component
                const registerTabButton = tabsComponent.querySelector('.shared-tab-button[data-tab="tab-register"]');
                
                if (registerTabButton) {
                    // Trigger a click on the register tab button
                    registerTabButton.click();
                    
                    // Optionally update the URL hash without a reload
                    if (history.pushState) {
                         history.pushState(null, null, window.location.pathname + window.location.search.split('#')[0] + '#tab-register');
                     } else {
                         window.location.hash = '#tab-register';
                     }
                }
            }
        });
    }
});

// Cloudflare Turnstile callback function for registration form
// This function is called by Turnstile when the challenge is successfully solved.
function community_register(token) {
    console.log('Turnstile callback fired with token:', token);
    // Find the registration form
    const registerForm = document.querySelector('.login-register-form form'); // Assuming the form is within .login-register-form

    if (registerForm) {
        // Find or create a hidden input to hold the Turnstile response
        let turnstileInput = registerForm.querySelector('input[name="cf-turnstile-response"]');
        if (!turnstileInput) {
            turnstileInput = document.createElement('input');
            turnstileInput.type = 'hidden';
            turnstileInput.name = 'cf-turnstile-response';
            registerForm.appendChild(turnstileInput);
        }
        
        // Set the token value
        turnstileInput.value = token;

        // Optionally, re-enable the submit button or show a message
        // const submitButton = registerForm.querySelector('input[type="submit"]');
        // if (submitButton) {
        //     submitButton.disabled = false; // Example: re-enable button
        // }

    } else {
        console.error('Registration form not found.');
    }
}

// Remove the previous event listener that prevented submission if token was missing.
// The form will now be submitted directly by the community_register callback.
document.addEventListener('DOMContentLoaded', function() {
    const registerForm = document.querySelector('.login-register-form form');
    const errorDiv = document.querySelector('.login-register-form .login-register-errors'); // Find the error div within the form container

    if (registerForm && errorDiv) {
    } else if (!registerForm) {
        console.error('Registration form not found for submit listener.');
    } else if (!errorDiv) {
         console.error('Error display div (.login-register-errors) not found for submit listener.');
    }
}); 
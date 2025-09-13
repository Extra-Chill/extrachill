window.addEventListener('load', function() {
    var preloadedUserDetails = window.preloadedUserDetails || false;

    function hideLoginForm() {
        document.querySelector('.community-login-form').style.display = 'none';
    }
    
    function displayLoginForm() {
        if (!preloadedUserDetails && !getCookie('ecc_user_session_token')) {
            fetchAndDisplayLoginForm();
        } else {
            hideLoginForm();
        }
    }
    
    function fetchAndDisplayLoginForm() {
        fetch('https://community.extrachill.com/wp-json/extrachill/v1/serve_login_form')
        .then(response => response.text())
        .then(html => {
            const formElement = document.querySelector('.community-login-form');
            formElement.innerHTML = html;
            formElement.style.display = 'block';
            bindLoginFormSubmission();
        })
        .catch(error => console.error('Failed to fetch login form'));
    }
    
    
    function setCookieFromServer(name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "") + expires + "; path=/; Secure; SameSite=None; domain=.extrachill.com";
    }

    function getCookie(name) {
        var cookieArray = document.cookie.split(';');
        for (var i = 0; i < cookieArray.length; i++) {
            var cookiePair = cookieArray[i].split('=');
            if (name === cookiePair[0].trim()) {
                return decodeURIComponent(cookiePair[1]);
            }
        }
        return null;
    }

    function bindLoginFormSubmission() {
        document.addEventListener('submit', function(e) {
            if (e.target.id === 'ecc_ajax_login_form') {
                e.preventDefault();
                var formData = new FormData(e.target); // Use FormData to serialize form
                
                fetch('https://community.extrachill.com/wp-json/extrachill/v1/handle_external_login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(Object.fromEntries(formData)),
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        setCookieFromServer('ecc_user_session_token', response.ecc_user_session_token, 180);
                        hideLoginForm();
                        console.log('Login successful.');
                        // Assuming response includes user details necessary for comments form
                        const userDetails = response.userDetails || false; // Adjust according to actual response structure
                        if (userDetails) {
                            fetchAndDisplayCommentForm(userDetails); // Now fetching the comment form upon successful login
                        }
                        // Trigger custom event for loginSuccess
                        const loginSuccessEvent = new CustomEvent('loginSuccess', { detail: response });
                        document.dispatchEvent(loginSuccessEvent);
                    } else {
                        displayLoginErrorMessage(response.message || 'Login failed: Invalid credentials.');
                    }
                })
                .catch(() => displayLoginErrorMessage('Login request failed. Please try again later.'));
            }
        });
    }
    
    
    

    function displayLoginErrorMessage(message) {
        const existingErrorMessage = document.querySelector('.login-error-message');
        if (existingErrorMessage) existingErrorMessage.remove();
    
        const errorMessage = document.createElement('div');
        errorMessage.className = 'login-error-message';
        errorMessage.style.color = 'red';
        errorMessage.innerHTML = message;
        const loginForm = document.querySelector('.community-login-form');
        loginForm.parentNode.insertBefore(errorMessage, loginForm);
    
        setTimeout(() => {
            errorMessage.style.opacity = '0';
            errorMessage.addEventListener('transitionend', () => errorMessage.remove());
        }, 8000);
    }
    
    

    displayLoginForm();

    // Assuming preloadedUserDetails is a global variable set by PHP when user details are available.
    var preloadedUserDetails = window.preloadedUserDetails || false;

    function initCommentForm() {
        if (preloadedUserDetails) {
            // Directly use preloaded user details to display the comment form
            fetchAndDisplayCommentForm(preloadedUserDetails);
        } else {
            // User not logged in or details not preloaded
            handleUserNotLoggedIn();
        }
    }

    function fetchAndDisplayCommentForm(userDetails) {
        // AJAX call to get the comment form
        fetch('https://community.extrachill.com/wp-json/extrachill/v1/comments/form')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text();
        })
        .then(htmlContent => {
            const commentFormContainer = document.querySelector('.community-comment-form');
            commentFormContainer.innerHTML = htmlContent;
            applyUserDetailsToForm(userDetails); // Apply user details to form before displaying it.
    
            commentFormContainer.style.display = 'block';
            initReplyLinks(); // Initialize reply links here.
        })
        .catch(error => {
            console.error('Failed to fetch the comment form:', error);
        });
    }
    

    function applyUserDetailsToForm(userDetails) {
        document.getElementById('user-name').textContent = userDetails.username || 'Guest';
        const commentForm = document.getElementById('community-comment-form');
        commentForm.setAttribute('data-username', userDetails.username);
        commentForm.setAttribute('data-email', userDetails.email);
        commentForm.setAttribute('data-user-id', userDetails.userID);
        // Apply the post ID during this phase
        if (extrachillPostData && extrachillPostData.postId) {
            commentForm.setAttribute('data-post-id', extrachillPostData.postId);
        } else {
            console.error('Post ID is not available for the comment form.');
        }
        displayUserGreeting(userDetails.username);
    }
    
    
    function displayUserGreeting(username) {
        var userUrl = 'https://community.extrachill.com/u/' + encodeURIComponent(username);
        var message = `<p>Logged in as <a href="${userUrl}">${username}</a>.</p>`;
        document.querySelector('.comment-message').innerHTML = message;
    }
    

    function handleUserNotLoggedIn() {
        // Handle scenarios where the user is not logged in
        document.querySelector('.community-comment-form').style.display = 'none';
    }

    function initReplyLinks() {
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('comment-reply-link')) {
                e.preventDefault();
                var commentID = e.target.dataset.commentid;
                document.getElementById('comment_parent').value = commentID;
                // Scroll and focus
                document.getElementById('community-comment-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.getElementById('comment').focus();
            }
        });
    }
    

    // Initialize the comment form once the page is fully loaded
    initCommentForm();


    


    document.addEventListener('submit', function(e) {
        if (e.target.matches('#community-comment-form form')) {
            e.preventDefault();
            // Show loading indicator
            const loadingMessage = document.createElement('div');
            loadingMessage.className = 'comment-submission-message';
            loadingMessage.style.color = 'blue';
            loadingMessage.textContent = 'Submitting...';
            document.querySelector('.community-comment-form').after(loadingMessage);
        
            const formData = {
                post_id: document.querySelector('#community-comment-form').getAttribute('data-post-id'),
                comment: document.getElementById('comment').value,
                community_user_id: document.querySelector('#community-comment-form').getAttribute('data-user-id'),
                author: document.querySelector('#community-comment-form').getAttribute('data-username'),
                email: document.querySelector('#community-comment-form').getAttribute('data-email'),
                comment_parent: document.getElementById('comment_parent').value,
            };
        
            fetch(extrachillPostData.restUrl + 'community-comment', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData),
            })
            .then(response => response.json())
            .then(handleCommentSuccess)
            .catch(handleCommentError)
            .finally(() => {
                // Corrected fadeOut effect for vanilla JS
                loadingMessage.style.transition = 'opacity 1s ease-out';
                loadingMessage.style.opacity = '0';
                loadingMessage.addEventListener('transitionend', () => loadingMessage.remove());
            });
        }
    });
        
    function handleCommentSuccess(response) {
        // Display success message
        const commentForm = document.querySelector('.community-comment-form');
        const successMessage = document.createElement('div');
        successMessage.className = 'comment-submission-message';
        successMessage.style.color = 'green';
        successMessage.textContent = 'Comment submitted successfully! Refresh to view.';
        commentForm.parentNode.insertBefore(successMessage, commentForm.nextSibling);
        
        setTimeout(() => {
            successMessage.style.transition = 'opacity 1s ease-out';
            successMessage.style.opacity = '0';
            successMessage.addEventListener('transitionend', () => successMessage.remove());
        }, 4000);
        
        document.querySelector('#community-comment-form form').reset();
        document.getElementById('comment_parent').value = '0';
        
    }
    
    function handleCommentError(error) {
        // Display error message
        const errorMessage = document.createElement('div');
        errorMessage.className = 'comment-submission-message';
        errorMessage.style.color = 'red';
        errorMessage.textContent = 'Failed to submit comment. Please try again.';
        commentForm.parentNode.insertBefore(errorMessage, commentForm.nextSibling);
        
        setTimeout(() => {
            errorMessage.style.transition = 'opacity 1s ease-out';
            errorMessage.style.opacity = '0';
            errorMessage.addEventListener('transitionend', () => errorMessage.remove());
        }, 4000);
        
    }    
});
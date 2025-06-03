jQuery(document).ready(function($) {
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

    function displayCommentForm() {
        var token = getCookie('ecc_user_session_token');
        if (!token) {
            return;
        }

        var postId = $('body').data('post-id');
        $.ajax({
            url: 'https://community.extrachill.com/wp-json/extrachill/v1/comments/form',
            type: 'GET',
            success: function(response) {
                if (response.form) {
                    $('.community-comment-form').html(response.form);
                    $('#community-comment-form').attr('data-post-id', postId);
                    fetchUserDetails(token); // Fetch and display user details
                }
            },
            error: function() {
            }
        });
    }

        function displayUserGreeting(username) {
        let userUrl = 'https://community.extrachill.com/u/' + encodeURIComponent(username);
        let message = `<p>Logged in as <a href="${userUrl}">${username}</a>.</p>`;
        $('.community-login-form').html(message);
    }

    function fetchUserDetails(token) {
        $.ajax({
            type: 'GET',
            url: 'https://community.extrachill.com/wp-json/extrachill/v1/user_details',
            headers: {
                'Authorization': 'Bearer ' + token
            },
            success: function(userDetails) {
                applyUserDetailsToForm(userDetails);
            },
            error: function(xhr, status, error) {
            }
        });
    }

    function applyUserDetailsToForm(userDetails) {
        $('#user-name').text(userDetails.username || 'Guest');
        $('#community-comment-form').attr('data-username', userDetails.username);
        $('#community-comment-form').attr('data-email', userDetails.email);
    }

    $(document).on('ecc:loginSuccess', function() {
        displayCommentForm(); // This already implicitly fetches user details
        displayUserGreeting(userDetails.username); // Now we ensure the username is passed correctly

    });

    // Trigger displayCommentForm initially to handle case where user is already logged in
    displayCommentForm();
});
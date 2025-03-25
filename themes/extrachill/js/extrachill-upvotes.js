document.addEventListener('DOMContentLoaded', function() {
    // Check if user details are available from PHP
    const userDetails = window.userDetails || {};
    console.log('Userdetails:', userDetails);
    const loggedIn = !!userDetails.userID; // Check for logged-in status
    const communityUserId = userDetails.userID || null; // Use user ID if logged in

    console.log('User logged in:', loggedIn, 'Community User ID:', communityUserId);

    const upvoteIcons = document.querySelectorAll('.upvote-icon');

    function encodeForAjax(data) {
        const params = new URLSearchParams();
        Object.keys(data).forEach(key => {
            if (Array.isArray(data[key])) {
                data[key].forEach(value => params.append(`${key}[]`, value));
            } else {
                params.append(key, data[key]);
            }
        });
        return params.toString();
    }
    
    function checkAndAdjustUpvoteIcons() {
        if (loggedIn && communityUserId) {
            const postIds = Array.from(upvoteIcons).map(icon => icon.dataset.postId);
    
            if (postIds.length > 0) {
                fetch(upvoteParams.ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: encodeForAjax({
                        action: 'check_upvotes_bulk',
                        'post_ids': postIds,
                        community_user_id: communityUserId,
                        nonce: upvoteParams.nonce
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(response => {
                    if (response.success) {
                        Object.entries(response.data).forEach(([postId, hasUpvoted]) => {
                            const iconUse = document.querySelector(`.upvote-icon[data-post-id="${postId}"] svg use`);
                            iconUse.setAttribute('href', `/wp-content/themes/colormag-pro/fonts/fontawesome.svg${hasUpvoted ? '?v=1.2#circle-up-solid' : '#circle-up-regular'}`);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error checking upvote status in bulk:', error);
                });
            }
        }
    }

    checkAndAdjustUpvoteIcons();

    let tooltip = document.querySelector('.tooltip-overlay');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.className = 'tooltip-overlay';
        tooltip.innerHTML = '<b>Community feature:</b> <a href="https://community.extrachill.com/login">Log In</a> to upvote.';
        document.body.appendChild(tooltip);
    }

    function createAndShowTooltip(element) {
        const offset = element.getBoundingClientRect();
        tooltip.style.cssText = `top: ${offset.top + window.scrollY + element.offsetHeight + 5}px; left: ${offset.left + window.scrollX}px; display: block;`;

        document.addEventListener('click', () => tooltip.style.display = 'none', { once: true });
    }

    document.body.addEventListener('click', function(e) {
        const target = e.target.closest('.upvote-icon');
        if (target) {
            e.stopPropagation();
            if (!communityUserId) {
                createAndShowTooltip(target);
                return;
            }

            const postId = target.dataset.postId;
            const nonce = target.dataset.nonce;
            const countSpan = target.nextElementSibling;
            const currentCount = parseInt(countSpan.textContent) || 0;
            const iconUse = target.querySelector('svg use');
            const isCurrentlyUpvoted = iconUse.getAttribute('href').includes('solid');

            countSpan.textContent = isCurrentlyUpvoted ? currentCount - 1 : currentCount + 1;
            iconUse.setAttribute('href', '/wp-content/themes/colormag-pro/fonts/fontawesome.svg' + (isCurrentlyUpvoted ? '#circle-up-regular' : '?v=1.2#circle-up-solid'));

            console.log('Handling upvote for user ID:', communityUserId, 'Post ID:', postId);

            fetch(upvoteParams.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: encodeForAjax({
                    action: 'handle_upvote',
                    post_id: postId,
                    community_user_id: communityUserId,
                    nonce: nonce
                })
            })
            .then(response => response.json())
            .then(response => {
                if (!response.success) {
                    console.error('Error handling upvote:', response.data.message);
                }
            })
            .catch(error => {
                console.error('AJAX error handling upvote:', error);
            });
        }
    });
});

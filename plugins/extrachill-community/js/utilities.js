jQuery(document).ready(function($) {
    // Function to switch sections without reloading the page
    function switchSection(section, userId) {
        // Make AJAX request to fetch the section content
        $.ajax({
            url: extrachillQuote.ajaxurl, // Use localized ajaxurl
            type: 'POST',
            data: {
                action: 'load_social_section',
                section: section,
                user_id: userId
            },
            success: function(response) {
                // Assuming your container for displaying the list has a specific class
                $('.list-social-network-page').html(response);
            },
            error: function() {
                console.log('Error loading content');
            }
        });
    }

    // Listen for changes on the dropdown and trigger content switch
    $('#social-section-switch').change(function() {
        var selectedSection = $(this).val();
        var userId = $(this).data('user-id'); // Assuming you pass the user ID as a data attribute to your select element
        switchSection(selectedSection, userId);
    });
});




/*tooltips */

document.addEventListener('DOMContentLoaded', function() {
    let tooltip;

    function createTooltip() {
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            document.body.appendChild(tooltip);
        }
    }

    function showTooltip(element) {
        createTooltip();
        tooltip.innerText = element.getAttribute('data-title');
        const rect = element.getBoundingClientRect();
        tooltip.style.left = `${rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)}px`;
        const verticalOffset = 5;
        tooltip.style.top = `${rect.bottom + window.scrollY + verticalOffset}px`;
        tooltip.style.display = 'block';
    }

    function hideTooltip() {
        if (tooltip) {
            tooltip.style.display = 'none';
        }
    }

    document.body.addEventListener('click', function(e) {
        const target = e.target.closest('[data-title]');
        if (target) {
            showTooltip(target);
            e.stopPropagation(); // Prevent immediate hide
        } else {
            hideTooltip();
        }
    });

    // Adjust for touch devices
    document.body.addEventListener('touchstart', function(e) {
        const target = e.target.closest('[data-title]');
        if (target) {
            e.preventDefault(); // Prevent the browser's default touch action
            showTooltip(target);
            // Consider not hiding the tooltip immediately upon touch end to improve experience
        }
    }, {passive: false}); // Ensure we can call preventDefault

    document.querySelectorAll('[data-title]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            showTooltip(this);
        });
        element.addEventListener('mouseleave', hideTooltip);
    });
});



// keep long usernames in view on mobile

document.addEventListener("DOMContentLoaded", function() {
    // Function to dynamically adjust margin for usernames based on their length
    function adjustUsernameMargin() {
        // Ensuring we only tweak this for the mobile homies
        if (window.innerWidth <= 425) { 
            var usernames = document.querySelectorAll('.bbp-topic-freshness-author');

            usernames.forEach(function(username) {
                var usernameLength = username.textContent.trim().length;

                // Check if username length is over 11 chars
                if (usernameLength > 11) {
                    // Calculate margin adjustment based on character count over 11, starting at -20px
                    var extraChars = usernameLength - 11;
                    var marginLeft = -6 - (extraChars * 4); // Decrease margin by 2px for each extra character
                    username.style.marginLeft = marginLeft + 'px';
                }
                // If username length is 11 or less, we don't mess with the style
            });
        }
    }

    // Spark it up initially and on window resize
    adjustUsernameMargin();
    window.addEventListener('resize', adjustUsernameMargin);
});


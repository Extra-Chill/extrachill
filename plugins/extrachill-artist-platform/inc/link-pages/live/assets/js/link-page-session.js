// JavaScript for handling session validation and showing the edit button on the link page

(function() {
    // Check if required data is available from wp_localize_script
    if (typeof extrchSessionData === 'undefined' || !extrchSessionData.rest_url || !extrchSessionData.artist_id) {
        return;
    }

    const { rest_url, artist_id } = extrchSessionData; // Get artist_id instead of link_page_id

    /**
     * Checks user permissions via the REST API and shows the edit button if allowed.
     * Includes retry logic and timeout handling for better mobile compatibility.
     */
    function checkManageAccess(retryCount = 0) {
        const maxRetries = 2;
        const timeoutMs = 3000; // Reduced timeout for faster response
        
        // Don't create multiple edit buttons if one already exists
        if (document.querySelector('.extrch-link-page-edit-btn')) {
            return;
        }

        const apiUrl = `${rest_url}extrachill/v1/check-artist-manage-access/${artist_id}`;

        // Create abort controller for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => {
            controller.abort();
        }, timeoutMs);

        fetch(apiUrl, {
            method: 'GET',
            credentials: 'include', // Important: Ensures cookies are sent with cross-origin requests
            headers: {
                'Content-Type': 'application/json',
            },
            signal: controller.signal
        })
            .then(response => {
                clearTimeout(timeoutId);
                
                if (!response.ok) {
                    return response.json().catch(() => ({ error: 'Failed to parse error response', status: response.status }));
                }
                return response.json();
            })
            .then(data => {
                
                if (data && data.canManage) {
                    // Create edit button only if user has permission
                    createEditButton();
                } else {
                    // Apply fallback logic for certain error conditions
                    if (retryCount === 0 && shouldRetryRequest(data)) {
                        setTimeout(() => checkManageAccess(retryCount + 1), 1000);
                    }
                    // No else needed - unauthorized users get no button at all
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                
                // Retry logic for certain types of failures
                if (retryCount < maxRetries && shouldRetryOnError(error)) {
                    setTimeout(() => checkManageAccess(retryCount + 1), 1500);
                    return;
                }
                
                // Final fallback logic when all AJAX attempts fail
                if (shouldShowButtonAsFallback()) {
                    if (editButton) {
                        editButton.style.display = 'flex';
                        // Add visual indicator that this is fallback mode
                        editButton.title = 'Edit (fallback mode - may require re-login)';
                    }
                } else {
                    // Ensure button is hidden on final error
                    if (editButton) {
                        editButton.style.display = 'none';
                    }
                }
            });
    }

    /**
     * Creates and injects the edit button into the page
     */
    function createEditButton() {
        const manageUrl = `https://community.extrachill.com/manage-link-page/?artist_id=${artist_id}`;
        
        // Create the edit button element
        const editButton = document.createElement('a');
        editButton.href = manageUrl;
        editButton.className = 'extrch-link-page-edit-btn';
        editButton.innerHTML = '<i class="fas fa-pencil-alt"></i>';
        
        // Insert the button at the top of the body (after noscript)
        const noscript = document.querySelector('noscript');
        if (noscript && noscript.nextSibling) {
            document.body.insertBefore(editButton, noscript.nextSibling);
        } else {
            document.body.appendChild(editButton);
        }
    }

    /**
     * Determines if the request should be retried based on the response data
     */
    function shouldRetryRequest(data) {
        // Retry if we got a server error or unexpected response
        return data && (data.error || data.status >= 500);
    }

    /**
     * Determines if the request should be retried based on the error type
     */
    function shouldRetryOnError(error) {
        // Retry on network errors, timeouts, but not on CORS or security errors
        return error.name === 'TypeError' || 
               error.name === 'AbortError' || 
               error.message.includes('Failed to fetch') ||
               error.message.includes('NetworkError');
    }

    /**
     * Detect mobile devices with more comprehensive check
     */
    function isMobileDevice() {
        return /Mobi|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || 
               window.innerWidth <= 768 ||
               ('ontouchstart' in window) || 
               (navigator.maxTouchPoints > 0);
    }

    /**
     * Attempts cross-domain session synchronization when user lacks session token
     */
    function attemptCrossDomainSessionSync() {
        
        // Create hidden iframe to trigger session check on community.extrachill.com
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.style.width = '1px';
        iframe.style.height = '1px';
        
        // URL that will trigger session token auto-login on community domain
        const syncUrl = 'https://community.extrachill.com/wp-admin/admin-ajax.php?action=sync_session_token&artist_id=' + artist_id;
        
        iframe.onload = function() {
            // Give it a moment then retry the access check
            setTimeout(() => {
                checkManageAccess(0); // Retry with fresh session
            }, 500);
            
            // Clean up iframe after delay
            setTimeout(() => {
                if (iframe.parentNode) {
                    iframe.parentNode.removeChild(iframe);
                }
            }, 2000);
        };
        
        iframe.onerror = function() {
        };
        
        iframe.src = syncUrl;
        document.body.appendChild(iframe);
    }

    /**
     * Fallback logic to determine if edit button should be shown when AJAX fails
     */
    function shouldShowButtonAsFallback() {
        const url = new URL(window.location.href);
        const referrer = document.referrer;
        
        // Check for debug mode
        if (url.searchParams.has('debug_edit_button') || localStorage.getItem('debug_edit_button')) {
            return true;
        }
        
        // Check if user came from management interface - trigger session sync
        if (referrer && (referrer.includes('/manage-link-page') || referrer.includes('/manage-artist-profile'))) {
            attemptCrossDomainSessionSync();
            return false; // Don't show button immediately, wait for sync
        }
        
        // Check if URL has edit parameter (could be set by management interface)
        if (url.searchParams.has('edit') || url.searchParams.has('manage')) {
            attemptCrossDomainSessionSync();
            return false; // Don't show button immediately, wait for sync
        }
        
        // Check localStorage for recent management activity
        const recentManagement = localStorage.getItem('extrch_recent_artist_management');
        if (recentManagement) {
            try {
                const data = JSON.parse(recentManagement);
                const timeDiff = Date.now() - data.timestamp;
                // If managed this band within the last 10 minutes
                if (data.artist_id == artist_id && timeDiff < 600000) {
                    attemptCrossDomainSessionSync();
                    return false; // Don't show button immediately, wait for sync
                }
            } catch (e) {
            }
        }
        
        // Check if running on localhost/development (admins might want to see button for debugging)
        if (window.location.hostname === 'localhost' || 
            window.location.hostname === '127.0.0.1' ||
            window.location.hostname.includes('dev') ||
            window.location.hostname.includes('staging')) {
            return true;
        }
        
        return false;
    }

    // Run the check when the DOM is fully loaded
    document.addEventListener('DOMContentLoaded', checkManageAccess);

})(); 

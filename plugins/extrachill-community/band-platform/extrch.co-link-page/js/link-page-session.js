// JavaScript for handling session validation and showing the edit button on the link page

(function() {
    // Check if required data is available from wp_localize_script
    if (typeof extrchSessionData === 'undefined' || !extrchSessionData.rest_url || !extrchSessionData.band_id) {
        return;
    }

    const { rest_url, band_id } = extrchSessionData; // Get band_id instead of link_page_id

    /**
     * Checks user permissions via the REST API and shows the edit button if allowed.
     * Includes retry logic and timeout handling for better mobile compatibility.
     */
    function checkManageAccess(retryCount = 0) {
        const editButton = document.querySelector('.extrch-link-page-edit-btn');
        const maxRetries = 2;
        const timeoutMs = 3000; // Reduced timeout for faster response
        
        console.log('[Edit Button Debug] Starting access check for band_id:', band_id, 'retry:', retryCount);
        
        // Hide button by default until access is confirmed by API
        if (editButton) {
            editButton.style.display = 'none';
            console.log('[Edit Button Debug] Edit button found and hidden by default');
        } else {
            console.warn('[Edit Button Debug] Edit button element not found in DOM');
            return;
        }

        const apiUrl = `${rest_url}extrachill/v1/check-band-manage-access/${band_id}`;
        console.log('[Edit Button Debug] Making request to:', apiUrl);

        // Create abort controller for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => {
            controller.abort();
            console.warn('[Edit Button Debug] Request timed out after', timeoutMs, 'ms');
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
                console.log('[Edit Button Debug] Response status:', response.status);
                console.log('[Edit Button Debug] Response ok:', response.ok);
                
                if (!response.ok) {
                    console.warn('[Edit Button Debug] Response not OK, attempting to parse JSON anyway');
                    return response.json().catch(() => ({ error: 'Failed to parse error response', status: response.status }));
                }
                return response.json();
            })
            .then(data => {
                console.log('[Edit Button Debug] Response data:', data);
                
                if (data && data.canManage) {
                    console.log('[Edit Button Debug] User can manage - showing edit button');
                    if (editButton) {
                        editButton.style.display = 'flex';
                    }
                } else {
                    console.log('[Edit Button Debug] User cannot manage - hiding edit button');
                    if (data && data.debug) {
                        console.log('[Edit Button Debug] Server debug info:', data.debug);
                    }
                    if (editButton) {
                        editButton.style.display = 'none';
                    }
                    
                    // Apply fallback logic for certain error conditions
                    if (retryCount === 0 && shouldRetryRequest(data)) {
                        console.log('[Edit Button Debug] Applying retry logic due to potential network/auth issue');
                        setTimeout(() => checkManageAccess(retryCount + 1), 1000);
                    }
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                console.error('[Edit Button Debug] Request failed:', error);
                console.log('[Edit Button Debug] Error details:', {
                    message: error.message,
                    name: error.name,
                    stack: error.stack,
                    apiUrl: apiUrl,
                    userAgent: navigator.userAgent,
                    cookiesEnabled: navigator.cookieEnabled,
                    hasDocumentCookie: !!document.cookie,
                    isAbortError: error.name === 'AbortError',
                    retryCount: retryCount
                });
                
                // Retry logic for certain types of failures
                if (retryCount < maxRetries && shouldRetryOnError(error)) {
                    console.log('[Edit Button Debug] Retrying request due to network error, attempt:', retryCount + 1);
                    setTimeout(() => checkManageAccess(retryCount + 1), 1500);
                    return;
                }
                
                // Final fallback logic when all AJAX attempts fail
                console.log('[Edit Button Debug] All AJAX attempts failed, checking fallback conditions');
                if (shouldShowButtonAsFallback()) {
                    console.log('[Edit Button Debug] Fallback conditions met - showing edit button');
                    if (editButton) {
                        editButton.style.display = 'flex';
                        // Add visual indicator that this is fallback mode
                        editButton.title = 'Edit (fallback mode - may require re-login)';
                    }
                } else {
                    console.log('[Edit Button Debug] Fallback conditions not met - hiding edit button');
                    // Ensure button is hidden on final error
                    if (editButton) {
                        editButton.style.display = 'none';
                    }
                }
            });
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
        console.log('[Edit Button Debug] Attempting cross-domain session synchronization');
        
        // Create hidden iframe to trigger session check on community.extrachill.com
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.style.width = '1px';
        iframe.style.height = '1px';
        
        // URL that will trigger session token auto-login on community domain
        const syncUrl = 'https://community.extrachill.com/wp-admin/admin-ajax.php?action=sync_session_token&band_id=' + band_id;
        
        iframe.onload = function() {
            console.log('[Edit Button Debug] Cross-domain session sync iframe loaded');
            // Give it a moment then retry the access check
            setTimeout(() => {
                console.log('[Edit Button Debug] Retrying access check after session sync');
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
            console.warn('[Edit Button Debug] Cross-domain session sync iframe failed to load');
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
            console.log('[Edit Button Debug] Debug mode detected');
            return true;
        }
        
        // Check if user came from management interface - trigger session sync
        if (referrer && (referrer.includes('/manage-link-page') || referrer.includes('/manage-band-profile'))) {
            console.log('[Edit Button Debug] User came from management interface - attempting session sync');
            attemptCrossDomainSessionSync();
            return false; // Don't show button immediately, wait for sync
        }
        
        // Check if URL has edit parameter (could be set by management interface)
        if (url.searchParams.has('edit') || url.searchParams.has('manage')) {
            console.log('[Edit Button Debug] Edit/manage parameter found in URL');
            attemptCrossDomainSessionSync();
            return false; // Don't show button immediately, wait for sync
        }
        
        // Check localStorage for recent management activity
        const recentManagement = localStorage.getItem('extrch_recent_band_management');
        if (recentManagement) {
            try {
                const data = JSON.parse(recentManagement);
                const timeDiff = Date.now() - data.timestamp;
                // If managed this band within the last 10 minutes
                if (data.band_id == band_id && timeDiff < 600000) {
                    console.log('[Edit Button Debug] Recent management activity found - attempting session sync');
                    attemptCrossDomainSessionSync();
                    return false; // Don't show button immediately, wait for sync
                }
            } catch (e) {
                console.warn('[Edit Button Debug] Error parsing localStorage data:', e);
            }
        }
        
        // Check if running on localhost/development (admins might want to see button for debugging)
        if (window.location.hostname === 'localhost' || 
            window.location.hostname === '127.0.0.1' ||
            window.location.hostname.includes('dev') ||
            window.location.hostname.includes('staging')) {
            console.log('[Edit Button Debug] Development environment detected');
            return true;
        }
        
        return false;
    }

    // Run the check when the DOM is fully loaded
    document.addEventListener('DOMContentLoaded', checkManageAccess);

})(); 
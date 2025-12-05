/**
 * QR Code Modal - REST API Integration
 *
 * Generates QR codes via extrachill-api REST endpoint.
 * Displays 300px preview, offers 1000px download for print.
 */
(function() {
    'use strict';

    const qrButton = document.getElementById('bp-get-qr-code-btn');
    const qrModal = document.getElementById('bp-qr-code-modal');
    if (!qrButton || !qrModal) return;

    const qrModalClose = qrModal.querySelector('.bp-modal-close');
    const qrImageContainer = document.getElementById('bp-qr-code-modal-image-container');
    const qrImageElement = qrImageContainer ? qrImageContainer.querySelector('img') : null;
    const loadingMessage = qrImageContainer ? qrImageContainer.querySelector('.loading-message') : null;
    const actionsContainer = qrModal.querySelector('.bp-qr-code-actions');
    const downloadButton = document.getElementById('bp-download-qr-hires');

    if (!qrImageElement || !loadingMessage) return;

    const errorMessageElement = document.createElement('p');
    errorMessageElement.className = 'notice notice-error';
    errorMessageElement.style.display = 'none';
    qrImageContainer.appendChild(errorMessageElement);

    let currentUrl = '';

    function getPublicUrl() {
        const publicUrlElement = document.querySelector('.bp-link-page-url-text');
        return publicUrlElement ? publicUrlElement.href : '';
    }

    function getRestEndpoint() {
        if (typeof extraChillArtistPlatform !== 'undefined' && extraChillArtistPlatform.restUrl) {
            return extraChillArtistPlatform.restUrl + '/tools/qr-code';
        }
        return '/wp-json/extrachill/v1/tools/qr-code';
    }

    function getArtistSlug() {
        if (typeof extraChillArtistPlatform !== 'undefined' && extraChillArtistPlatform.artistSlug) {
            return extraChillArtistPlatform.artistSlug;
        }
        return 'link-page';
    }

    async function fetchQrCode(url, size) {
        const response = await fetch(getRestEndpoint(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url, size })
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.message || 'Failed to generate QR code');
        }

        return response.json();
    }

    function showModal() {
        qrModal.style.display = 'block';
    }

    function hideModal() {
        qrModal.style.display = 'none';
        qrImageElement.style.display = 'none';
        loadingMessage.style.display = 'block';
        if (actionsContainer) actionsContainer.style.display = 'none';
        errorMessageElement.style.display = 'none';
        errorMessageElement.textContent = '';
    }

    function showError(message) {
        loadingMessage.style.display = 'none';
        errorMessageElement.textContent = message;
        errorMessageElement.style.display = 'block';
    }

    async function loadPreviewQrCode() {
        currentUrl = getPublicUrl();

        if (!currentUrl) {
            showError('Public URL for QR code is not available.');
            return;
        }

        loadingMessage.style.display = 'block';
        qrImageElement.style.display = 'none';
        if (actionsContainer) actionsContainer.style.display = 'none';
        errorMessageElement.style.display = 'none';

        try {
            const data = await fetchQrCode(currentUrl, 300);

            if (data.success && data.image_url) {
                loadingMessage.style.display = 'none';
                qrImageElement.src = data.image_url;
                qrImageElement.style.display = 'block';
                if (actionsContainer) actionsContainer.style.display = 'block';
            } else {
                showError(data.message || 'Failed to generate QR code.');
            }
        } catch (error) {
            showError(error.message || 'Request failed.');
        }
    }

    async function downloadHighResQrCode() {
        if (!currentUrl || !downloadButton) return;

        downloadButton.disabled = true;
        const originalText = downloadButton.innerHTML;
        downloadButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';

        try {
            const data = await fetchQrCode(currentUrl, 1000);

            if (data.success && data.image_url) {
                const link = document.createElement('a');
                link.href = data.image_url;
                link.download = getArtistSlug() + '-qr-code.png';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                alert('Failed to generate high-resolution QR code.');
            }
        } catch (error) {
            alert('Error: ' + (error.message || 'Request failed.'));
        } finally {
            downloadButton.disabled = false;
            downloadButton.innerHTML = originalText;
        }
    }

    // Event listeners
    qrButton.addEventListener('click', function() {
        showModal();
        loadPreviewQrCode();
    });

    if (qrModalClose) {
        qrModalClose.addEventListener('click', hideModal);
    }

    qrModal.addEventListener('click', function(event) {
        if (event.target === qrModal) {
            hideModal();
        }
    });

    if (downloadButton) {
        downloadButton.addEventListener('click', downloadHighResQrCode);
    }
})();

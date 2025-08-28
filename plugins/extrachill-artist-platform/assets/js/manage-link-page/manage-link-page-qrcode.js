// --- QR Code Modal Functionality ---
(function() {
    const qrButton = document.getElementById('bp-get-qr-code-btn');
    const qrModal = document.getElementById('bp-qr-code-modal');
    const qrModalClose = qrModal ? qrModal.querySelector('.bp-modal-close') : null;
    const qrImageContainer = document.getElementById('bp-qr-code-modal-image-container');
    const qrImageElement = qrImageContainer ? qrImageContainer.querySelector('img') : null;
    const loadingMessage = qrImageContainer ? qrImageContainer.querySelector('.loading-message') : null;
    const errorMessageElement = document.createElement('p'); // For displaying errors
    errorMessageElement.className = 'bp-notice bp-notice-error';
    errorMessageElement.style.display = 'none';
    if (qrImageContainer) {
        qrImageContainer.appendChild(errorMessageElement);
    }

    function showModal() {
        if (qrModal) qrModal.style.display = 'block';
    }

    function hideModal() {
        if (qrModal) qrModal.style.display = 'none';
        if (qrImageElement) qrImageElement.style.display = 'none'; // Hide image
        if (loadingMessage) loadingMessage.style.display = 'block'; // Reset loading message
        errorMessageElement.style.display = 'none'; // Hide error message
        errorMessageElement.textContent = '';
    }

    if (qrButton && qrModal && qrModalClose && qrImageElement && loadingMessage) {
        qrButton.addEventListener('click', function() {
            showModal();
            loadingMessage.style.display = 'block';
            qrImageElement.style.display = 'none';
            errorMessageElement.style.display = 'none';

            const ajaxData = new FormData();
            ajaxData.append('action', 'extrch_generate_qrcode');
            ajaxData.append('security', window.extrchLinkPagePreviewAJAX.nonce); 
            ajaxData.append('link_page_id', window.extrchLinkPagePreviewAJAX.link_page_id);

            // Get the public URL from the element on the page
            const publicUrlElement = document.querySelector('.bp-link-page-url-text');
            const publicUrl = publicUrlElement ? publicUrlElement.href : '';
             if (publicUrl) {
                ajaxData.append('url', publicUrl);
             } else {
                 loadingMessage.style.display = 'none';
                 errorMessageElement.textContent = 'Public URL for QR code is not available.';
                 errorMessageElement.style.display = 'block';
                 console.error('Public URL for QR code is not available.');
                 return;
             }

            fetch(window.extrchLinkPagePreviewAJAX.ajax_url, {
                method: 'POST',
                body: ajaxData
            })
            .then(response => response.json())
            .then(data => {
                loadingMessage.style.display = 'none';
                if (data.success && data.data.image_url) {
                    qrImageElement.src = data.data.image_url;
                    qrImageElement.style.display = 'block';
                } else {
                    errorMessageElement.textContent = data.data && data.data.message ? data.data.message : 'An unknown error occurred.';
                    errorMessageElement.style.display = 'block';
                }
            })
            .catch(error => {
                loadingMessage.style.display = 'none';
                errorMessageElement.textContent = 'Request failed: ' + error;
                errorMessageElement.style.display = 'block';
                console.error('Error fetching QR code:', error);
            });
        });

        qrModalClose.addEventListener('click', hideModal);

        // Close modal if clicked outside the modal content
        qrModal.addEventListener('click', function(event) {
            if (event.target === qrModal) {
                hideModal();
            }
        });
    } else {
        if (!qrButton) console.error('QR Code button not found.');
        if (!qrModal) console.error('QR Code modal not found.');
        if (!qrModalClose) console.error('QR Code modal close button not found.');
        if (!qrImageElement) console.error('QR Code modal image element not found.');
        if (!loadingMessage) console.error('QR Code modal loading message not found.');
    }
})(); 
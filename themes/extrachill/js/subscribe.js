document.addEventListener("DOMContentLoaded", function() {
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    document.body.appendChild(overlay);

    function createPopup(headerText, inputPlaceholder, buttonText, popupClass, replaceExisting = false) {
        let popup;
        let closeButtonText = localStorage.getItem('subscribed') === 'true' ? "Close" : "Sorry, I'm Not That Chill";

        if (replaceExisting) {
            popup = document.querySelector('.' + popupClass);
            if (popup) {
                popup.innerHTML = `
                    <p>${headerText}</p>
                    <button class="close-popup">${closeButtonText}</button>
                    <button class="follow-instagram">Follow Instagram</button>`;
            }
        } else {
            popup = document.createElement('div');
            popup.className = popupClass;
            let linkHTML = ''; // Removed community popup links
            
            if (popupClass === 'subscribe-popup') {
                linkHTML = `<p style="text-align:center;"><a href="/newsletters" target="_blank">See past Newsletters</a></p>`;
            }

            popup.innerHTML = `
                <p>${headerText}</p>
                <form>
                    <input type="text" name="email" placeholder="${inputPlaceholder}" required>
                    <button class="subscribe-button" type="submit">${buttonText}</button>
                </form>
                ${linkHTML}
                <span class="popup-buttons">
                <button class="follow-instagram">Follow Instagram</button>
                <button class="close-popup">${closeButtonText}</button>
                </span>`;
            document.body.appendChild(popup);
            overlay.style.display = 'block';
        }
    }

    function handleScrollAndPopup() {
        const lastSubscribedTime = localStorage.getItem('lastSubscribedTime');
        const currentTime = new Date().getTime();
        const subscribed = localStorage.getItem('subscribed');
        let popupTriggered = false;

        // Check for the first trigger div, fallback to the second if not found
        const primaryTriggerDiv = document.querySelector('.community-cta'); // First preferred div
        const secondaryTriggerDiv = document.querySelector('#extra-footer'); // Fallback div
        
        const targetDiv = primaryTriggerDiv || secondaryTriggerDiv; // Use primary if available, else fallback to secondary
        if (!targetDiv) return; // If neither div is found, do nothing

        const observer = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !popupTriggered) {
                    popupTriggered = true; // Ensure the popup only triggers once
                    observer.unobserve(entry.target); // Stop observing once triggered

                    // Check subscription status and decide which popup to show
                    if (subscribed !== 'true') {
                        createPopup('Independent music journalism with personality! Enter your email for a good time.', 'Enter your email', 'Subscribe', 'subscribe-popup');
                    }
                }
            });
        }, { threshold: 0.5 }); // Adjust threshold as needed

        observer.observe(targetDiv);
    }

    document.addEventListener('pointerup', function(event) {
        if (event.pointerType === 'mouse' && event.button !== 0) return;
    
        if (event.target.classList.contains('close-popup')) {
            event.stopPropagation();
            const popup = event.target.closest('.subscribe-popup');
            if (popup) {
                popup.remove();
                overlay.style.display = 'none';
            }
            sessionStorage.removeItem('popupShown');
        }
        
        if (event.target.classList.contains('follow-instagram')) {
            window.open('https://www.instagram.com/extrachill', '_blank');
        }
    }, true);

    document.body.addEventListener('submit', function(event) {
        if (event.target.closest('.subscribe-popup form')) {
            event.preventDefault();
            const email = event.target.querySelector('input[name="email"]').value;
    
            fetch(newsletter_vars.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=submit_newsletter_popup_form&email=${encodeURIComponent(email)}&nonce=${newsletter_vars.nonce}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    createPopup('Thank you for subscribing! Stay tuned for updates.', '', '', 'subscribe-popup', true);
                } else {
                    alert('Error: ' + data.data);
                }
            })
            .catch(error => console.error('Error:', error));
    
            localStorage.setItem('subscribed', 'true');
            localStorage.setItem('lastSubscribedTime', new Date().getTime());
        }
    });

    handleScrollAndPopup();
});

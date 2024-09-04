document.addEventListener("DOMContentLoaded", function() {
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    document.body.appendChild(overlay);

    function isLoggedIn() {
        return document.body.classList.contains('logged-in');
    }

    function showLoginOrRegisterButtons() {
        if (!isLoggedIn()) {
            const popup = document.createElement('div');
            popup.className = 'login-popup';
            popup.innerHTML = `
                <p>Thanks for visiting! Log in or register to join the discussion.</p>
                <div class="login-register-buttons">
                <button class="login-button" onclick="window.location.href='/login'">Login</button>
                <button class="register-button" onclick="window.location.href='/register'">Register</button>
                </div>
                <button class="close-popup" onclick="closePopupAndReset()">Sorry, I'm Not That Chill</button>`;
            document.body.appendChild(popup);
            overlay.style.display = 'block';
        }
    }

    window.closePopupAndReset = function() {
        const popup = document.querySelector(".login-popup");
        if (popup) {
            popup.remove();
        }
        overlay.style.display = 'none';
    };

    window.addEventListener('scroll', handleScroll);

    function handleScroll() {
        const scrollTop = document.documentElement.scrollTop || document.body.scrollTop;
        const scrollHeight = document.documentElement.scrollHeight || document.body.scrollHeight;
        const clientHeight = document.documentElement.clientHeight || document.body.clientHeight;
        const scrolledToBottom = Math.ceil(scrollTop + clientHeight) >= scrollHeight;

        if (scrolledToBottom) {
            showLoginOrRegisterButtons();
            window.removeEventListener('scroll', handleScroll);
        }
    }
});

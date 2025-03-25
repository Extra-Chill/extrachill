<?php
/**
 * Plugin Name: Local Session Token Manager
 * Description: Adds a dashboard widget to manage the ecc_user_session_token for local testing.
 */

add_action('wp_dashboard_setup', 'add_session_token_dashboard_widgets');

function add_session_token_dashboard_widgets() {
    wp_add_dashboard_widget(
        'session_token_manager_widget',
        'Session Token Manager',
        'session_token_dashboard_widget_content'
    );
}

function session_token_dashboard_widget_content() {
    ?>
    <div class="session-token-manager">
        <?php if (isset($_COOKIE['ecc_user_session_token'])) : ?>
            <p>Session token is currently set.</p>
            <button id="unset-session-token" class="button button-secondary">Unset Session Token</button>
        <?php else : ?>
            <p>Session token is currently NOT set.</p>
            <button id="set-session-token" class="button button-primary">Set Session Token</button>
        <?php endif; ?>
        <div id="session-token-message" style="margin-top:10px;"></div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const setMessage = (message, isError = false) => {
            const messageDiv = document.getElementById('session-token-message');
            messageDiv.textContent = message;
            messageDiv.style.color = isError ? 'red' : 'green';
        };

        const setSessionButton = document.getElementById('set-session-token');
        if (setSessionButton) {
            setSessionButton.addEventListener('click', () => {
                document.cookie = "ecc_user_session_token=local_test_token; path=/";
                setMessage('Session token has been set. Reload the page.', false);
                setTimeout(() => { location.reload(); }, 1000); // Reload after message
            });
        }

        const unsetSessionButton = document.getElementById('unset-session-token');
        if (unsetSessionButton) {
            unsetSessionButton.addEventListener('click', () => {
                document.cookie = "ecc_user_session_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT";
                setMessage('Session token has been unset. Reload the page.', false);
                setTimeout(() => { location.reload(); }, 1000); // Reload after message
            });
        }
    });
    </script>
    <?php
}
<?php

// serves the login form for community.extrachill.com on extrachill.com and registers an endpoint to do so. lives on community.extrachill.com


add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/serve_login_form', array(
        'methods' => 'GET', // GET since we're just retrieving the form
        'callback' => 'serve_login_form',
        'permission_callback' => '__return_true',
    ));
});

function serve_login_form() {
    // Set Content-Type header to ensure response is treated as HTML
    header('Content-Type: text/html; charset=utf-8');

    ob_start();
    ?>
    <div id="community-login-container">
        <h3 class="comments-title">Community Login</h3>
        <p>You must be a Community member to comment on Extra Chill. Not a member? <a href="https://community.extrachill.com/register">Sign up here</a></p>
        <form id="ecc_ajax_login_form" action="https://community.extrachill.com/wp-json/extrachill/v1/handle_external_login" method="post">
            <p class="login-username">
                <label for="username"><b>Username or Email Address</b></label>
                <input type="text" name="username" id="username" autocomplete="username" class="input" value="" size="20" />
            </p>
            <p class="login-password">
                <label for="password"><b>Password</b></label>
                <input type="password" name="password" id="password" autocomplete="current-password" spellcheck="false" class="input" value="" size="20" />
            </p>
            <p class="login-remember">
                <label><input name="rememberme" type="checkbox" id="rememberme" value="forever" /> Remember Me</label>
            </p>
            <p class="login-submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary" value="Log In" />
                <input type="hidden" name="action" value="ecc_ajax_login">
            </p>
            <p class="login-error" style="display:none;"></p>
        </form>
    </div>
    <?php
    $htmlContent = ob_get_clean();
    echo $htmlContent;
    // Ensure no further processing or output occurs after this point
    exit;
}

<?php
/**
 * Template Name: Login/Register Page Template
 *
 * A custom page template for the consolidated Login and Registration page.
 *
 * @package extra-chill-community
 */

get_header();
?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">

        <?php
        while ( have_posts() ) : the_post();

            get_template_part( 'content', 'page' );

            // Add HTML for the join flow modal (initially hidden)
            ?>
            <div id="join-flow-modal-overlay" class="join-flow-modal-overlay"></div>
            <div id="join-flow-modal-content" class="join-flow-modal-content">
                <h2>Welcome to the Join Flow!</h2>
                <p>Do you already have an Extra Chill Community account?</p>
                <span class="join-flow-buttons">
                <button id="join-flow-existing-account">Yes, I have an account</button>
                <button id="join-flow-new-account">No, I need to create an account</button>
                </span>
            </div>

            <?php
            // Start of Tabbed Interface
            ?>
            <div class="shared-tabs-component">
                <div class="shared-tabs-buttons-container">
                    <!-- Login Tab Item -->
                    <div class="shared-tab-item">
                        <button type="button" class="shared-tab-button active" data-tab="tab-login">
                            Login
                            <span class="shared-tab-arrow open"></span>
                        </button>
                        <div id="tab-login" class="shared-tab-pane active">
                            <?php
                                // Include the login form file
                                require_once get_stylesheet_directory() . '/login/login.php';
                                // Call the function that displays the login form if it's not automatically displayed by the include
                                if (function_exists('extrachill_login_form')) {
                                     echo extrachill_login_form();
                                }
                            ?>
                        </div>
                    </div>

                    <!-- Register Tab Item -->
                    <div class="shared-tab-item">
                         <button type="button" class="shared-tab-button" data-tab="tab-register">
                             Register
                             <span class="shared-tab-arrow"></span>
                         </button>
                         <div id="tab-register" class="shared-tab-pane">
                              <?php
                                // Include the registration form file
                                 require_once get_stylesheet_directory() . '/login/register.php';
                                 // Call the function that displays the registration form if it's not automatically displayed by the include
                                 if (function_exists('extrachill_registration_form_shortcode')) {
                                      echo extrachill_registration_form_shortcode();
                                 }
                              ?>
                         </div>
                    </div>
                </div> <!-- End shared-tabs-buttons-container -->

                <!-- Desktop Tab Content Area (initially hidden) -->
                <div class="shared-desktop-tab-content-area" style="display: none;"></div>

            </div>
            <?php
            // End of Tabbed Interface

            // If comments are open or we have at least one comment, load up the comment template.
            if ( comments_open() || get_comments_number() ) :
                comments_template();
            endif;

        endwhile; // End of the loop.
        ?>

    </main><!-- #main -->
</div><!-- #primary -->

<?php
get_footer(); 
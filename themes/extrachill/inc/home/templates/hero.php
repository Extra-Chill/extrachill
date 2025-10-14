<?php
/**
 * Homepage Hero Section
 *
 * Displays personalized welcome message for logged-in users.
 *
 * @package ExtraChill
 * @since 69.58
 */

$username = '';
if ( is_user_logged_in() ) {
    $user = wp_get_current_user();
    $username = $user->user_nicename;
}
?>
<div class="full-width-breakout">
<section id="hero-section">
    <h2>
        <?php
        if ( $username ) {
            printf(
                esc_html__( 'Welcome back, %s 🥶', 'extrachill' ),
                esc_html( $username )
            );
        } else {
            esc_html_e( 'Welcome to the Online Music Scene 🥶', 'extrachill' );
        }
        ?>
    </h2>

    <h3>
        <?php
        echo $username
            ? esc_html__( 'Thanks for being part of the scene', 'extrachill' )
            : esc_html__( 'A melting pot for independent music', 'extrachill' );
        ?>
    </h3>

    <div class="hero-buttons-container">
        <a href="<?php echo esc_url( $username ? 'https://community.extrachill.com' : 'https://community.extrachill.com/register' ); ?>"
           class="button-1 button-medium">
            <?php echo $username
                ? esc_html__( 'Visit Community', 'extrachill' )
                : esc_html__( 'Join the Community', 'extrachill' ); ?>
        </a>

        <!-- Newsletter archive link requires ExtraChill Newsletter Plugin -->
        <a href="<?php echo esc_url( home_url( '/newsletters/' ) ); ?>"
           class="button-3 button-medium">
            <?php esc_html_e( 'Newsletter', 'extrachill' ); ?>
        </a>
    </div>
</section>
</div><!-- .full-width-breakout --> 
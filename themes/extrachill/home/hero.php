<?php
// home/hero.php - Homepage Hero Section

$username = '';
global $header_user_details; // Access global user details
if ( is_array( $header_user_details ) && ! empty( $header_user_details['username'] ) ) {
    $username = sanitize_text_field( $header_user_details['username'] );
}
?>
<section id="hero-section">
    <h2>
        <?php
        if ( $username ) {
            printf(
                esc_html__( 'Welcome back, %s 🥶', 'colormag' ),
                esc_html( $username )
            );
        } else {
            esc_html_e( 'Welcome to the Online Music Scene 🥶', 'colormag' );
        }
        ?>
    </h2>

    <h3>
        <?php
        echo $username
            ? esc_html__( 'Thanks for being part of the scene', 'colormag' )
            : esc_html__( 'A melting pot for independent music', 'colormag' );
        ?>
    </h3>

    <div class="hero-buttons-container">
        <a href="<?php echo esc_url( $username ? 'https://community.extrachill.com' : 'https://community.extrachill.com/register' ); ?>"
           class="hero-button community-button">
            <?php echo $username
                ? esc_html__( 'Visit Community', 'colormag' )
                : esc_html__( 'Join the Community', 'colormag' ); ?>
        </a>

        <a href="<?php echo esc_url( home_url( '/newsletters/' ) ); ?>"
           class="hero-button newsletter-button">
            <?php esc_html_e( 'Newsletter', 'colormag' ); ?>
        </a>
    </div>
</section> 
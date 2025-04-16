<?php
get_header();

$current_user = wp_get_current_user();
$is_logged_in = is_user_logged_in();
$page_title = ( get_query_var('paged', 1 ) > 1 ) 
    ? sprintf( __( 'The Latest – Page %d', 'colormag' ), get_query_var('paged') ) 
    : __( 'The Latest', 'colormag' );
?>
<div id="mediavine-settings" data-blocklist-all="1"></div>
<section id="hero-section">
    <?php
    $username = '';
    global $header_user_details; // Access global user details
    if ( is_array( $header_user_details ) && ! empty( $header_user_details['username'] ) ) {
        $username = sanitize_text_field( $header_user_details['username'] );
    }
    ?>

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



<section id="primary" class="content-area">
    <header class="front-page-top-section">
        <h2><?php echo esc_html( $page_title ); ?></h2>
        <a href="<?php echo esc_url( home_url( '/all/' ) ); ?>" class="view-all-button">
            <?php esc_html_e( 'Explore', 'colormag' ); ?>
        </a>
    </header>

    <main id="main" class="site-main archive" role="main">
        <?php if ( have_posts() ) : ?>
            <?php while ( have_posts() ) : the_post(); ?>
                <?php get_template_part( 'content' ); ?>
            <?php endwhile; ?>
            <?php get_template_part( 'navigation', 'none' ); ?>
        <?php else : ?>
            <?php get_template_part( 'no-results', 'none' ); ?>
        <?php endif; ?>
    </main>
</section>

<?php
get_sidebar();
get_footer();
?>

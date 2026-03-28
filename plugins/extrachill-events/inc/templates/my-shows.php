<?php
/**
 * My Shows — Concert History Page Template
 *
 * Server-rendered initial state with JS hydration for filtering and pagination.
 * Requires authenticated user (redirect handled in my-shows.php).
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$user_id = get_current_user_id();
$year    = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter.

$stats_args = $year ? array( 'year' => $year ) : array();
$stats      = function_exists( 'ec_users_get_user_concert_stats' )
	? ec_users_get_user_concert_stats( $user_id, $stats_args )
	: array();

$upcoming = function_exists( 'ec_users_get_user_events' )
	? ec_users_get_user_events( $user_id, array_merge( array( 'period' => 'upcoming', 'per_page' => 50 ), $stats_args ) )
	: array( 'shows' => array(), 'total' => 0 );

$past = function_exists( 'ec_users_get_user_events' )
	? ec_users_get_user_events( $user_id, array_merge( array( 'period' => 'past', 'per_page' => 20, 'page' => 1 ), $stats_args ) )
	: array( 'shows' => array(), 'total' => 0, 'pages' => 0 );

$total_shows    = $stats['total_shows'] ?? 0;
$unique_venues  = $stats['unique_venues'] ?? 0;
$unique_artists = $stats['unique_artists'] ?? 0;
$unique_cities  = $stats['unique_cities'] ?? 0;
$top_artists    = $stats['top_artists'] ?? array();
$top_venues     = $stats['top_venues'] ?? array();
$top_cities     = $stats['top_cities'] ?? array();
$shows_by_year  = $stats['shows_by_year'] ?? array();

extrachill_breadcrumbs();
?>

<article class="my-shows-page">
	<div class="page-content">
		<header class="my-shows-header">
			<div class="my-shows-header__title-row">
				<h1 class="page-title"><?php esc_html_e( 'My Shows', 'extrachill-events' ); ?></h1>
				<?php if ( ! empty( $shows_by_year ) ) : ?>
					<form class="my-shows-year-filter" method="get" action="<?php echo esc_url( home_url( '/my-shows/' ) ); ?>">
						<select name="year" onchange="this.form.submit()">
							<option value=""><?php esc_html_e( 'All Time', 'extrachill-events' ); ?></option>
							<?php foreach ( $shows_by_year as $yr => $count ) : ?>
								<option value="<?php echo esc_attr( $yr ); ?>" <?php selected( $year, $yr ); ?>>
									<?php echo esc_html( $yr ); ?> (<?php echo esc_html( $count ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</form>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( 0 === $total_shows && ! $year ) : ?>
			<!-- Empty state -->
			<div class="my-shows-empty">
				<p class="my-shows-empty__heading"><?php esc_html_e( 'Start Tracking Your Shows!', 'extrachill-events' ); ?></p>
				<p class="my-shows-empty__text">
					<?php esc_html_e( 'Mark events as "Going" to build your personal concert history.', 'extrachill-events' ); ?>
				</p>
				<div class="my-shows-empty__actions">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="button-2 button-large">
						<?php esc_html_e( 'Browse Tonight\'s Shows', 'extrachill-events' ); ?>
					</a>
				</div>
			</div>
		<?php else : ?>

			<!-- Stats bar -->
			<div class="my-shows-stats">
				<div class="my-shows-stats__item">
					<span class="my-shows-stats__number"><?php echo esc_html( $total_shows ); ?></span>
					<span class="my-shows-stats__label"><?php echo esc_html( _n( 'Show', 'Shows', $total_shows, 'extrachill-events' ) ); ?></span>
				</div>
				<div class="my-shows-stats__item">
					<span class="my-shows-stats__number"><?php echo esc_html( $unique_venues ); ?></span>
					<span class="my-shows-stats__label"><?php echo esc_html( _n( 'Venue', 'Venues', $unique_venues, 'extrachill-events' ) ); ?></span>
				</div>
				<div class="my-shows-stats__item">
					<span class="my-shows-stats__number"><?php echo esc_html( $unique_artists ); ?></span>
					<span class="my-shows-stats__label"><?php echo esc_html( _n( 'Artist', 'Artists', $unique_artists, 'extrachill-events' ) ); ?></span>
				</div>
				<div class="my-shows-stats__item">
					<span class="my-shows-stats__number"><?php echo esc_html( $unique_cities ); ?></span>
					<span class="my-shows-stats__label"><?php echo esc_html( _n( 'City', 'Cities', $unique_cities, 'extrachill-events' ) ); ?></span>
				</div>
			</div>

			<!-- Upcoming shows -->
			<?php if ( ! empty( $upcoming['shows'] ) ) : ?>
				<section class="my-shows-section">
					<h2 class="my-shows-section__title">
						<?php
						printf(
							/* translators: %d: number of upcoming shows */
							esc_html__( 'Upcoming (%d)', 'extrachill-events' ),
							$upcoming['total']
						);
						?>
					</h2>
					<div class="my-shows-list">
						<?php foreach ( $upcoming['shows'] as $show ) : ?>
							<?php ec_events_render_show_card( $show ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<!-- Past shows -->
			<?php if ( ! empty( $past['shows'] ) ) : ?>
				<section class="my-shows-section" id="my-shows-past">
					<h2 class="my-shows-section__title">
						<?php
						printf(
							/* translators: %d: number of past shows */
							esc_html__( 'Past (%d)', 'extrachill-events' ),
							$past['total']
						);
						?>
					</h2>
					<div class="my-shows-list" id="my-shows-past-list">
						<?php foreach ( $past['shows'] as $show ) : ?>
							<?php ec_events_render_show_card( $show ); ?>
						<?php endforeach; ?>
					</div>
					<?php if ( $past['pages'] > 1 ) : ?>
						<div class="my-shows-load-more" id="my-shows-load-more">
							<button class="button-2 button-large"
									data-page="1"
									data-pages="<?php echo esc_attr( $past['pages'] ); ?>"
									data-user-id="<?php echo esc_attr( $user_id ); ?>"
									<?php if ( $year ) : ?>data-year="<?php echo esc_attr( $year ); ?>"<?php endif; ?>
									type="button">
								<?php esc_html_e( 'Load More', 'extrachill-events' ); ?>
							</button>
						</div>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<!-- Leaderboards -->
			<?php if ( ! empty( $top_artists ) || ! empty( $top_venues ) || ! empty( $top_cities ) ) : ?>
				<div class="my-shows-leaderboards">
					<?php if ( ! empty( $top_artists ) ) : ?>
						<section class="my-shows-leaderboard">
							<h3 class="my-shows-leaderboard__title"><?php esc_html_e( 'Top Artists', 'extrachill-events' ); ?></h3>
							<ol class="my-shows-leaderboard__list">
								<?php foreach ( array_slice( $top_artists, 0, 5 ) as $item ) : ?>
									<li>
										<span class="my-shows-leaderboard__name"><?php echo esc_html( $item['name'] ); ?></span>
										<span class="my-shows-leaderboard__count">(<?php echo esc_html( $item['count'] ); ?>)</span>
									</li>
								<?php endforeach; ?>
							</ol>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $top_venues ) ) : ?>
						<section class="my-shows-leaderboard">
							<h3 class="my-shows-leaderboard__title"><?php esc_html_e( 'Top Venues', 'extrachill-events' ); ?></h3>
							<ol class="my-shows-leaderboard__list">
								<?php foreach ( array_slice( $top_venues, 0, 5 ) as $item ) : ?>
									<li>
										<span class="my-shows-leaderboard__name"><?php echo esc_html( $item['name'] ); ?></span>
										<span class="my-shows-leaderboard__count">(<?php echo esc_html( $item['count'] ); ?>)</span>
									</li>
								<?php endforeach; ?>
							</ol>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $top_cities ) ) : ?>
						<section class="my-shows-leaderboard">
							<h3 class="my-shows-leaderboard__title"><?php esc_html_e( 'Top Cities', 'extrachill-events' ); ?></h3>
							<ol class="my-shows-leaderboard__list">
								<?php foreach ( array_slice( $top_cities, 0, 5 ) as $item ) : ?>
									<li>
										<span class="my-shows-leaderboard__name"><?php echo esc_html( $item['name'] ); ?></span>
										<span class="my-shows-leaderboard__count">(<?php echo esc_html( $item['count'] ); ?>)</span>
									</li>
								<?php endforeach; ?>
							</ol>
						</section>
					<?php endif; ?>
				</div>
			<?php endif; ?>

		<?php endif; ?>
	</div>
</article>

<?php get_footer(); ?>

<?php
/**
 * Render a single show card in the My Shows list.
 *
 * @param array $show Show data from ec_users_get_user_events().
 */
function ec_events_render_show_card( array $show ) {
	$date_display = '';
	if ( ! empty( $show['event_date'] ) ) {
		$timestamp    = strtotime( $show['event_date'] );
		$date_display = $timestamp ? wp_date( 'M j', $timestamp ) : $show['event_date'];
	}

	$venue_city = '';
	if ( ! empty( $show['venue']['name'] ) ) {
		$venue_city .= $show['venue']['name'];
	}
	if ( ! empty( $show['city']['name'] ) ) {
		$venue_city .= ( $venue_city ? ' · ' : '' ) . $show['city']['name'];
	}

	// Primary artist (first in list).
	$artist_names = array();
	if ( ! empty( $show['artists'] ) ) {
		foreach ( $show['artists'] as $artist ) {
			$artist_names[] = $artist['name'];
		}
	}
	$artist_display = ! empty( $artist_names ) ? implode( ', ', $artist_names ) : $show['title'];

	$permalink = $show['permalink'] ?? '#';
	?>
	<a href="<?php echo esc_url( $permalink ); ?>" class="my-shows-card">
		<span class="my-shows-card__date"><?php echo esc_html( $date_display ); ?></span>
		<span class="my-shows-card__details">
			<span class="my-shows-card__artist"><?php echo esc_html( $artist_display ); ?></span>
			<?php if ( $venue_city ) : ?>
				<span class="my-shows-card__venue"><?php echo esc_html( $venue_city ); ?></span>
			<?php endif; ?>
		</span>
	</a>
	<?php
}

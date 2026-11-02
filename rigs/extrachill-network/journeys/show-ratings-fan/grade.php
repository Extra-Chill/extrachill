<?php
/** Grade private My Shows ratings using browser state and users-plugin truth. */
$fixture = get_site_option( 'ec_rig_journey_fixture_show_ratings_fan', array() );
if ( empty( $fixture['events_blog_id'] ) ) {
	throw new RuntimeException( 'Journey fixture missing; seed did not run.' );
}
$obs = array();
foreach ( (array) get_site_option( 'ec_show_ratings_observations', array() ) as $row ) {
	if ( is_array( $row ) && isset( $row['label'] ) ) {
		$obs[ $row['label'] ] = $row;
	}
}
$results = array();
$record = static function ( string $id, string $task, bool $ok, $evidence ) use ( &$results ): void {
	$results[] = array( 'id' => $id, 'task' => $task, 'outcome' => $ok ? 'pass' : 'finding', 'evidence' => $evidence );
};
$card = static function ( string $label, string $slug ) use ( $obs ): array {
	foreach ( (array) ( $obs[ $label ]['value']['cards'] ?? array() ) as $item ) {
		if ( str_contains( (string) ( $item['href'] ?? '' ), $slug ) ) {
			return $item;
		}
	}
	return array();
};
$recent_slug = 'goose-charleston-pour-house-fall';
$older_slug = 'goose-charleston-pour-house-summer';
$truth = static function ( string $label, int $event_id, int $expected ) use ( $obs ): bool {
	$server = $obs[ $label ]['server'] ?? array();
	if ( ! isset( $server['ratings'] ) || ! is_array( $server['ratings'] ) ) {
		return false;
	}
	return $expected === (int) ( $server['ratings'][ $event_id ] ?? 0 );
};
$owner = static fn( string $label ): bool => (int) ( $obs[ $label ]['viewer_id'] ?? 0 ) === (int) $fixture['fan_id']
	&& '1' === ( $obs[ $label ]['value']['owner'] ?? null );
$initial = $card( 'owner-initial', $recent_slug );
$record( 'quiet-rate-button', 'An unrated attended show offers Rate, not empty stars.', $owner( 'owner-initial' ) && 'Rate' === ( $initial['toggle'] ?? null ) && 0 === ( $initial['stars'] ?? -1 ) && $truth( 'owner-initial', (int) $fixture['recent_id'], 0 ), $obs['owner-initial'] ?? null );
$record( 'private-ratings-note', 'The owner sees Only you see your ratings.', str_contains( (string) ( $obs['owner-initial']['value']['note'] ?? '' ), 'Only you see your ratings' ), $obs['owner-initial'] ?? null );
$seeded = $card( 'owner-initial', $older_slug );
$record( 'seeded-rating-renders', 'A pre-existing 5-star rating renders as rated, not Rate (#1055).', $owner( 'owner-initial' ) && 5 === ( $seeded['on'] ?? null ) && '5 stars' === ( $seeded['selected'] ?? null ) && empty( $seeded['toggle'] ) && $truth( 'owner-initial', (int) $fixture['older_id'], 5 ), array( 'browser' => $seeded, 'checkpoint' => $obs['owner-initial'] ?? null ) );
$picker = $card( 'owner-picker', $recent_slug );
$record( 'rate-reveals-stars', 'Tapping Rate reveals five stars without saving a rating.', 5 === ( $picker['stars'] ?? null ) && empty( $picker['toggle'] ) && $truth( 'owner-picker', (int) $fixture['recent_id'], 0 ), $obs['owner-picker'] ?? null );
foreach ( array( 'owner-four' => 4, 'owner-three' => 3, 'owner-reloaded-three' => 3, 'owner-cleared' => 0, 'owner-reloaded-clear' => 0 ) as $label => $expected ) {
	$item = $card( $label, $recent_slug );
	$browser_ok = ! empty( $item ) && empty( $item['error'] ) && $expected === ( $item['on'] ?? null );
	$browser_ok = $browser_ok && ( $expected ? "{$expected} stars" === ( $item['selected'] ?? null ) : 'Rate' === ( $item['toggle'] ?? null ) );
	$record( $label, "My Shows checkpoint {$label}: browser and private store agree on {$expected} stars.", $owner( $label ) && $browser_ok && $truth( $label, (int) $fixture['recent_id'], $expected ), $obs[ $label ] ?? null );
}
foreach ( array( 'visitor' => 0, 'other' => (int) $fixture['other_id'] ) as $persona => $user_id ) {
	foreach ( array( 'history', 'event', 'artist' ) as $surface ) {
		$label = "{$persona}-{$surface}";
		$row = $obs[ $label ] ?? array();
		$value = $row['value'] ?? array();
		$ok = isset( $row['viewer_id'], $value['ratingNodes'] ) && $user_id === (int) $row['viewer_id'] && 0 === $value['ratingNodes'] && true === ( $value['hasGoose'] ?? false )
			&& $truth( $label, (int) $fixture['older_id'], 5 );
		// A populated public history prevents a blank/error page from passing privacy.
		if ( 'history' === $surface ) {
			$ok = $ok && '0' === ( $value['owner'] ?? null ) && (string) $fixture['fan_id'] === ( $value['userId'] ?? null )
				&& ! empty( $card( $label, $older_slug ) ) && ! empty( $card( $label, $recent_slug ) );
		}
		$record( $label . '-private', "{$persona} sees no rating UI or value on {$surface}.", $ok, $row );
	}
}
$summary = array(
	'schema' => 'extrachill-network/journey-result/show-ratings-fan/v1',
	'fixture' => $fixture,
	'results' => $results,
	'passed' => count( array_filter( $results, static fn( $x ) => 'pass' === $x['outcome'] ) ),
	'findings' => count( array_filter( $results, static fn( $x ) => 'finding' === $x['outcome'] ) ),
	'skipped' => 0,
	'observations' => $obs,
);
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $summary ) ) );

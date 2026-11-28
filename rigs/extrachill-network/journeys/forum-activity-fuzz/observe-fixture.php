<?php
/**
 * Journey fixture mu-plugin: forum-activity-fuzz.
 *
 * Evidence sinks for the exploratory forum journey. Browser steps POST
 * either a "reached" marker (all of a task's interactions completed) or a
 * JSON page observation (layout overflow, broken images, error notices, PHP
 * noise, editor shape). grade.php prints everything it collected.
 *
 * @package ExtraChillNetwork
 */

add_action(
	'init',
	static function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sandbox-only evidence sink.
		if ( isset( $_GET['forum_fuzz_reached'] ) ) {
			$id              = sanitize_key( wp_unslash( $_GET['forum_fuzz_reached'] ) );
			$log             = (array) get_site_option( 'ec_rig_forum_fuzz_reached', array() );
			$log[ $id ]      = time();
			update_site_option( 'ec_rig_forum_fuzz_reached', $log );
			status_header( 204 );
			exit;
		}
		if ( isset( $_GET['forum_fuzz_observe'] ) ) {
			$id   = sanitize_text_field( wp_unslash( $_GET['forum_fuzz_observe'] ) );
			$body = json_decode( (string) file_get_contents( 'php://input' ), true );
			$log  = (array) get_site_option( 'ec_rig_forum_fuzz_observations', array() );
			$log[ $id ] = is_array( $body ) ? $body : array( 'unparseable' => true );
			update_site_option( 'ec_rig_forum_fuzz_observations', $log );
			status_header( 204 );
			exit;
		}
		// phpcs:enable
	},
	0
);

/**
 * Create the journey's forum content inside a community-site request, where
 * bbPress and the community plugin are loaded (their reply hooks, such as
 * mention notifications and points, run for real). Runs once.
 */
function forum_fuzz_seed_content() {
	if ( ! function_exists( 'bbp_insert_topic' ) || ! get_site_option( 'ec_rig_forum_fuzz_seed_pending' ) ) {
		return;
	}
	if ( 'community.extrachill.com' !== get_site()->domain ) {
		return;
	}
	delete_site_option( 'ec_rig_forum_fuzz_seed_pending' );

	$ff_users = array( array( 811 ), array( 812 ), array( 813 ), array( 814 ) );
	foreach ( $ff_users as $ff_user ) {
		if ( function_exists( 'bbp_set_user_role' ) ) {
			bbp_set_user_role( $ff_user[0], bbp_get_participant_role() );
		}
	}

	$ff_forum = static function ( $slug ) {
		$post = get_page_by_path( $slug, OBJECT, bbp_get_forum_post_type() );
		if ( ! $post instanceof WP_Post ) {
			throw new RuntimeException( 'Community activation forum missing: ' . $slug );
		}
		return (int) $post->ID;
	};
	$ff_live    = $ff_forum( 'live-shows-scenes' );
	$ff_music   = $ff_forum( 'music-discussion' );
	$ff_artists = $ff_forum( 'artist-corner' );

	$ff_para = static function ( ...$paragraphs ) {
		$out = '';
		foreach ( $paragraphs as $p ) {
			$out .= "<!-- wp:paragraph -->\n<p>" . $p . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
		return trim( $out );
	};

	$ff_topic = static function ( $forum_id, $author, $title, $content, $ago, $slug ) {
		$date = gmdate( 'Y-m-d H:i:s', time() - $ago );
		$id   = bbp_insert_topic(
			array(
				'post_parent'   => $forum_id,
				'post_author'   => $author,
				'post_title'    => $title,
				'post_name'     => $slug,
				'post_content'  => $content,
				'post_date'     => get_date_from_gmt( $date ),
				'post_date_gmt' => $date,
			),
			array( 'forum_id' => $forum_id )
		);
		if ( ! $id || is_wp_error( $id ) ) {
			throw new RuntimeException( 'Unable to seed topic: ' . $title );
		}
		return (int) $id;
	};
	$ff_reply = static function ( $forum_id, $topic_id, $author, $content, $ago ) {
		$date = gmdate( 'Y-m-d H:i:s', time() - $ago );
		$id   = bbp_insert_reply(
			array(
				'post_parent'   => $topic_id,
				'post_author'   => $author,
				'post_content'  => $content,
				'post_date'     => get_date_from_gmt( $date ),
				'post_date_gmt' => $date,
			),
			array(
				'forum_id' => $forum_id,
				'topic_id' => $topic_id,
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			throw new RuntimeException( 'Unable to seed reply on topic ' . $topic_id );
		}
		return (int) $id;
	};

	// Busy topic: 22 replies so it paginates at 15 per page.
	$ff_busy = $ff_topic(
		$ff_live,
		812,
		'Best small rooms for a Tuesday night show?',
		$ff_para( 'Trying to book a weeknight bill for three local bands. Which rooms actually show up on a Tuesday? Looking for 100 to 200 cap, decent sound, not a sports bar.' ),
		3 * DAY_IN_SECONDS,
		'tuesday-rooms'
	);
	$ff_voices = array( 813, 814, 812 );
	for ( $i = 1; $i <= 20; $i++ ) {
		$ff_reply(
			$ff_live,
			$ff_busy,
			$ff_voices[ $i % 3 ],
			$ff_para( 'Reply ' . $i . ': the back room on the east side has been solid lately, sound person actually cares. Load in is rough though.' ),
			3 * DAY_IN_SECONDS - $i * HOUR_IN_SECONDS
		);
	}
	$ff_old_own_reply = $ff_reply( $ff_live, $ff_busy, 811, $ff_para( 'Old take from Fuzz Fan: Tuesdays work if you pair with a trivia crowd.' ), 2 * DAY_IN_SECONDS );
	$ff_fresh_own_reply = $ff_reply( $ff_live, $ff_busy, 811, $ff_para( 'Fresh take from Fuzz Fan: I will be at the next one, count me in.' ), 60 );

	// Stress topic: long unbroken word, long URL, emoji, long reply (truncation).
	$ff_stress = $ff_topic(
		$ff_music,
		813,
		'Supercalifragilisticexpialidocious-shoegaze-dreampop-postrock-crossover recs 🎸🌊✨ (the long title edition, with a title that keeps going past where it should probably stop)',
		$ff_para(
			'Drop your favourite records in this lane. Bonus points for anything on tape.',
			'Reference: https://example.invalid/a/really/long/path/that/never/breaks/because/it/is/one/token/0123456789abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz',
			'Aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
		),
		DAY_IN_SECONDS,
		'long-title-recs'
	);
	$ff_reply(
		$ff_music,
		$ff_stress,
		812,
		$ff_para( ...array_fill( 0, 14, 'This is a long reply that should be truncated behind a Show More toggle. It keeps rambling about reverb pedals, tape hiss, and the one show where the fog machine set off the alarm.' ) ),
		20 * HOUR_IN_SECONDS
	);
	$ff_reply( $ff_music, $ff_stress, 814, $ff_para( '🎧🎧🎧 <strong>bold</strong> and <em>italic</em> and a <a href="https://example.invalid/">link</a>' ), 10 * HOUR_IN_SECONDS );

	// Member's own topic (edit target) and a quiet topic with zero replies.
	$ff_own_topic = $ff_topic( $ff_artists, 811, 'Fuzz Fan here, new to the scene', $ff_para( 'Hey all, I play bass and just moved to town. Say hi!' ), 120, 'fuzz-fan-intro' );
	$ff_quiet     = $ff_topic( $ff_artists, 814, 'Anyone recording at home on a budget?', $ff_para( 'What is the cheapest interface that does not suck?' ), 5 * HOUR_IN_SECONDS, 'budget-home-recording' );

	// A reply that mentions the member, so notifications have something in them.
	// bbp_insert_reply() skips bbp_new_reply, so fire it like a real post would
	// (mention + reply notifications, points).
	$ff_mention = $ff_reply( $ff_artists, $ff_own_topic, 812, $ff_para( 'Welcome @fuzzfan! Come out to the Tuesday show.' ), 30 );
	do_action( 'bbp_new_reply', $ff_mention, $ff_own_topic, $ff_artists, array(), 812, false, 0 );


	$fixture = (array) get_site_option( 'ec_rig_journey_fixture_forum_fuzz', array() );
	update_site_option(
		'ec_rig_journey_fixture_forum_fuzz',
		array_merge(
			$fixture,
			array(
				'busy_topic'      => $ff_busy,
				'stress_topic'    => $ff_stress,
				'own_topic'       => $ff_own_topic,
				'quiet_topic'     => $ff_quiet,
				'fresh_own_reply' => $ff_fresh_own_reply,
				'old_own_reply'   => $ff_old_own_reply,
				'permalinks'      => array( bbp_get_topic_permalink( $ff_busy ), bbp_get_topic_permalink( $ff_stress ), bbp_get_topic_permalink( $ff_own_topic ) ),
			)
		)
	);
}
// Capture JS errors from the first byte, so sinks can report them per page.
add_action(
	'wp_head',
	static function () {
		echo "<script>window.__ffErrors=[];addEventListener('error',function(e){window.__ffErrors.push(String(e.message||e.type)+' @'+(e.filename||'').split('?')[0].split('/').slice(-2).join('/')+':'+(e.lineno||''))});addEventListener('unhandledrejection',function(e){window.__ffErrors.push('rejection: '+String(e.reason&&e.reason.message||e.reason))});</script>\n";
	},
	-1000
);
// Edit-page editor diagnostics: error stacks, REST traffic, and entity/editor
// store state, posted to the observation sink 10s after load.
add_action(
	'wp_head',
	static function () {
		if ( ! preg_match( '#/(reply|t)/[^?]*edit/?#', (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ) { // phpcs:ignore
			return;
		}
		?>
<script>
(function () {
	var D = window.__ffDiag = { errors: [], rest: [] };
	addEventListener('error', function (e) { D.errors.push({ msg: String(e.message), file: (e.filename || '').split('?')[0].split('/').slice(-3).join('/'), line: e.lineno, col: e.colno, stack: e.error && String(e.error.stack).slice(0, 1500) }); });
	var of = window.fetch;
	window.fetch = function (input, init) {
		var u = String(input && input.url || input);
		var rec = /wp-json|rest_route/.test(u) ? { url: u.replace(location.origin, '').slice(0, 200), method: (init && init.method) || 'GET' } : null;
		if (rec) D.rest.push(rec);
		return of.apply(this, arguments).then(function (r) { if (rec) rec.status = r.status; return r; }, function (err) { if (rec) rec.status = 'ERR ' + err; throw err; });
	};
	setTimeout(function () {
		var out = { url: location.pathname, errors: D.errors, rest: D.rest };
		try {
			var be = window.wpBlocksEverywhere || {};
			out.postEntity = be.postEntity || null;
			var core = wp.data.select('core');
			out.entityConfig = !!(be.postEntity && core.getEntityConfig('postType', be.postEntity.type));
			out.entityRecord = !!(be.postEntity && core.getEntityRecord('postType', be.postEntity.type, be.postEntity.id));
			out.editorPostId = wp.data.select('core/editor').getCurrentPostId();
			out.typesKnown = (core.getPostTypes({ per_page: -1 }) || []).map(function (t) { return t.slug; });
		} catch (err) { out.stateErr = String(err); }
		out.iframe = !!document.querySelector('iframe[name=editor-canvas]');
		out.scripts = [].slice.call(document.scripts).map(function (s) { return s.src; }).filter(function (s) { return /blocks-everywhere|\/editor\/index|\/core-data\/|react-dom/.test(s); }).map(function (s) { return s.replace(location.origin, ''); });
		fetch('/?forum_fuzz_observe=' + encodeURIComponent('edit-diag:' + location.pathname + ':' + (document.body.className.match(/logged-in/) ? 'li' : 'lo')), { method: 'POST', body: JSON.stringify(out) });
	}, 10000);
})();
</script>
		<?php
	},
	-999
);
add_action(
	'wp_loaded',
	static function () {
		try {
			forum_fuzz_seed_content();
		} catch ( Throwable $e ) {
			update_site_option( 'ec_rig_forum_fuzz_seed_error', $e->getMessage() );
		}
	},
	0
);

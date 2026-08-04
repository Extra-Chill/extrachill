<?php

use PHPUnit\Framework\TestCase;

final class ArtistDirectSubscriberWpdb extends EcTestWpdb {
	public $prefix = 'wp_';
	public $rows   = array();

	public function get_var( $prepared ) {
		if ( str_contains( $prepared['query'], 'artist_subscribers' ) ) {
			$artist_id = (int) $prepared['args'][0];
			$email     = (string) $prepared['args'][1];

			return count(
				array_filter(
					$this->rows,
					static fn( $row ) => $artist_id === (int) $row->artist_profile_id && $email === $row->subscriber_email
				)
			);
		}

		return parent::get_var( $prepared );
	}

	public function insert( $table, $data, $format ) {
		$data['subscriber_id'] = count( $this->rows ) + 1;
		$data['user_id']       = null;
		$data['source']        = 'artist_subscribe_form';
		$this->rows[]          = (object) $data;

		return 1;
	}

	public function get_results( $prepared ) {
		$args             = is_array( $prepared['args'][0] ) ? $prepared['args'][0] : $prepared['args'];
		$artist_id        = (int) $args[0];
		$excluded_source  = (string) $args[1];
		$exported         = count( $args ) > 2 ? (int) $args[2] : null;
		$rows             = array_values(
			array_filter(
				$this->rows,
				static function ( $row ) use ( $artist_id, $excluded_source, $exported ) {
					return $artist_id === (int) $row->artist_profile_id
						&& $excluded_source !== $row->source
						&& ( null === $exported || $exported === (int) $row->exported );
				}
			)
		);

		usort( $rows, static fn( $left, $right ) => strcmp( $right->subscribed_at, $left->subscribed_at ) );
		return $rows;
	}

	public function query( $query ) {
		if ( preg_match( '/subscriber_id IN \(([^)]+)\)/', $query, $matches ) ) {
			$ids = array_map( 'intval', explode( ',', $matches[1] ) );
			foreach ( $this->rows as $row ) {
				if ( in_array( (int) $row->subscriber_id, $ids, true ) ) {
					$row->exported = 1;
				}
			}

			return count( $ids );
		}

		return parent::query( $query );
	}
}

final class ArtistDirectSubscriberTest extends TestCase {
	private $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']      = new ArtistDirectSubscriberWpdb();
		$GLOBALS['ec_test']   = array(
			'current_user_id' => 7,
			'managed_artists' => array( 7 => array( 42 ) ),
			'current_blog_id' => 4,
			'blog_stack'      => array(),
		);
		$GLOBALS['ec_test']['blogs'][4]['posts'][42] = (object) array(
			'ID'          => 42,
			'post_type'   => 'artist_profile',
			'post_status' => 'publish',
			'post_title'  => 'Futurebirds',
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_anonymous_direct_submission_storage_list_and_export_work_without_recipient_resolver(): void {
		$this->assertFalse( function_exists( 'extrachill_users_entity_subscription_recipients' ) );

		$subscribed = extrachill_artist_platform_ability_artist_subscribe(
			array(
				'id'    => 42,
				'email' => 'listener@example.com',
			)
		);

		$this->assertSame( 'Thank you for subscribing!', $subscribed['message'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows );
		$this->assertNull( $GLOBALS['wpdb']->rows[0]->user_id );
		$this->assertSame( 'artist_subscribe_form', $GLOBALS['wpdb']->rows[0]->source );

		$duplicate = extrachill_artist_platform_ability_artist_subscribe(
			array(
				'id'    => 42,
				'email' => 'listener@example.com',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'already_subscribed', $duplicate->get_error_code() );

		$list = extrachill_artist_platform_ability_artist_list_subscribers( array( 'id' => 42 ) );
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 'listener@example.com', $list['subscribers'][0]->subscriber_email );

		$export = extrachill_artist_platform_ability_artist_export_subscribers( array( 'id' => 42 ) );
		$this->assertSame( 1, $export['total'] );
		$this->assertSame( 1, $export['marked_count'] );
		$this->assertSame( 'listener@example.com', $export['subscribers'][0]['email'] );
		$this->assertSame( 1, $GLOBALS['wpdb']->rows[0]->exported );
	}

	public function test_direct_reader_filters_orders_paginates_and_excludes_historical_follow_consent(): void {
		$GLOBALS['wpdb']->rows = array(
			(object) array(
				'subscriber_id'    => 1,
				'artist_profile_id' => 42,
				'subscriber_email' => 'older@example.com',
				'username'         => '',
				'source'           => 'artist_subscribe_form',
				'subscribed_at'    => '2026-08-01 12:00:00',
				'exported'         => 0,
			),
			(object) array(
				'subscriber_id'    => 2,
				'artist_profile_id' => 42,
				'subscriber_email' => 'historical@example.com',
				'username'         => 'historical',
				'source'           => 'platform_follow_consent',
				'subscribed_at'    => '2026-08-03 12:00:00',
				'exported'         => 0,
			),
			(object) array(
				'subscriber_id'    => 3,
				'artist_profile_id' => 42,
				'subscriber_email' => 'newer@example.com',
				'username'         => '',
				'source'           => 'artist_subscribe_form',
				'subscribed_at'    => '2026-08-02 12:00:00',
				'exported'         => 0,
			),
		);

		$subscribers = extrachill_artist_get_artist_subscribers(
			42,
			array(
				'exported' => 0,
				'limit'    => 1,
				'offset'   => 1,
			)
		);

		$this->assertCount( 1, $subscribers );
		$this->assertSame( 'older@example.com', $subscribers[0]->subscriber_email );
	}

	public function test_list_handler_rejects_unauthorized_artist_before_reading(): void {
		$GLOBALS['ec_test']['managed_artists'] = array();

		$result = extrachill_artist_platform_ability_artist_list_subscribers( array( 'id' => 42 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_access_denied', $result->get_error_code() );
	}
}

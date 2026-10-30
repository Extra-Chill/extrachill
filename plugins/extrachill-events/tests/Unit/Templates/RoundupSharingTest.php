<?php
use ExtraChillEvents\Templates\EventRoundupTemplate;
use ExtraChillEvents\Templates\RoundupPhotos;
require_once dirname( __DIR__, 2 ) . '/fixtures/data-machine-template-interface.php';
require_once dirname( __DIR__, 3 ) . '/inc/Templates/EventRoundupTemplate.php';

final class RoundupSharingTest extends BookingTestCase {
	public function test_credit_from_recap_not_uploader(): void {
		$this->assertSame( array( 'photographer_name' => 'Steve Aycock', 'photographer_instagram' => 'steveaycockphoto' ), RoundupPhotos::credit( '<p>Photos by Steve Aycock (@steveaycockphoto)</p>', array(), 'Admin' ) );
		$this->assertSame( 'Tucker McManus', RoundupPhotos::credit( '<p>Photo by Tucker McManus</p>', array(), 'Admin' )['photographer_name'] );
		$this->assertSame( 'EXIF name', RoundupPhotos::credit( '', array( 'credit' => 'EXIF name' ), 'Admin' )['photographer_name'] );
	}

	public function test_tags_are_normalized_and_capped(): void {
		$template = new EventRoundupTemplate();
		$begin = new ReflectionMethod( $template, 'begin_slide' );
		$begin->setAccessible( true );
		$begin->invoke( $template, 'day' );
		$add = new ReflectionMethod( $template, 'add_tag' );
		$add->setAccessible( true );
		for ( $i = 0; $i < 25; ++$i ) {
			$add->invoke( $template, '@user' . $i, 0.1, 0.2 );
		}
		$record = new ReflectionMethod( $template, 'record_slide' );
		$record->setAccessible( true );
		EventRoundupTemplate::$last_slides = array();
		$record->invoke( $template, '/slide.png' );
		$this->assertCount( 20, EventRoundupTemplate::$last_slides[0]['user_tags'] );
		$this->assertSame( array( 'username' => 'user0', 'x' => 0.1, 'y' => 0.2 ), EventRoundupTemplate::$last_slides[0]['user_tags'][0] );
		$this->assertSame( 'room', EventRoundupTemplate::username( 'https://www.instagram.com/Room/' ) );
		$this->assertSame( '', EventRoundupTemplate::username( 'not a handle' ) );
	}

	public function test_split_preserves_every_event_and_tag_budget(): void {
		$events = array();
		for ( $i = 0; $i < 22; ++$i ) {
			$events[] = array( 'post' => (object) array( 'ID' => $i ), 'event_data' => array( 'startTime' => '18:00:00' ) );
		}
		$slides = EventRoundupTemplate::plan_slides( array( array( 'events' => $events ) ), 0, 10000, 0, 20, static fn() => 20, 30, 9 );
		$ids = array();
		foreach ( $slides as $slide ) {
			$count = 0;
			foreach ( $slide as $day ) {
				foreach ( $day['events'] as $event ) {
					$ids[] = $event['post']->ID;
					++$count;
				}
			}
			$this->assertLessThanOrEqual( 9, $count );
		}
		$this->assertSame( range( 0, 21 ), $ids );
	}
}

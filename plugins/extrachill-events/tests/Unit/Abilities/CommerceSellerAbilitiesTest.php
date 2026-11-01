<?php
/**
 * Commerce seller resolver tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO,Squiz.Commenting -- File-local WordPress doubles.

namespace {
	foreach ( array(
		'get_current_user_id'  => static fn() => $GLOBALS['cs_user'],
		'get_current_blog_id'  => static fn() => 7,
		'ec_get_blog_id'       => static fn() => 7,
		'get_term'             => static fn( $id, $tax ) => $GLOBALS['cs_terms'][ $tax ][ $id ] ?? null,
		'get_term_link'        => static fn( $term ) => 'https://events.example/' . $term->taxonomy . '/' . $term->term_id . '/',
		'esc_url_raw'          => static fn( $url ) => $url,
		'sanitize_text_field'  => static fn( $value ) => trim( (string) $value ),
		'absint'               => static fn( $value ) => abs( (int) $value ),
		'switch_to_blog'       => static fn() => true,
		'restore_current_blog' => static fn() => true,
	) as $name => $impl ) {
		if ( ! function_exists( $name ) ) {
			$GLOBALS['cs_impl'][ $name ] = $impl;
			eval( "function {$name}( ...\$a ) { return ( \$GLOBALS['cs_impl']['{$name}'] )( ...\$a ); }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Define only missing doubles.
		}
	}
}

namespace ExtraChillEvents\Core {
	if ( ! class_exists( VenueAuthorization::class, false ) ) {
		class VenueAuthorization {
			public const ACTION_MANAGE_FINANCES = 'manage_finances';
			public function can( int $user_id, int $venue_term_id, string $action ): bool {
				return in_array( array( $user_id, $venue_term_id, $action ), $GLOBALS['cs_venue_grants'], true );
			}
		}
	}
	if ( ! class_exists( PromoterAuthorityRepository::class, false ) ) {
		class PromoterAuthorityRepository {
			public const STATUS_ACTIVE = 'active';
			public function get_organization( int $id ) {
				return $GLOBALS['cs_orgs'][ $id ] ?? null;
			}
			public function get_active_membership( int $id, int $user_id ) {
				return $GLOBALS['cs_members'][ $id ][ $user_id ] ?? null;
			}
		}
	}
	if ( ! class_exists( PromoterAuthoritySchema::class, false ) ) {
		class PromoterAuthoritySchema {
			public static function is_ready(): bool {
				return true;
			}
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {
	use ExtraChillEvents\Abilities\CommerceSellerAbilities;
	use ExtraChillEvents\Core\PromoterAuthorityRepository;
	use ExtraChillEvents\Core\VenueAuthorization;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/CommerceSellerAbilities.php';

	final class CommerceSellerAbilitiesTest extends TestCase {
		private CommerceSellerAbilities $ability;

		protected function setUp(): void {
			$GLOBALS['cs_user']         = 5;
			$GLOBALS['cs_terms']        = array(
				'venue'    => array(
					1524 => (object) array(
						'term_id'  => 1524,
						'taxonomy' => 'venue',
						'name'     => ' Lo-Fi Brewing ',
					),
				),
				'promoter' => array(
					88 => (object) array(
						'term_id'  => 88,
						'taxonomy' => 'promoter',
						'name'     => 'Real South',
					),
				),
			);
			$GLOBALS['cs_venue_grants'] = array( array( 5, 1524, 'manage_finances' ) );
			$GLOBALS['cs_orgs']         = array( 88 => array( 'status' => 'active' ) );
			$GLOBALS['cs_members']      = array(
				88 => array(
					5 => array( 'is_owner' => true ),
					6 => array( 'is_owner' => false ),
				),
			);
			$this->ability              = new CommerceSellerAbilities( new VenueAuthorization(), new PromoterAuthorityRepository(), false );
		}

		public function test_venue_finance_owner_can_manage(): void {
			$this->assertSame(
				array(
					'type'                => 'venue',
					'id'                  => 1524,
					'name'                => 'Lo-Fi Brewing',
					'url'                 => 'https://events.example/venue/1524/',
					'can_manage_finances' => true,
				),
				$this->ability->execute(
					array(
						'type' => 'venue',
						'id'   => 1524,
					)
				)
			);
			$GLOBALS['cs_user'] = 6;
			$this->assertFalse(
				$this->ability->execute(
					array(
						'type' => 'venue',
						'id'   => 1524,
					)
				)['can_manage_finances']
			);
		}

		public function test_promoter_requires_active_organization_and_owner_membership(): void {
			$this->assertTrue(
				$this->ability->execute(
					array(
						'type' => 'promoter',
						'id'   => 88,
					)
				)['can_manage_finances']
			);
			$GLOBALS['cs_user'] = 6;
			$this->assertFalse(
				$this->ability->execute(
					array(
						'type' => 'promoter',
						'id'   => 88,
					)
				)['can_manage_finances']
			);
			$GLOBALS['cs_orgs'][88]['status'] = 'revoked';
			$this->assertSame(
				'commerce_seller_not_found',
				$this->ability->execute(
					array(
						'type' => 'promoter',
						'id'   => 88,
					)
				)->get_error_code()
			);
		}

		public function test_rejects_anonymous_invalid_and_unknown_sellers(): void {
			foreach ( array(
				array(
					'type' => 'artist',
					'id'   => 1,
				),
				array(
					'type' => 'venue',
					'id'   => '1524',
				),
				array(
					'type' => 'venue',
					'id'   => 0,
				),
			) as $input ) {
				$this->assertSame( 'invalid_commerce_seller', $this->ability->execute( $input )->get_error_code() );
			}
			$this->assertSame(
				'commerce_seller_not_found',
				$this->ability->execute(
					array(
						'type' => 'venue',
						'id'   => 88,
					)
				)->get_error_code()
			);
			$GLOBALS['cs_user'] = 0;
			$this->assertSame(
				'commerce_seller_authentication_required',
				$this->ability->execute(
					array(
						'type' => 'venue',
						'id'   => 1524,
					)
				)->get_error_code()
			);
		}
	}
}

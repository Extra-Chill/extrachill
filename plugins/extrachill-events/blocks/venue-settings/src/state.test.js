/* global describe, expect, it */

/**
 * Internal dependencies
 */
import {
	editableConfig,
	normalizeKey,
	profileChanges,
	validateConfig,
} from './state';

const validConfig = () => ( {
	version: 10,
	enabled: true,
	intake: { version: 1, fields: [] },
	consent: {
		id: 'booking-privacy',
		version: 1,
		label: 'I agree.',
		required: true,
	},
	attachment_policy: { version: 1, enabled: false, purposes: [] },
	embed: { allowed_parent_origins: [] },
	spaces: [ { key: 'main_room', name: 'Main Room', is_default: true } ],
	default_deal: {
		version: 1,
		type: 'custom',
		guarantee_cents: 0,
		revenue_share_basis_points: 0,
		revenue_share_basis: 'gross_ticket_sales',
		currency: 'USD',
	},
	ticket_provider_reference: null,
	marketing_channels: [],
	marketing_triggers: [],
	hold_ttl_minutes: 1440,
} );

describe( 'venue settings state', () => {
	it( 'removes read metadata before a complete config replacement', () => {
		expect(
			editableConfig( {
				...validConfig(),
				revision: 4,
				updated_at: '2026-07-26 12:00:00',
				updated_by_user_id: 8,
			} )
		).toEqual( validConfig() );
	} );

	it( 'sends only changed canonical profile fields', () => {
		const baseline = {
			term_id: 12,
			name: 'Room',
			city: 'Charleston',
			revision: 'abc',
		};
		expect(
			profileChanges( { ...baseline, name: 'The Room' }, baseline )
		).toEqual( { name: 'The Room' } );
	} );

	it( 'normalizes operator labels into bounded keys', () => {
		expect( normalizeKey( '  Main Stage / Upstairs  ' ) ).toBe(
			'main_stage_upstairs'
		);
	} );

	it( 'reports duplicate, incomplete, and out-of-range configuration', () => {
		const config = validConfig();
		config.spaces.push( { key: 'main_room', name: '', is_default: true } );
		config.intake.fields.push( {
			key: 'genre',
			label: 'Genre',
			type: 'select',
			required: false,
			options: [],
		} );
		config.hold_ttl_minutes = 1;
		expect( validateConfig( config ) ).toEqual(
			expect.arrayContaining( [
				'Each space needs a name and key.',
				'Space keys must be unique.',
				'Choose one default space.',
				'A saved multiple-choice question needs at least one choice.',
				'Hold duration must be between 5 minutes and 14 days.',
			] )
		);
	} );

	it( 'accepts a fourteen-day hold and rejects values above it', () => {
		const config = validConfig();
		config.hold_ttl_minutes = 20160;
		expect( validateConfig( config ) ).toEqual( [] );

		config.hold_ttl_minutes = 20161;
		expect( validateConfig( config ) ).toContain(
			'Hold duration must be between 5 minutes and 14 days.'
		);
	} );

	it( 'fails closed for inconsistent attachment invitations and requirements', () => {
		const config = validConfig();
		config.attachment_policy = { version: 1, enabled: true, purposes: [] };
		expect( validateConfig( config ) ).toContain(
			'Choose at least one attachment purpose.'
		);

		config.attachment_policy.purposes = Array.from(
			{ length: 6 },
			( _, index ) => ( {
				key: [
					'promo_image',
					'epk',
					'press_release',
					'stage_plot',
					'technical_rider',
					'hospitality_rider',
				][ index ],
				requirement: 'required',
			} )
		);
		expect( validateConfig( config ) ).toContain(
			'Require no more than five attachment purposes.'
		);

		config.attachment_policy.enabled = false;
		expect( validateConfig( config ) ).toContain(
			'Disable all attachment purposes before turning files off.'
		);
	} );
} );

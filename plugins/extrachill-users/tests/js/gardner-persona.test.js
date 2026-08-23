/**
 * Contract validation for the canonical Gardner test persona.
 */

import fs from 'fs';
import path from 'path';
import Ajv from 'ajv';

const fixturePath = path.resolve( __dirname, '../personas/gardner.v1.json' );
const schemaPath = path.resolve( __dirname, '../personas/gardner.schema.json' );
const fixtureSource = fs.readFileSync( fixturePath, 'utf8' );
const persona = JSON.parse( fixtureSource );
const schema = JSON.parse( fs.readFileSync( schemaPath, 'utf8' ) );

describe( 'Gardner persona contract', () => {
	test( 'matches the versioned schema', () => {
		const validate = new Ajv( { allErrors: true, strict: true } ).compile(
			schema
		);

		expect( validate( persona ) ).toBe( true );
		expect( validate.errors ).toBeNull();
	} );

	test( 'keeps its stable identity and Users-owned access contract', () => {
		expect( persona.persona_id ).toBe( 'extra-chill-users/chris-gardner' );
		expect( persona.contract_version ).toBe( '1.0.0' );
		expect( persona.reference_persona.name ).toBe( 'Chris Gardner' );
		expect( persona.fixture_identity ).toEqual( {
			username: 'gardner_persona_fixture',
			display_name: 'Chris Gardner (Test Persona)',
			email: 'gardner-persona@example.invalid',
			non_production: true,
		} );
		expect( persona.network_access.team_role ).toBe( 'extra_chill_team' );
		expect( persona.network_access.site_membership ).toEqual( {
			scope: 'every-active-network-site',
			role: 'extra_chill_team',
		} );
		expect( persona.network_access.baseline_capabilities ).toEqual(
			expect.arrayContaining( [
				'read',
				'upload_files',
				'edit_posts',
				'access_studio',
				'access_events_admin',
				'submit_for_review',
			] )
		);
		expect( persona.network_access.explicit_user_grants ).toContainEqual( {
			capability: 'manage_brand_socials',
			scope: 'every-active-network-site',
		} );
	} );

	test( 'publishes each stable cross-product oracle exactly once', () => {
		const oracleIds = persona.oracles.map( ( oracle ) => oracle.id );

		expect( new Set( oracleIds ).size ).toBe( oracleIds.length );
		expect( oracleIds ).toEqual( [
			'task-completion',
			'obvious-state',
			'reload-persistence',
			'safe-retry',
			'duplicate-prevention',
			'attribution',
			'actionable-errors',
			'jargon-avoidance',
			'server-authorization',
		] );
	} );

	test( 'contains no live identity material or permitted external side effects', () => {
		expect(
			persona.fixture_identity.email.endsWith( '@example.invalid' )
		).toBe( true );
		expect( persona.safety ).toEqual( {
			production_credentials: false,
			tokens: false,
			personal_contact_data: false,
			live_external_writes: false,
		} );
		expect( fixtureSource ).not.toMatch(
			/(?:api[_-]?key|password|secret)/i
		);
		expect( fixtureSource ).not.toMatch(
			/(?:https?:\/\/|\+?1?[ .-]?\(?\d{3}\)?[ .-]?\d{3}[ .-]?\d{4})/
		);
	} );
} );

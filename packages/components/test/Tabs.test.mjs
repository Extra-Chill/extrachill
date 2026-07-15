import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { Tabs } from '../dist/Tabs.js';

const tabs = [
	{ id: 'one', label: 'One' },
	{ id: 'two', label: 'Two' },
	{ id: 'three', label: 'Three' },
];

function render( active = 'one' ) {
	const changes = [];
	const element = Tabs( { tabs, active, onChange: ( id ) => changes.push( id ), idPrefix: 'test-tabs' } );
	return { buttons: element.props.children, changes };
}

test( 'tabs expose roving tabindex and panel associations', () => {
	const { buttons } = render();
	assert.equal( buttons[ 0 ].props.id, 'test-tabs-tab-0' );
	assert.equal( buttons[ 0 ].props['aria-controls'], 'test-tabs-panel-0' );
	assert.equal( buttons[ 0 ].props.tabIndex, 0 );
	assert.equal( buttons[ 1 ].props.tabIndex, -1 );
} );

test( 'tabs activate and focus adjacent, first, and last tabs from the keyboard', () => {
	const focused = [];
	const previousDocument = globalThis.document;
	globalThis.document = { getElementById: ( id ) => ( { focus: () => focused.push( id ) } ) };

	try {
		const { buttons, changes } = render( 'two' );
		for ( const [ index, key, expected ] of [ [ 1, 'ArrowRight', 'three' ], [ 0, 'ArrowLeft', 'three' ], [ 2, 'Home', 'one' ], [ 0, 'End', 'three' ] ] ) {
			let prevented = false;
			buttons[ index ].props.onKeyDown( {
				key,
				currentTarget: { parentElement: null },
				preventDefault: () => { prevented = true; },
			} );
			assert.equal( prevented, true );
			assert.equal( changes.at( -1 ), expected );
		}
		assert.deepEqual( focused, [ 'test-tabs-tab-2', 'test-tabs-tab-2', 'test-tabs-tab-0', 'test-tabs-tab-2' ] );
	} finally {
		globalThis.document = previousDocument;
	}
} );

test( 'responsive mode modifiers override the default CSS breakpoint', async () => {
	const styles = await readFile( new URL( '../styles/components.scss', import.meta.url ), 'utf8' );
	assert.match( styles, /\.ec-responsive-tabs--desktop \.ec-responsive-tabs__accordion/ );
	assert.match( styles, /\.ec-responsive-tabs--mobile \.ec-tabs__tabs/ );
	assert.match( styles, /:not\(\.ec-responsive-tabs--desktop\) \.ec-tabs__tabs/ );
} );

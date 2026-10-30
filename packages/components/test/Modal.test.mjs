import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile( new URL( '../dist/Modal.js', import.meta.url ), 'utf8' );
const styles = await readFile( new URL( '../dist/components.css', import.meta.url ), 'utf8' );

test( 'modal marks itself as a modal dialog', () => {
	assert.match( source, /"aria-modal": "true"/ );
	assert.match( source, /role: "dialog"/ );
} );

test( 'modal styles the markup the component actually renders', () => {
	for ( const selector of [
		'.ec-modal',
		'.ec-modal__backdrop',
		'.ec-modal__content',
		'.ec-modal__header',
		'.ec-modal__body',
	] ) {
		assert.ok( styles.includes( selector ), `missing ${ selector }` );
	}
} );

test( 'modal does not style markup it no longer renders', () => {
	assert.ok( ! styles.includes( 'components-modal__content' ) );
	assert.ok( ! source.includes( 'components-modal__content' ) );
} );

test( 'modal surfaces come from theme tokens', () => {
	const block = styles.slice( styles.indexOf( '.ec-modal__content' ) );
	assert.match( block, /var\(--card-background/ );
	assert.match( block, /var\(--border-color/ );
} );

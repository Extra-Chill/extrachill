import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile( new URL( '../dist/SearchBox.js', import.meta.url ), 'utf8' );

test( 'search box gives its input a generic accessible name by default', () => {
	assert.match( source, /label = 'Search'/ );
	assert.match( source, /"aria-label": label/ );
} );

test( 'search box accepts context-specific accessible names', () => {
	assert.match( source, /"aria-label": label/ );
} );

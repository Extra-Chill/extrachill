/**
 * Link Page Analytics Block
 *
 * Registers the analytics block for the Gutenberg editor.
 */

/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit';

import './editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );

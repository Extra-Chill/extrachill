/**
 * Link Page Analytics Block
 *
 * Registers the analytics block for the Gutenberg editor.
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import Edit from './edit';

import './editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );

/**
 * Link Page Editor Block
 *
 * Gutenberg block for managing artist link pages with live preview.
 */

import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import metadata from './block.json';
import './editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );

import { registerBlockType } from '@wordpress/blocks';
import './editor.scss';

import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType( metadata.name, {
	edit: Edit,
} );

import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';
import Edit from './edit';
import metadata from './block.json';

registerBlockType( metadata.name as string, {
	edit: Edit,
	save() {
		return null;
	},
} as Partial< BlockConfiguration > );

import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';
import Edit from './edit';
import metadata from './block.json';

type StudioAttributes = {
	headline: string;
	description: string;
	deniedMessage: string;
};

registerBlockType< StudioAttributes >( metadata as BlockConfiguration< StudioAttributes >, {
	edit: Edit,
	save() {
		return null;
	},
} );

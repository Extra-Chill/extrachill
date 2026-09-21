import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	getArtist,
	getLinks,
	getSocials,
	updateArtist,
	updateLinks,
	updateSocials,
	uploadMedia,
	deleteMedia,
	generateQRCode,
} from '../shared/api/client';

const read = async ( artistId ) => {
	const [ artist, page, socials ] = await Promise.all( [
		getArtist( artistId ),
		getLinks( artistId ),
		getSocials( artistId ),
	] );
	return {
		identity: artist,
		link_page: page,
		socials: socials.social_links || [],
	};
};

const saveBio = ( artistId, bio ) =>
	apiFetch( {
		path: '/wp-abilities/v1/abilities/extrachill/save-link-page-settings/run',
		method: 'POST',
		data: { input: { artist_id: artistId, bio } },
	} );

const countLinks = ( sections ) =>
	( sections || [] ).reduce(
		( total, section ) =>
			total +
			( Array.isArray( section.links ) ? section.links.length : 0 ),
		0
	);

/**
 * Guard against silently emptying a populated Link Page.
 *
 * Fetches the currently persisted links only when the draft is about to save
 * zero links, so an already-empty page never prompts. When the transition is
 * genuinely populated-to-empty, requires an explicit confirmation before the
 * save proceeds; declining aborts the entire save (all dirty areas), so a
 * confirmed removal is always a deliberate, standalone action.
 *
 * @param {number} artistId Artist profile ID.
 * @param {Set}    dirty    Dirty area names for this save.
 * @param {Object} draft    Full editor draft state.
 * @return {Promise<{proceed: boolean, allowEmpty: boolean}>} `proceed` is
 *                          false only when the user declined a genuine
 *                          populated-to-empty confirmation. `allowEmpty` is
 *                          true only in that same confirmed case.
 */
const confirmEmptyLinksIntent = async ( artistId, dirty, draft ) => {
	if ( ! dirty.has( 'links' ) || 0 !== countLinks( draft.page.links ) ) {
		return { proceed: true, allowEmpty: false };
	}
	const current = await getLinks( artistId );
	if ( 0 === countLinks( current.links ) ) {
		return { proceed: true, allowEmpty: false };
	}
	// eslint-disable-next-line no-alert -- deliberate, synchronous confirmation before a destructive Link Page save; matches the existing pattern in EditorContext.js.
	const confirmed = window.confirm( 'Remove all links from your page?' );
	return { proceed: confirmed, allowEmpty: confirmed };
};

const save = async ( artistId, draft, { dirtyAreas = [] } = {} ) => {
	const dirty = new Set( dirtyAreas );
	const { proceed, allowEmpty } = await confirmEmptyLinksIntent(
		artistId,
		dirty,
		draft
	);
	if ( ! proceed ) {
		return read( artistId );
	}
	const tasks = [];
	if ( dirty.has( 'identity' ) ) {
		tasks.push(
			updateArtist( artistId, {
				name: draft.identity.name,
				profile_image_id: draft.identity.imageId,
			} )
		);
	}
	if (
		[ 'links', 'styles', 'settings', 'background' ].some( ( area ) =>
			dirty.has( area )
		)
	) {
		const pageChanges = {};
		if ( dirty.has( 'links' ) ) {
			pageChanges.links = draft.page.links;
			if ( allowEmpty ) {
				pageChanges.allow_empty = true;
			}
		}
		if ( dirty.has( 'styles' ) ) {
			pageChanges.css_vars = draft.page.styles;
		}
		if ( dirty.has( 'settings' ) ) {
			pageChanges.settings = draft.page.settings;
		}
		if ( dirty.has( 'background' ) ) {
			pageChanges.background_image_id = draft.page.backgroundImageId;
		}
		tasks.push( updateLinks( artistId, pageChanges ) );
	}
	if ( dirty.has( 'bio' ) ) {
		tasks.push( saveBio( artistId, draft.page.bio ) );
	}
	if ( dirty.has( 'socials' ) ) {
		tasks.push(
			updateSocials( artistId, { social_links: draft.socials } )
		);
	}
	await Promise.all( tasks );
	return read( artistId );
};

const InfoPanel = ( { draft, runUpload } ) =>
	createElement(
		'label',
		null,
		'Profile Image',
		createElement( 'input', {
			type: 'file',
			accept: 'image/*',
			onChange: async ( event ) => {
				const file = event.target.files?.[ 0 ];
				if ( ! file ) {
					return;
				}
				await runUpload(
					'profile',
					file,
					( current, result ) => ( {
						...current,
						identity: {
							...current.identity,
							imageId: result.attachment_id,
							imageUrl: result.url,
						},
					} ),
					'identity'
				);
			},
		} ),
		draft.identity.imageUrl &&
			createElement(
				'button',
				{
					type: 'button',
					className: 'button-2',
					onClick: () =>
						runUpload(
							'profile-remove',
							null,
							( current ) => ( {
								...current,
								identity: {
									...current.identity,
									imageId: 0,
									imageUrl: '',
								},
							} ),
							'identity'
						),
				},
				'Remove image'
			)
	);

const SocialsPanel = ( { draft, change, configuration } ) =>
	createElement(
		'div',
		{ className: 'ec-tab' },
		...draft.socials.map( ( social, index ) =>
			createElement(
				'div',
				{ className: 'ec-link-item', key: social.id || index },
				createElement( 'input', {
					type: 'url',
					'aria-label': `${ social.type || 'Social' } URL`,
					value: social.url || '',
					onChange: ( event ) => {
						const items = [ ...draft.socials ];
						items[ index ] = { ...social, url: event.target.value };
						change( { socials: items } );
					},
				} ),
				createElement(
					'button',
					{
						type: 'button',
						disabled: 0 === index,
						onClick: () => {
							const items = [ ...draft.socials ];
							[ items[ index - 1 ], items[ index ] ] = [
								items[ index ],
								items[ index - 1 ],
							];
							change( { socials: items } );
						},
					},
					'Move Up'
				),
				createElement(
					'button',
					{
						type: 'button',
						disabled: index === draft.socials.length - 1,
						onClick: () => {
							const items = [ ...draft.socials ];
							[ items[ index ], items[ index + 1 ] ] = [
								items[ index + 1 ],
								items[ index ],
							];
							change( { socials: items } );
						},
					},
					'Move Down'
				),
				createElement(
					'button',
					{
						type: 'button',
						'aria-label': 'Remove social link',
						onClick: () =>
							change( {
								socials: draft.socials.filter(
									( _, itemIndex ) => itemIndex !== index
								),
							} ),
					},
					'Remove'
				)
			)
		),
		createElement(
			'select',
			{
				'aria-label': 'Add social link',
				value: '',
				onChange: ( event ) => {
					const type = configuration.socialTypes?.find(
						( item ) => item.id === event.target.value
					);
					if ( type ) {
						change( {
							socials: [
								...draft.socials,
								{
									id: `temp-social-${ Date.now() }`,
									type: type.id,
									url: '',
									icon_class: type.icon_class,
								},
							],
						} );
					}
				},
			},
			createElement( 'option', { value: '' }, 'Add Social Link' ),
			...( configuration.socialTypes || [] ).map( ( type ) =>
				createElement(
					'option',
					{ key: type.id, value: type.id },
					type.label
				)
			)
		)
	);

export const adapter = {
	read,
	save,
	upload: ( type, artistId, file ) => {
		if ( 'profile-remove' === type ) {
			return deleteMedia( 'artist_profile', artistId );
		}
		if ( 'background-remove' === type ) {
			return deleteMedia( 'link_page_background', artistId );
		}
		return uploadMedia(
			type === 'background' ? 'link_page_background' : 'artist_profile',
			artistId,
			file
		);
	},
	infoPanel: ( props ) => createElement( InfoPanel, props ),
	socialsPanel: ( props ) => createElement( SocialsPanel, props ),
	qrCode: async ( url, size ) =>
		( await generateQRCode( url, size ) ).image_url,
};

if ( window.ExtraChillLinkPageEditor?.registerAdapter ) {
	window.ExtraChillLinkPageEditor.registerAdapter(
		'extrachill-artist-platform',
		adapter
	);
} else {
	window.ecLinkPageEditorPendingAdapters =
		window.ecLinkPageEditorPendingAdapters || [];
	window.ecLinkPageEditorPendingAdapters.push( [
		'extrachill-artist-platform',
		adapter,
	] );
}

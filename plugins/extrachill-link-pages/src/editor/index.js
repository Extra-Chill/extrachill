import {
	Children,
	cloneElement,
	createRoot,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import './style.scss';

const DEFAULT_STYLES = {
	'--link-page-background-color': '#121212',
	'--link-page-text-color': '#e5e5e5',
	'--link-page-link-text-color': '#ffffff',
	'--link-page-button-bg-color': '#0b5394',
	'--link-page-button-hover-bg-color': '#53940b',
	'--link-page-background-type': 'color',
	'--link-page-background-gradient-start': '#0b5394',
	'--link-page-background-gradient-end': '#53940b',
	'--link-page-background-gradient-direction': 'to right',
	'--link-page-title-font-size': '2.1em',
	'--link-page-button-radius': '8px',
	'--link-page-profile-img-size': '30%',
	overlay: '1',
};

export const DEFAULT_LIMITS = {
	sections: 10,
	linksPerSection: 25,
	sectionTitleLength: 200,
	linkTextLength: 200,
	urlLength: 2048,
	bioLength: 5000,
	displayNameLength: 200,
};

const text = ( value, fallback = '' ) =>
	typeof value === 'string' ? value : fallback;
const hasOwn = ( value, key ) =>
	Object.prototype.hasOwnProperty.call( value, key );
const tempId = ( type ) =>
	`temp-${ type }-${ Date.now() }-${ Math.random()
		.toString( 36 )
		.slice( 2, 8 ) }`;
const storageKey = ( adapter, id ) =>
	`ec-link-page-editor:dirty:${ adapter }:${ id }`;
let editorInstance = 0;

const limitsFor = ( configured = {} ) => ( {
	...DEFAULT_LIMITS,
	...configured,
} );

export const validateDraft = ( draft, configuredLimits = {} ) => {
	const limits = limitsFor( configuredLimits );
	if ( ! draft || draft.identity.name.length > limits.displayNameLength ) {
		return 'Display name exceeds the supported length.';
	}
	if ( draft.page.bio.length > limits.bioLength ) {
		return 'Link Page bio exceeds the supported length.';
	}
	if ( draft.page.links.length > limits.sections ) {
		return 'Link Page contains too many sections.';
	}
	for ( const section of draft.page.links ) {
		if (
			( section.section_title || '' ).length > limits.sectionTitleLength
		) {
			return 'A section title exceeds the supported length.';
		}
		if (
			! Array.isArray( section.links ) ||
			section.links.length > limits.linksPerSection
		) {
			return 'A section contains too many links.';
		}
		for ( const link of section.links ) {
			const title = ( link.link_text || '' ).trim();
			const url = ( link.link_url || '' ).trim();
			if ( ! title || title.length > limits.linkTextLength ) {
				return 'Every link needs a supported title.';
			}
			if ( ! url || url.length > limits.urlLength ) {
				return 'Every link needs a supported URL.';
			}
			try {
				const parsed = new URL( url );
				if ( ! [ 'http:', 'https:' ].includes( parsed.protocol ) ) {
					return 'Every link URL must use HTTP or HTTPS.';
				}
			} catch ( error ) {
				return 'Every link needs a valid URL.';
			}
		}
	}
	return '';
};

export const normalizeDocument = ( value = {} ) => {
	const page = value.link_page || value.page || {};
	const identity = value.identity || value.subject || {};
	let sections = page.link_sections || page.links || [];
	if ( sections.length && ! Array.isArray( sections[ 0 ]?.links ) ) {
		sections = [ { id: '', section_title: '', links: sections } ];
	}
	return {
		identity: {
			id: identity.id || identity.term_id || 0,
			name: hasOwn( identity, 'name' )
				? text( identity.name )
				: text( identity.title ),
			hasName: hasOwn( identity, 'name' ) || hasOwn( identity, 'title' ),
			slug: text( identity.slug ),
			imageId: identity.image_id || identity.profile_image_id || 0,
			imageUrl: text(
				identity.image_url ||
					identity.profile_image_url ||
					identity.snapshot?.image_url
			),
		},
		page: {
			id: page.link_page_id || page.id || 0,
			revision: text( page.revision ),
			bio: hasOwn( page, 'bio' )
				? text( page.bio )
				: text( identity.snapshot?.description ),
			links: Array.isArray( sections ) ? sections : [],
			styles: {
				...DEFAULT_STYLES,
				...( page.css_vars || page.styles || {} ),
			},
			settings: page.settings || {},
			backgroundImageId: page.background_image_id || 0,
			backgroundImageUrl: text( page.background_image_url ),
			publicUrl: text( page.public_url || identity.public_url ),
		},
		socials: Array.isArray( value.socials )
			? value.socials
			: identity.snapshot?.social_links || [],
	};
};

const Field = ( { label, children, help } ) => {
	const generatedId = useId();
	const childElements = Children.toArray( children );
	const control = childElements[ 0 ];
	const controlId = control.props.id || generatedId;
	const helpId = `${ controlId }-help`;
	return (
		<div className="ec-lpe-field">
			<label htmlFor={ controlId }>{ label }</label>
			{ cloneElement( control, {
				id: controlId,
				...( help ? { 'aria-describedby': helpId } : {} ),
			} ) }
			{ childElements.slice( 1 ) }
			{ help && <small id={ helpId }>{ help }</small> }
		</div>
	);
};

function LinksPanel( { draft, change, limits } ) {
	const updateSection = ( index, patch ) => {
		const links = [ ...draft.page.links ];
		links[ index ] = { ...links[ index ], ...patch };
		change( { page: { ...draft.page, links } } );
	};
	const move = ( index, direction ) => {
		const destination = index + direction;
		if ( destination < 0 || destination >= draft.page.links.length ) {
			return;
		}
		const links = [ ...draft.page.links ];
		[ links[ index ], links[ destination ] ] = [
			links[ destination ],
			links[ index ],
		];
		change( { page: { ...draft.page, links } } );
	};
	const moveLink = ( sectionIndex, linkIndex, direction ) => {
		const destination = linkIndex + direction;
		const section = draft.page.links[ sectionIndex ];
		if ( destination < 0 || destination >= section.links.length ) {
			return;
		}
		const items = [ ...section.links ];
		[ items[ linkIndex ], items[ destination ] ] = [
			items[ destination ],
			items[ linkIndex ],
		];
		const sections = [ ...draft.page.links ];
		sections[ sectionIndex ] = { ...section, links: items };
		change( { page: { ...draft.page, links: sections } } );
	};
	return (
		<div className="ec-tab ec-tab--links">
			{ draft.page.links.map( ( section, sectionIndex ) => (
				<fieldset
					className="ec-lpe-section"
					key={ section.id || sectionIndex }
				>
					<div className="ec-lpe-section__header">
						<input
							aria-label="Section title"
							value={ section.section_title || '' }
							maxLength={ limits.sectionTitleLength }
							placeholder="Section title (optional)"
							onChange={ ( event ) =>
								updateSection( sectionIndex, {
									section_title: event.target.value,
								} )
							}
						/>
						<button
							type="button"
							aria-label="Move section up"
							onClick={ () => move( sectionIndex, -1 ) }
						>
							Up
						</button>
						<button
							type="button"
							aria-label="Move section down"
							onClick={ () => move( sectionIndex, 1 ) }
						>
							Down
						</button>
						<button
							type="button"
							aria-label="Remove section"
							onClick={ () =>
								change( {
									page: {
										...draft.page,
										links: draft.page.links.filter(
											( _, index ) =>
												index !== sectionIndex
										),
									},
								} )
							}
						>
							Remove
						</button>
					</div>
					<div className="ec-lpe-section__content">
						{ ( section.links || [] ).map( ( link, linkIndex ) => (
							<div
								className="ec-link-item"
								key={ link.id || linkIndex }
							>
								<div className="ec-link-item__fields">
									<input
										aria-label="Link title"
										required
										value={ link.link_text || '' }
										maxLength={ limits.linkTextLength }
										placeholder="Link title"
										onChange={ ( event ) => {
											const items = [ ...section.links ];
											items[ linkIndex ] = {
												...link,
												link_text: event.target.value,
											};
											updateSection( sectionIndex, {
												links: items,
											} );
										} }
									/>
									<input
										aria-label="Link URL"
										type="url"
										required
										value={ link.link_url || '' }
										maxLength={ limits.urlLength }
										placeholder="https://..."
										onChange={ ( event ) => {
											const items = [ ...section.links ];
											items[ linkIndex ] = {
												...link,
												link_url: event.target.value,
											};
											updateSection( sectionIndex, {
												links: items,
											} );
										} }
									/>
									{ draft.page.settings
										.link_expiration_enabled && (
										<input
											aria-label="Expiration date"
											type="datetime-local"
											value={ link.expires_at || '' }
											onChange={ ( event ) => {
												const items = [
													...section.links,
												];
												items[ linkIndex ] = {
													...link,
													expires_at:
														event.target.value,
												};
												updateSection( sectionIndex, {
													links: items,
												} );
											} }
										/>
									) }
								</div>
								<button
									type="button"
									disabled={ 0 === linkIndex }
									onClick={ () =>
										moveLink( sectionIndex, linkIndex, -1 )
									}
								>
									Move Up
								</button>
								<button
									type="button"
									disabled={
										linkIndex === section.links.length - 1
									}
									onClick={ () =>
										moveLink( sectionIndex, linkIndex, 1 )
									}
								>
									Move Down
								</button>
								<button
									type="button"
									aria-label="Remove link"
									onClick={ () =>
										updateSection( sectionIndex, {
											links: section.links.filter(
												( _, index ) =>
													index !== linkIndex
											),
										} )
									}
								>
									Remove
								</button>
							</div>
						) ) }
						<button
							type="button"
							className="button-2"
							disabled={
								section.links.length >= limits.linksPerSection
							}
							onClick={ () =>
								updateSection( sectionIndex, {
									links: [
										...( section.links || [] ),
										{
											id: tempId( 'link' ),
											link_text: '',
											link_url: '',
										},
									],
								} )
							}
						>
							Add Link
						</button>
					</div>
				</fieldset>
			) ) }
			<button
				type="button"
				className="button-2"
				disabled={ draft.page.links.length >= limits.sections }
				onClick={ () =>
					change( {
						page: {
							...draft.page,
							links: [
								...draft.page.links,
								{
									id: tempId( 'section' ),
									section_title: '',
									links: [],
								},
							],
						},
					} )
				}
			>
				Add Section
			</button>
		</div>
	);
}

function CustomizePanel( { draft, change, adapter, runUpload, fonts } ) {
	const styles = draft.page.styles;
	const setting = ( key, value ) =>
		change(
			{
				page: {
					...draft.page,
					settings: { ...draft.page.settings, [ key ]: value },
				},
			},
			'settings'
		);
	const style = ( key, value ) =>
		change(
			{
				page: { ...draft.page, styles: { ...styles, [ key ]: value } },
			},
			'styles'
		);
	const upload = async ( event ) => {
		const file = event.target.files?.[ 0 ];
		if ( ! file || ! adapter.upload ) {
			return;
		}
		await runUpload(
			'background',
			file,
			( current, result ) => ( {
				...current,
				page: {
					...current.page,
					backgroundImageId: result.attachment_id,
					backgroundImageUrl: result.url,
				},
			} ),
			'background'
		);
	};
	return (
		<div className="ec-tab ec-tab--customize">
			{ fonts.length > 0 && (
				<>
					<Field label="Title Font">
						<select
							value={
								styles[ '--link-page-title-font-family' ] || ''
							}
							onChange={ ( event ) =>
								style(
									'--link-page-title-font-family',
									event.target.value
								)
							}
						>
							{ fonts.map( ( font ) => (
								<option key={ font.value } value={ font.value }>
									{ font.label }
								</option>
							) ) }
						</select>
					</Field>
					<Field label="Body Font">
						<select
							value={
								styles[ '--link-page-body-font-family' ] || ''
							}
							onChange={ ( event ) =>
								style(
									'--link-page-body-font-family',
									event.target.value
								)
							}
						>
							{ fonts.map( ( font ) => (
								<option key={ font.value } value={ font.value }>
									{ font.label }
								</option>
							) ) }
						</select>
					</Field>
				</>
			) }
			<Field label="Title Size">
				<input
					type="range"
					min="0.8"
					max="3.5"
					step="0.1"
					value={
						parseFloat( styles[ '--link-page-title-font-size' ] ) ||
						2.1
					}
					onChange={ ( event ) =>
						style(
							'--link-page-title-font-size',
							`${ event.target.value }em`
						)
					}
				/>
			</Field>
			<Field label="Profile Image Size">
				<input
					type="range"
					min="1"
					max="100"
					value={
						parseInt(
							styles[ '--link-page-profile-img-size' ],
							10
						) || 30
					}
					onChange={ ( event ) =>
						style(
							'--link-page-profile-img-size',
							`${ event.target.value }%`
						)
					}
				/>
			</Field>
			<Field label="Background Type">
				<select
					value={ styles[ '--link-page-background-type' ] }
					onChange={ ( event ) =>
						style(
							'--link-page-background-type',
							event.target.value
						)
					}
				>
					<option value="color">Solid Color</option>
					<option value="gradient">Gradient</option>
					<option value="image">Image</option>
				</select>
			</Field>
			{ styles[ '--link-page-background-type' ] === 'color' && (
				<Field label="Background Color">
					<input
						type="color"
						value={ styles[ '--link-page-background-color' ] }
						onChange={ ( event ) =>
							style(
								'--link-page-background-color',
								event.target.value
							)
						}
					/>
				</Field>
			) }
			{ styles[ '--link-page-background-type' ] === 'gradient' && (
				<>
					<Field label="Gradient Start">
						<input
							type="color"
							value={
								styles[
									'--link-page-background-gradient-start'
								]
							}
							onChange={ ( event ) =>
								style(
									'--link-page-background-gradient-start',
									event.target.value
								)
							}
						/>
					</Field>
					<Field label="Gradient End">
						<input
							type="color"
							value={
								styles[ '--link-page-background-gradient-end' ]
							}
							onChange={ ( event ) =>
								style(
									'--link-page-background-gradient-end',
									event.target.value
								)
							}
						/>
					</Field>
					<Field label="Gradient Direction">
						<select
							value={
								styles[
									'--link-page-background-gradient-direction'
								] || 'to right'
							}
							onChange={ ( event ) =>
								style(
									'--link-page-background-gradient-direction',
									event.target.value
								)
							}
						>
							<option value="to right">Left to Right</option>
							<option value="to bottom">Top to Bottom</option>
							<option value="135deg">Diagonal</option>
						</select>
					</Field>
				</>
			) }
			{ styles[ '--link-page-background-type' ] === 'image' &&
				adapter.upload && (
					<Field label="Background Image">
						<input
							type="file"
							accept="image/*"
							onChange={ upload }
						/>
						{ draft.page.backgroundImageUrl && (
							<button
								type="button"
								className="button-2"
								onClick={ () =>
									runUpload(
										'background-remove',
										null,
										( current ) => ( {
											...current,
											page: {
												...current.page,
												backgroundImageId: 0,
												backgroundImageUrl: '',
											},
										} ),
										'background'
									)
								}
							>
								Remove Background Image
							</button>
						) }
					</Field>
				) }
			<Field label="Text Color">
				<input
					type="color"
					value={ styles[ '--link-page-text-color' ] }
					onChange={ ( event ) =>
						style( '--link-page-text-color', event.target.value )
					}
				/>
			</Field>
			<Field label="Button Color">
				<input
					type="color"
					value={ styles[ '--link-page-button-bg-color' ] }
					onChange={ ( event ) =>
						style(
							'--link-page-button-bg-color',
							event.target.value
						)
					}
				/>
			</Field>
			<Field label="Link Text Color">
				<input
					type="color"
					value={
						styles[ '--link-page-link-text-color' ] || '#ffffff'
					}
					onChange={ ( event ) =>
						style(
							'--link-page-link-text-color',
							event.target.value
						)
					}
				/>
			</Field>
			<Field label="Button Hover Color">
				<input
					type="color"
					value={
						styles[ '--link-page-button-hover-bg-color' ] ||
						'#53940b'
					}
					onChange={ ( event ) =>
						style(
							'--link-page-button-hover-bg-color',
							event.target.value
						)
					}
				/>
			</Field>
			<Field label="Button Border Color">
				<input
					type="color"
					value={
						styles[ '--link-page-button-border-color' ] || '#0b5394'
					}
					onChange={ ( event ) =>
						style(
							'--link-page-button-border-color',
							event.target.value
						)
					}
				/>
			</Field>
			<Field label="Button Radius">
				<input
					type="range"
					min="0"
					max="50"
					value={
						parseInt( styles[ '--link-page-button-radius' ], 10 ) ||
						0
					}
					onChange={ ( event ) =>
						style(
							'--link-page-button-radius',
							`${ event.target.value }px`
						)
					}
				/>
			</Field>
			<Field label="Overlay">
				<input
					type="checkbox"
					checked={ styles.overlay !== '0' }
					onChange={ ( event ) =>
						style( 'overlay', event.target.checked ? '1' : '0' )
					}
				/>
			</Field>
			<Field label="Image Shape">
				<select
					value={
						draft.page.settings.profile_image_shape || 'circle'
					}
					onChange={ ( event ) =>
						setting( 'profile_image_shape', event.target.value )
					}
				>
					<option value="circle">Circle</option>
					<option value="square">Square</option>
					<option value="rectangle">Rectangle</option>
				</select>
			</Field>
		</div>
	);
}

function AdvancedPanel( { draft, change, subscriptions } ) {
	const settings = draft.page.settings;
	const set = ( key, value ) =>
		change(
			{
				page: {
					...draft.page,
					settings: { ...settings, [ key ]: value },
				},
			},
			'settings'
		);
	return (
		<div className="ec-tab ec-tab--advanced">
			<Field label="Enable Link Expiration Dates">
				<input
					type="checkbox"
					checked={ !! settings.link_expiration_enabled }
					onChange={ ( event ) =>
						set( 'link_expiration_enabled', event.target.checked )
					}
				/>
			</Field>
			<Field label="Enable Temporary Redirect">
				<input
					type="checkbox"
					checked={ !! settings.redirect_enabled }
					onChange={ ( event ) =>
						set( 'redirect_enabled', event.target.checked )
					}
				/>
			</Field>
			{ settings.redirect_enabled && (
				<Field label="Redirect URL">
					<input
						type="url"
						value={ settings.redirect_target_url || '' }
						onChange={ ( event ) =>
							set( 'redirect_target_url', event.target.value )
						}
					/>
				</Field>
			) }
			<Field label="Disable Inline YouTube Player">
				<input
					type="checkbox"
					checked={ settings.youtube_embed_enabled === false }
					onChange={ ( event ) =>
						set( 'youtube_embed_enabled', ! event.target.checked )
					}
				/>
			</Field>
			<Field label="Meta Pixel ID">
				<input
					value={ settings.meta_pixel_id || '' }
					onChange={ ( event ) =>
						set( 'meta_pixel_id', event.target.value )
					}
				/>
			</Field>
			<Field label="Google Tag ID">
				<input
					value={ settings.google_tag_id || '' }
					onChange={ ( event ) =>
						set( 'google_tag_id', event.target.value )
					}
				/>
			</Field>
			{ subscriptions && (
				<>
					<Field label="Subscription Display">
						<select
							value={
								settings.subscribe_display_mode || 'icon_modal'
							}
							onChange={ ( event ) =>
								set(
									'subscribe_display_mode',
									event.target.value
								)
							}
						>
							<option value="icon_modal">Subscribe Icon</option>
							<option value="inline_form">Inline Form</option>
							<option value="disabled">Disabled</option>
						</select>
					</Field>
					<Field label="Subscribe Form Description">
						<textarea
							rows="3"
							value={ settings.subscribe_description || '' }
							onChange={ ( event ) =>
								set(
									'subscribe_description',
									event.target.value
								)
							}
						/>
					</Field>
				</>
			) }
		</div>
	);
}

export function Preview( {
	draft,
	fonts = [],
	localFontsCss = '',
	instanceId = 'ec-lpe-preview',
	subscriptions = true,
} ) {
	const style = { ...draft.page.styles };
	const backgroundType =
		draft.page.styles[ '--link-page-background-type' ] || 'color';
	style.backgroundImage = 'none';
	style.backgroundColor =
		draft.page.styles[ '--link-page-background-color' ] || '#121212';
	if ( 'gradient' === backgroundType ) {
		style.backgroundImage = `linear-gradient(${
			draft.page.styles[ '--link-page-background-gradient-direction' ] ||
			'to right'
		}, ${
			draft.page.styles[ '--link-page-background-gradient-start' ] ||
			'#0b5394'
		}, ${
			draft.page.styles[ '--link-page-background-gradient-end' ] ||
			'#53940b'
		})`;
	} else if ( 'image' === backgroundType && draft.page.backgroundImageUrl ) {
		style.backgroundImage = `url(${ draft.page.backgroundImageUrl })`;
		style.backgroundSize =
			draft.page.styles[ '--link-page-image-size' ] || 'cover';
		style.backgroundPosition =
			draft.page.styles[ '--link-page-image-position' ] ||
			'center center';
		style.backgroundRepeat =
			draft.page.styles[ '--link-page-image-repeat' ] || 'no-repeat';
	}
	const renderSocials = () =>
		draft.socials.length > 0 ? (
			<div className="extrch-link-page-socials">
				{ draft.socials.map( ( item, index ) => (
					<a
						key={ item.id || index }
						href={ item.url || '#' }
						className="extrch-social-icon"
						aria-label={ item.label || item.type }
					>
						<i className={ item.icon_class } />
					</a>
				) ) }
			</div>
		) : null;
	useEffect( () => {
		const styleId = `${ instanceId }-local-fonts`;
		const linkId = `${ instanceId }-remote-fonts`;
		if ( localFontsCss ) {
			const styleElement = document.createElement( 'style' );
			styleElement.id = styleId;
			styleElement.textContent = localFontsCss;
			document.head.appendChild( styleElement );
		}
		const selected = [
			draft.page.styles[ '--link-page-title-font-family' ],
			draft.page.styles[ '--link-page-body-font-family' ],
		];
		const families = selected
			.map(
				( value ) =>
					fonts.find( ( font ) => font.value === value )
						?.google_font_param
			)
			.filter( ( value ) => value && 'local_default' !== value );
		if ( families.length ) {
			const link = document.createElement( 'link' );
			link.id = linkId;
			link.rel = 'stylesheet';
			link.href = `https://fonts.googleapis.com/css2?family=${ [
				...new Set( families ),
			].join( '&family=' ) }&display=swap`;
			document.head.appendChild( link );
		}
		return () => {
			document.getElementById( styleId )?.remove();
			document.getElementById( linkId )?.remove();
		};
	}, [ draft.page.styles, fonts, instanceId, localFontsCss ] );
	return (
		<div className="ec-preview-wrapper">
			<div
				className="extrch-link-page-container extrch-link-page-preview-container"
				style={ style }
				data-bg-type={ backgroundType }
				data-background-image={ style.backgroundImage }
			>
				<div
					className={ `extrch-link-page-content-wrapper${
						draft.page.styles.overlay === '0' ? ' no-overlay' : ''
					}` }
				>
					<div className="extrch-link-page-header-content">
						<div
							className={ `extrch-link-page-profile-img shape-${
								draft.page.settings.profile_image_shape ||
								'circle'
							}${ draft.identity.imageUrl ? '' : ' no-image' }` }
						>
							{ draft.identity.imageUrl && (
								<img src={ draft.identity.imageUrl } alt="" />
							) }
						</div>
						<h1 className="extrch-link-page-title">
							{ draft.identity.name }
						</h1>
						{ draft.page.bio && (
							<div className="extrch-link-page-bio">
								{ draft.page.bio }
							</div>
						) }
						{ subscriptions &&
							'icon_modal' ===
								( draft.page.settings.subscribe_display_mode ||
									'icon_modal' ) && (
								<button
									type="button"
									className="extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger"
									aria-label="Subscribe to this page"
								>
									Subscribe
								</button>
							) }
						<button
							type="button"
							className="extrch-share-trigger extrch-share-page-trigger"
							aria-label="Share this page"
						>
							Share
						</button>
					</div>
					{ 'below' !== draft.page.settings.social_icons_position &&
						renderSocials() }
					{ draft.page.links.map( ( section, index ) => (
						<div key={ section.id || index }>
							{ section.section_title && (
								<div className="extrch-link-page-section-title">
									{ section.section_title }
								</div>
							) }
							<div className="extrch-link-page-links">
								{ ( section.links || [] ).map(
									( link, itemIndex ) => (
										<div
											key={ link.id || itemIndex }
											className="extrch-link-button-wrapper"
										>
											<a
												href={ link.link_url || '#' }
												className="extrch-link-page-link"
												rel="ugc noopener"
											>
												<span className="extrch-link-page-link-text">
													{ link.link_text ||
														'Untitled Link' }
												</span>
											</a>
											<button
												type="button"
												className="extrch-share-trigger extrch-share-item-trigger"
												aria-label="Share this link"
											>
												Share
											</button>
										</div>
									)
								) }
							</div>
						</div>
					) ) }
					{ 'below' === draft.page.settings.social_icons_position &&
						renderSocials() }
					{ subscriptions &&
						'inline_form' ===
							draft.page.settings.subscribe_display_mode && (
							<div className="extrch-link-page-subscribe-inline-form-container">
								<h3 className="extrch-subscribe-header">
									Subscribe
								</h3>
								<p className="extrch-subscribe-description">
									{ draft.page.settings
										.subscribe_description ||
										'Enter your email address to receive updates.' }
								</p>
								<div
									className="extrch-subscribe-form"
									role="presentation"
								>
									<input
										type="email"
										aria-label="Email Address"
										placeholder="Your email address"
									/>
									<button
										type="button"
										className="button-1 button-small"
									>
										Subscribe
									</button>
								</div>
							</div>
						) }
					<div className="extrch-link-page-powered">
						Powered by Extra Chill
					</div>
					{ subscriptions &&
						'icon_modal' ===
							( draft.page.settings.subscribe_display_mode ||
								'icon_modal' ) && (
							<div
								id="extrch-subscribe-modal"
								className="extrch-subscribe-modal extrch-modal extrch-modal-hidden"
								role="dialog"
								aria-modal="true"
								aria-label="Subscribe"
							>
								<div className="extrch-subscribe-modal-overlay extrch-modal-overlay" />
								<div className="extrch-subscribe-modal-content extrch-modal-content">
									<p className="extrch-subscribe-description">
										{ draft.page.settings
											.subscribe_description ||
											'Enter your email address to receive updates.' }
									</p>
								</div>
							</div>
						) }
					<div
						id="extrch-share-modal"
						className="extrch-share-modal extrch-modal extrch-modal-hidden"
					>
						<div className="extrch-share-modal-overlay extrch-modal-overlay" />
						<div className="extrch-share-modal-content extrch-modal-content">
							Share this page
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}

export function QrModal( { url, error, onClose, restoreFocus, titleId } ) {
	const dialogRef = useRef( null );
	const closeRef = useRef( onClose );
	closeRef.current = onClose;
	useEffect( () => {
		const dialog = dialogRef.current;
		const getFocusable = () =>
			dialog?.querySelectorAll( 'button:not([disabled]), a[href]' ) || [];
		getFocusable()[ 0 ]?.focus();
		const onKeyDown = ( event ) => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closeRef.current();
				return;
			}
			const focusable = getFocusable();
			if ( 'Tab' !== event.key || 0 === focusable.length ) {
				return;
			}
			if ( 1 === focusable.length ) {
				event.preventDefault();
				focusable[ 0 ].focus();
				return;
			}
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			if (
				event.shiftKey &&
				dialog.ownerDocument.activeElement === first
			) {
				event.preventDefault();
				last.focus();
			} else if (
				! event.shiftKey &&
				dialog.ownerDocument.activeElement === last
			) {
				event.preventDefault();
				first.focus();
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			restoreFocus?.focus();
		};
	}, [ restoreFocus ] );
	return (
		<div className="ec-qr-modal" role="presentation">
			<div
				ref={ dialogRef }
				className="ec-qr-modal__content"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
			>
				<h2 id={ titleId }>Link Page QR Code</h2>
				<button type="button" onClick={ onClose }>
					Close
				</button>
				{ 'loading' === url && (
					<p role="status">Generating QR Code...</p>
				) }
				{ error && <p role="alert">{ error }</p> }
				{ url && ! [ 'loading', 'error' ].includes( url ) && (
					<>
						<img src={ url } alt="Link Page QR Code" />
						<a
							className="button-2"
							href={ url }
							download="link-page-qr-code.png"
						>
							Download for Print
						</a>
					</>
				) }
			</div>
		</div>
	);
}

export function Editor( { configuration, adapter: suppliedAdapter } ) {
	const adapter =
		suppliedAdapter ||
		window.ecLinkPageEditorAdapters?.[ configuration.adapter ];
	const identities = configuration.identities || [];
	const initialIdentity =
		configuration.initialIdentity || identities[ 0 ]?.id;
	const [ identityId, setIdentityId ] = useState( initialIdentity );
	const [ draft, setDraft ] = useState( null );
	const [ phase, setPhase ] = useState(
		configuration.status === 'not_provisioned' ? 'absent' : 'loading'
	);
	const [ message, setMessage ] = useState( '' );
	const [ qrCode, setQrCode ] = useState( '' );
	const [ qrError, setQrError ] = useState( '' );
	const [ dirty, setDirty ] = useState( false );
	const dirtyAreasRef = useRef( new Set() );
	const identityRef = useRef( initialIdentity );
	const requestRef = useRef( 0 );
	const revisionRef = useRef( 0 );
	const savingRef = useRef( false );
	const uploadingRef = useRef( false );
	const formRef = useRef( null );
	const qrButtonRef = useRef( null );
	const instanceRef = useRef( `ec-lpe-${ ++editorInstance }` );
	const limits = limitsFor( configuration.limits );
	const capabilities = {
		identity: true,
		bio: true,
		socials: true,
		backgroundMedia: true,
		subscriptions: true,
		...configuration.capabilities,
	};
	const isBusy = 'saving' === phase || 'uploading' === phase;
	const configuredPanels = adapter?.panels || [];
	const panels = configuredPanels.filter(
		( panel ) =>
			panel &&
			typeof panel.id === 'string' &&
			typeof panel.area === 'string' &&
			'' !== panel.area.trim() &&
			typeof panel.render === 'function'
	);
	const hasInvalidPanel = panels.length !== configuredPanels.length;
	const tabs = [
		...( capabilities.identity || capabilities.bio || adapter?.infoPanel
			? [ 'info' ]
			: [] ),
		'links',
		...( capabilities.socials ? [ 'socials' ] : [] ),
		'customize',
		'advanced',
		...panels.map( ( panel ) => panel.id ),
	];
	const [ active, setActive ] = useState( tabs[ 0 ] || 'links' );
	const activePanel = panels.find( ( panel ) => panel.id === active );
	const notifyDirty = ( value ) => {
		setDirty( value );
		adapter?.onDirtyChange?.( value );
	};
	const beginRequest = ( id = identityRef.current ) => ( {
		id,
		generation: ++requestRef.current,
	} );
	const isCurrentRequest = ( token ) =>
		token.generation === requestRef.current &&
		String( token.id ) === String( identityRef.current );
	const accept = ( value, token ) => {
		if ( ! isCurrentRequest( token ) ) {
			return false;
		}
		const normalized = normalizeDocument( value );
		const current =
			identities.find(
				( item ) => String( item.id ) === String( token.id )
			) || {};
		normalized.identity = {
			...normalized.identity,
			id: token.id,
			name: normalized.identity.hasName
				? normalized.identity.name
				: current.label || current.name || '',
			imageUrl: normalized.identity.imageUrl || current.imageUrl,
		};
		normalized.page.publicUrl =
			normalized.page.publicUrl || current.publicUrl || '';
		setDraft( normalized );
		revisionRef.current = 0;
		dirtyAreasRef.current = new Set();
		savingRef.current = false;
		uploadingRef.current = false;
		notifyDirty( false );
		setPhase( 'ready' );
		window.sessionStorage.removeItem(
			storageKey( configuration.adapter, token.id )
		);
		return true;
	};
	const load = async ( requestedIdentity = identityRef.current ) => {
		if ( ! adapter?.read || ! requestedIdentity ) {
			setPhase( 'unavailable' );
			return;
		}
		const token = beginRequest( requestedIdentity );
		setPhase( 'loading' );
		setMessage( '' );
		try {
			const saved = window.sessionStorage.getItem(
				storageKey( configuration.adapter, requestedIdentity )
			);
			if (
				saved &&
				// eslint-disable-next-line no-alert -- Native confirmation protects recovered user edits.
				window.confirm(
					'You have unsaved Link Page changes. Restore them?'
				)
			) {
				if ( isCurrentRequest( token ) ) {
					const restored = JSON.parse( saved );
					setDraft( restored.draft );
					dirtyAreasRef.current = new Set(
						restored.dirtyAreas || []
					);
					revisionRef.current = 1;
					notifyDirty( true );
					setPhase( 'ready' );
				}
				return;
			}
			accept( await adapter.read( requestedIdentity ), token );
		} catch ( error ) {
			if ( ! isCurrentRequest( token ) ) {
				return;
			}
			if ( error?.data?.status === 404 ) {
				notifyDirty( false );
				setPhase( 'absent' );
			} else {
				setMessage(
					error?.message || 'Link Page management could not load.'
				);
				setPhase( 'error' );
			}
		}
	};
	useEffect( () => {
		identityRef.current = identityId;
		load( identityId );
		return () => {
			identityRef.current = null;
		};
		// `load` intentionally binds this effect to the selected identity generation.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ identityId ] );
	useEffect( () => {
		const warn = ( event ) => {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );
	const change = ( patch, area = 'page' ) =>
		setDraft( ( value ) => {
			if ( ! value || savingRef.current || uploadingRef.current ) {
				return value;
			}
			const next =
				'function' === typeof patch
					? patch( value )
					: { ...value, ...patch };
			++revisionRef.current;
			dirtyAreasRef.current.add( area );
			notifyDirty( true );
			window.sessionStorage.setItem(
				storageKey( configuration.adapter, identityRef.current ),
				JSON.stringify( {
					draft: next,
					dirtyAreas: [ ...dirtyAreasRef.current ],
				} )
			);
			return next;
		} );
	const save = async ( event ) => {
		event?.preventDefault();
		if ( ! draft || savingRef.current || uploadingRef.current ) {
			return;
		}
		const validationError = validateDraft( draft, limits );
		if ( ! formRef.current?.checkValidity() || validationError ) {
			formRef.current?.reportValidity();
			setMessage(
				validationError || 'Complete all required Link Page fields.'
			);
			return;
		}
		const token = beginRequest();
		const revision = revisionRef.current;
		const payload = draft;
		savingRef.current = true;
		setPhase( 'saving' );
		setMessage( '' );
		try {
			const result = await adapter.save( token.id, payload, {
				dirtyAreas: [ ...dirtyAreasRef.current ],
			} );
			if ( revision === revisionRef.current && accept( result, token ) ) {
				setMessage( 'Saved!' );
			}
		} catch ( error ) {
			if ( isCurrentRequest( token ) ) {
				savingRef.current = false;
				setMessage( error?.message || 'Changes could not be saved.' );
				setPhase( 'ready' );
			}
		}
	};
	const provision = async () => {
		if (
			! adapter?.provision ||
			savingRef.current ||
			uploadingRef.current
		) {
			return;
		}
		const token = beginRequest();
		savingRef.current = true;
		setPhase( 'saving' );
		try {
			if ( accept( await adapter.provision( token.id ), token ) ) {
				setMessage( 'Link Page created.' );
			}
		} catch ( error ) {
			if ( isCurrentRequest( token ) ) {
				savingRef.current = false;
				setMessage(
					error?.message || 'Link Page could not be created.'
				);
				setPhase( 'absent' );
			}
		}
	};
	const runUpload = async ( type, file, applyResult, area = 'page' ) => {
		if ( ! adapter?.upload || savingRef.current || uploadingRef.current ) {
			return;
		}
		const token = beginRequest();
		uploadingRef.current = true;
		setPhase( 'uploading' );
		setMessage( 'Uploading...' );
		try {
			const result = await adapter.upload( type, token.id, file );
			if ( isCurrentRequest( token ) ) {
				uploadingRef.current = false;
				change(
					( currentDraft ) => applyResult( currentDraft, result ),
					area
				);
				setPhase( 'ready' );
				setMessage( '' );
			}
		} catch ( error ) {
			if ( isCurrentRequest( token ) ) {
				uploadingRef.current = false;
				setPhase( 'ready' );
				setMessage( error?.message || 'Upload failed.' );
			}
		}
	};
	const switchIdentity = ( nextIdentity ) => {
		if (
			savingRef.current ||
			uploadingRef.current ||
			String( nextIdentity ) === String( identityRef.current )
		) {
			return;
		}
		if (
			dirty &&
			// eslint-disable-next-line no-alert -- Native confirmation protects unsaved identity-scoped edits.
			! window.confirm( 'Discard unsaved changes and switch?' )
		) {
			return;
		}
		window.sessionStorage.removeItem(
			storageKey( configuration.adapter, identityRef.current )
		);
		++requestRef.current;
		identityRef.current = nextIdentity;
		revisionRef.current = 0;
		dirtyAreasRef.current = new Set();
		notifyDirty( false );
		setDraft( null );
		setMessage( '' );
		setIdentityId( nextIdentity );
	};
	const openQrCode = async () => {
		if (
			! adapter?.qrCode ||
			! draft?.page.publicUrl ||
			savingRef.current ||
			uploadingRef.current
		) {
			return;
		}
		const token = beginRequest();
		setQrError( '' );
		setQrCode( 'loading' );
		try {
			const url = await adapter.qrCode( draft.page.publicUrl, 300 );
			if ( isCurrentRequest( token ) ) {
				setQrCode( url );
			}
		} catch ( error ) {
			if ( isCurrentRequest( token ) ) {
				setQrCode( 'error' );
				setQrError( error?.message || 'QR Code generation failed.' );
			}
		}
	};
	if ( ! adapter ) {
		return (
			<div className="notice notice-warning">
				<p>Link Page editor runtime is unavailable.</p>
			</div>
		);
	}
	if ( hasInvalidPanel ) {
		return (
			<div className="notice notice-warning" role="alert">
				<p>Link Page editor extension configuration is invalid.</p>
			</div>
		);
	}
	if ( phase === 'loading' ) {
		return (
			<div className="ec-editor-loading" role="status">
				Loading editor...
			</div>
		);
	}
	if ( phase === 'absent' ) {
		return (
			<div className="ec-lpe-empty">
				<p>This identity does not have a Link Page yet.</p>
				{ adapter.provision && (
					<button className="button-1" onClick={ provision }>
						Create Link Page
					</button>
				) }
			</div>
		);
	}
	if ( phase === 'unavailable' ) {
		return (
			<div className="notice notice-warning">
				<p>Link Page management is unavailable.</p>
			</div>
		);
	}
	if ( phase === 'error' ) {
		return (
			<div className="notice notice-error" role="alert">
				<p>{ message }</p>
				<button className="button-2" onClick={ () => load() }>
					Retry
				</button>
			</div>
		);
	}
	if ( ! draft ) {
		return null;
	}
	let saveLabel = 'Saved';
	if ( 'saving' === phase ) {
		saveLabel = 'Saving...';
	} else if ( dirty ) {
		saveLabel = 'Save changes';
	}
	return (
		<form
			ref={ formRef }
			className="ec-editor"
			onSubmit={ save }
			noValidate={ false }
		>
			<header className="ec-editor__header">
				<div>
					{ draft.page.publicUrl && (
						<>
							<a
								href={ draft.page.publicUrl }
								target="_blank"
								rel="noreferrer"
							>
								{ draft.page.publicUrl }
							</a>
							{ adapter.qrCode && (
								<button
									type="button"
									className="button-2 button-small"
									ref={ qrButtonRef }
									disabled={ isBusy }
									onClick={ openQrCode }
								>
									QR Code
								</button>
							) }
						</>
					) }
				</div>
				<div className="ec-editor__actions">
					{ identities.length > 1 && (
						<select
							aria-label="Link Page identity"
							value={ identityId }
							disabled={ isBusy }
							onChange={ ( event ) =>
								switchIdentity( event.target.value )
							}
						>
							{ identities.map( ( item ) => (
								<option key={ item.id } value={ item.id }>
									{ item.label || item.name }
								</option>
							) ) }
						</select>
					) }
					{ message && <span role="status">{ message }</span> }
					<button
						type="submit"
						className="button-1"
						disabled={ isBusy || ! dirty }
					>
						{ saveLabel }
					</button>
				</div>
			</header>
			<fieldset className="ec-editor__controls" disabled={ isBusy }>
				<div className="ec-editor__body">
					<div className="ec-editor__sidebar">
						<div
							className="ec-lpe-tabs"
							role="tablist"
							aria-label="Editor sections"
						>
							{ tabs.map( ( tab, index ) => (
								<button
									type="button"
									key={ tab }
									id={ `${ instanceRef.current }-tab-${ tab }` }
									role="tab"
									aria-selected={ active === tab }
									aria-controls={ `${ instanceRef.current }-panel-${ tab }` }
									tabIndex={ active === tab ? 0 : -1 }
									className={
										active === tab ? 'is-active' : ''
									}
									onClick={ () => setActive( tab ) }
									onKeyDown={ ( event ) => {
										let nextIndex = index;
										if ( 'ArrowRight' === event.key ) {
											nextIndex =
												( index + 1 ) % tabs.length;
										} else if (
											'ArrowLeft' === event.key
										) {
											nextIndex =
												( index - 1 + tabs.length ) %
												tabs.length;
										} else if ( 'Home' === event.key ) {
											nextIndex = 0;
										} else if ( 'End' === event.key ) {
											nextIndex = tabs.length - 1;
										} else {
											return;
										}
										event.preventDefault();
										setActive( tabs[ nextIndex ] );
										event.currentTarget.parentElement
											.querySelectorAll( '[role="tab"]' )
											[ nextIndex ]?.focus();
									} }
								>
									{ panels.find( ( item ) => item.id === tab )
										?.label ||
										tab[ 0 ].toUpperCase() +
											tab.slice( 1 ) }
								</button>
							) ) }
						</div>
						<div
							className="ec-lpe-panel"
							id={ `${ instanceRef.current }-panel-${ active }` }
							role="tabpanel"
							aria-labelledby={ `${ instanceRef.current }-tab-${ active }` }
							tabIndex="0"
						>
							{ active === 'info' && (
								<div className="ec-tab">
									{ capabilities.identity && (
										<Field label="Display Name">
											<input
												value={ draft.identity.name }
												maxLength={
													limits.displayNameLength
												}
												onChange={ ( event ) =>
													change(
														{
															identity: {
																...draft.identity,
																name: event
																	.target
																	.value,
															},
														},
														'identity'
													)
												}
											/>
										</Field>
									) }
									{ capabilities.bio && (
										<Field label="Link Page Bio">
											<textarea
												rows="4"
												value={ draft.page.bio }
												maxLength={ limits.bioLength }
												onChange={ ( event ) =>
													change(
														{
															page: {
																...draft.page,
																bio: event
																	.target
																	.value,
															},
														},
														'bio'
													)
												}
											/>
										</Field>
									) }
									{ adapter.infoPanel?.( {
										draft,
										change,
										identityId,
										runUpload,
									} ) }
								</div>
							) }
							{ active === 'links' && (
								<LinksPanel
									draft={ draft }
									change={ ( patch ) =>
										change( patch, 'links' )
									}
									limits={ limits }
								/>
							) }
							{ active === 'socials' && (
								<div className="ec-tab">
									<Field label="Social Icon Position">
										<select
											value={
												draft.page.settings
													.social_icons_position ||
												'above'
											}
											onChange={ ( event ) =>
												change(
													{
														page: {
															...draft.page,
															settings: {
																...draft.page
																	.settings,
																social_icons_position:
																	event.target
																		.value,
															},
														},
													},
													'settings'
												)
											}
										>
											<option value="above">
												Above Links
											</option>
											<option value="below">
												Below Links
											</option>
										</select>
									</Field>
									<p>
										Social links are managed by the owning
										platform.
									</p>
									{ adapter.socialsPanel?.( {
										draft,
										change: ( patch ) =>
											change( patch, 'socials' ),
										identityId,
										configuration,
									} ) }
								</div>
							) }
							{ active === 'customize' && (
								<CustomizePanel
									draft={ draft }
									change={ change }
									adapter={ adapter }
									identityId={ identityId }
									runUpload={ runUpload }
									fonts={ configuration.fonts || [] }
								/>
							) }
							{ active === 'advanced' && (
								<AdvancedPanel
									draft={ draft }
									change={ change }
									subscriptions={ capabilities.subscriptions }
								/>
							) }
							{ activePanel?.render( {
								draft,
								identityId,
								change: ( patch ) =>
									change( patch, activePanel.area ),
								runUpload: ( type, file, applyResult ) =>
									runUpload(
										type,
										file,
										applyResult,
										activePanel.area
									),
							} ) }
						</div>
					</div>
					<aside className="ec-editor__preview-region">
						<h2>Preview</h2>
						<p>Live preview of your public Link Page.</p>
						<Preview
							draft={ draft }
							fonts={ configuration.fonts || [] }
							localFontsCss={ configuration.localFontsCss || '' }
							instanceId={ instanceRef.current }
							subscriptions={ capabilities.subscriptions }
						/>
					</aside>
				</div>
			</fieldset>
			{ qrCode && (
				<QrModal
					url={ qrCode }
					error={ qrError }
					onClose={ () => {
						++requestRef.current;
						setQrCode( '' );
						setQrError( '' );
					} }
					restoreFocus={ qrButtonRef.current }
					titleId={ `${ instanceRef.current }-qr-title` }
				/>
			) }
		</form>
	);
}

export function AdapterBoundary( { configuration } ) {
	const [ adapter, setAdapter ] = useState(
		() => window.ecLinkPageEditorAdapters?.[ configuration.adapter ] || null
	);
	const [ expired, setExpired ] = useState( false );
	useEffect( () => {
		if ( adapter ) {
			return undefined;
		}
		const onRegistered = ( event ) => {
			if ( event.detail?.name === configuration.adapter ) {
				setAdapter(
					window.ecLinkPageEditorAdapters?.[
						configuration.adapter
					] || null
				);
			}
		};
		document.addEventListener(
			'ec-link-page-editor-adapter-registered',
			onRegistered
		);
		const timeout = window.setTimeout(
			() => setExpired( true ),
			Math.max( 100, Number( configuration.adapterTimeout ) || 3000 )
		);
		return () => {
			document.removeEventListener(
				'ec-link-page-editor-adapter-registered',
				onRegistered
			);
			window.clearTimeout( timeout );
		};
	}, [ adapter, configuration.adapter, configuration.adapterTimeout ] );
	if ( adapter ) {
		return <Editor configuration={ configuration } adapter={ adapter } />;
	}
	return (
		<div
			className={ `notice notice-${ expired ? 'warning' : 'info' }` }
			role="status"
		>
			<p>
				{ expired
					? 'Link Page editor adapter is unavailable.'
					: 'Loading Link Page editor...' }
			</p>
		</div>
	);
}

export const registerAdapter = ( name, adapter ) => {
	if ( ! name || ! adapter ) {
		return false;
	}
	window.ecLinkPageEditorAdapters = window.ecLinkPageEditorAdapters || {};
	window.ecLinkPageEditorAdapters[ name ] = adapter;
	document.dispatchEvent(
		new CustomEvent( 'ec-link-page-editor-adapter-registered', {
			detail: { name },
		} )
	);
	return true;
};

export const mount = ( target, configuration ) => {
	if ( ! target ) {
		return null;
	}
	const root = createRoot( target );
	root.render( <AdapterBoundary configuration={ configuration } /> );
	return () => root.unmount();
};

window.ecLinkPageEditorAdapters = window.ecLinkPageEditorAdapters || {};
window.ExtraChillLinkPageEditor = { mount, normalizeDocument, registerAdapter };
( window.ecLinkPageEditorPendingAdapters || [] ).forEach(
	( [ name, adapter ] ) => registerAdapter( name, adapter )
);
window.ecLinkPageEditorPendingAdapters = [];

const boot = () =>
	document
		.querySelectorAll( '[data-link-page-editor-config]' )
		.forEach( ( node ) => {
			if ( node.dataset.mounted ) {
				return;
			}
			const target = document.getElementById(
				node.dataset.linkPageEditorConfig
			);
			if ( target ) {
				node.dataset.mounted = 'true';
				mount( target, JSON.parse( node.textContent ) );
			}
		} );
if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}

/**
 * TabLinks Component
 *
 * Sections and links management with drag-and-drop.
 */

import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useEditor } from '../../context/EditorContext';
import DraggableList from '../shared/DraggableList';

const createTempId = (prefix) =>
	`temp-${ prefix }-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2, 8 ) }`;

export default function TabLinks() {
	const { links, updateLinks, settings } = useEditor();
	const [ expandedSections, setExpandedSections ] = useState( {} );
	const [ expirationModal, setExpirationModal ] = useState( null );

	const linkExpirationEnabled = settings?.link_expiration_enabled || false;

	const handleSectionReorder = useCallback(
		( newOrder ) => {
			updateLinks( newOrder );
		},
		[ updateLinks ]
	);

	const handleLinkReorder = useCallback(
		( sectionIndex, newLinks ) => {
			const updated = [ ...links ];
			updated[ sectionIndex ] = {
				...updated[ sectionIndex ],
				links: newLinks,
			};
			updateLinks( updated );
		},
		[ links, updateLinks ]
	);

	const addSection = useCallback( () => {
		const newSection = {
			id: createTempId( 'section' ),
			section_title: '',
			links: [],
		};
		updateLinks( [ ...links, newSection ] );
	}, [ links, updateLinks ] );

	const removeSection = useCallback(
		( sectionIndex ) => {
			const updated = links.filter( ( _, i ) => i !== sectionIndex );
			updateLinks( updated );
		},
		[ links, updateLinks ]
	);

	const updateSectionTitle = useCallback(
		( sectionIndex, section_title ) => {
			const updated = [ ...links ];
			updated[ sectionIndex ] = { ...updated[ sectionIndex ], section_title };
			updateLinks( updated );
		},
		[ links, updateLinks ]
	);

	const addLink = useCallback(
		( sectionIndex ) => {
			const newLink = {
				id: createTempId( 'link' ),
				link_text: '',
				link_url: '',
				expires_at: '',
			};
			const updated = [ ...links ];
			updated[ sectionIndex ] = {
				...updated[ sectionIndex ],
				links: [ ...( updated[ sectionIndex ].links || [] ), newLink ],
			};
			updateLinks( updated );
		},
		[ links, updateLinks ]
	);

	const removeLink = useCallback(
		( sectionIndex, linkIndex ) => {
			const updated = [ ...links ];
			updated[ sectionIndex ] = {
				...updated[ sectionIndex ],
				links: updated[ sectionIndex ].links.filter(
					( _, i ) => i !== linkIndex
				),
			};
			updateLinks( updated );
		},
		[ links, updateLinks ]
	);

	const updateLink = useCallback(
		( sectionIndex, linkIndex, field, value ) => {
			const updated = [ ...links ];
			const linksCopy = [ ...updated[ sectionIndex ].links ];
			linksCopy[ linkIndex ] = { ...linksCopy[ linkIndex ], [ field ]: value };
			updated[ sectionIndex ] = { ...updated[ sectionIndex ], links: linksCopy };
			updateLinks( updated );
		},
		[ links, updateLinks ]
	);

	const toggleSection = useCallback( ( sectionId ) => {
		setExpandedSections( ( prev ) => ( {
			...prev,
			[ sectionId ]: ! prev[ sectionId ],
		} ) );
	}, [] );

	const openExpirationModal = useCallback( ( sectionIndex, linkIndex, currentValue ) => {
		setExpirationModal( { sectionIndex, linkIndex, value: currentValue || '' } );
	}, [] );

	const closeExpirationModal = useCallback( () => {
		setExpirationModal( null );
	}, [] );

	const saveExpiration = useCallback( () => {
		if ( expirationModal ) {
			updateLink( expirationModal.sectionIndex, expirationModal.linkIndex, 'expires_at', expirationModal.value );
			setExpirationModal( null );
		}
	}, [ expirationModal, updateLink ] );

	const clearExpiration = useCallback( () => {
		if ( expirationModal ) {
			updateLink( expirationModal.sectionIndex, expirationModal.linkIndex, 'expires_at', '' );
			setExpirationModal( null );
		}
	}, [ expirationModal, updateLink ] );

	const renderLink = ( link, linkIndex, sectionIndex ) => {
		const hasExpiration = link.expires_at && link.expires_at.length > 0;
		const linkKey = link.id || `link-${ sectionIndex }-${ linkIndex }`;

		return (
			<div key={ linkKey } className="ec-link-item">
				<div className="ec-link-item__drag-handle">
					<span className="dashicons dashicons-menu"></span>
				</div>
				<div className="ec-link-item__fields">
					<input
						type="text"
						className="ec-link-item__title"
						value={ link.link_text || '' }
						onChange={ ( e ) =>
							updateLink( sectionIndex, linkIndex, 'link_text', e.target.value )
						}
						placeholder={ __( 'Link title', 'extrachill-artist-platform' ) }
					/>
					<input
						type="url"
						className="ec-link-item__url"
						value={ link.link_url || '' }
						onChange={ ( e ) =>
							updateLink( sectionIndex, linkIndex, 'link_url', e.target.value )
						}
						placeholder={ __( 'https://...', 'extrachill-artist-platform' ) }
					/>
				</div>
				{ linkExpirationEnabled && (
					<button
						type="button"
						className={ `ec-link-item__expiration${ hasExpiration ? ' ec-link-item__expiration--active' : '' }` }
						onClick={ () => openExpirationModal( sectionIndex, linkIndex, link.expires_at ) }
						title={ hasExpiration
							? __( 'Expires: ', 'extrachill-artist-platform' ) + link.expires_at
							: __( 'Set expiration', 'extrachill-artist-platform' )
						}
					>
						<span className="dashicons dashicons-clock"></span>
					</button>
				) }
				<button
					type="button"
					className="ec-link-item__remove"
					onClick={ () => removeLink( sectionIndex, linkIndex ) }
					title={ __( 'Remove link', 'extrachill-artist-platform' ) }
				>
					<span className="dashicons dashicons-trash"></span>
				</button>
			</div>
		);
	};

	const renderSection = ( section, sectionIndex ) => {
		const sectionId = section.id || `section-${ sectionIndex }`;
		const isExpanded = expandedSections[ sectionId ] !== false;

		return (
			<div key={ sectionId } className="ec-section" data-section-id={ sectionId }>
				<div className="ec-section__header">
					<span className="ec-section__drag-handle">
						<span className="dashicons dashicons-menu"></span>
					</span>
					<input
						type="text"
						className="ec-section__title-input"
						value={ section.section_title || '' }
						onChange={ ( e ) =>
							updateSectionTitle( sectionIndex, e.target.value )
						}
						placeholder={ __( 'Section title (optional)', 'extrachill-artist-platform' ) }
					/>
					<button
						type="button"
						className="ec-section__toggle"
						onClick={ () => toggleSection( sectionId ) }
					>
						<span
							className={ `dashicons dashicons-arrow-${
								isExpanded ? 'up' : 'down'
							}-alt2` }
						></span>
					</button>
					<button
						type="button"
						className="ec-section__remove"
						onClick={ () => removeSection( sectionIndex ) }
					>
						<span className="dashicons dashicons-trash"></span>
					</button>
				</div>

				{ isExpanded && (
					<div className="ec-section__content">
						<DraggableList
							items={ section.links || [] }
							onReorder={ ( newLinks ) =>
								handleLinkReorder( sectionIndex, newLinks )
							}
							renderItem={ ( link, linkIndex ) =>
								renderLink( link, linkIndex, sectionIndex )
							}
						/>
						<button
							type="button"
							className="ec-section__add-link"
							onClick={ () => addLink( sectionIndex ) }
						>
							<span className="dashicons dashicons-plus"></span>
							{ __( 'Add Link', 'extrachill-artist-platform' ) }
						</button>
					</div>
				) }
			</div>
		);
	};

	return (
		<div className="ec-tab ec-tab--links">
			<DraggableList
				items={ links }
				onReorder={ handleSectionReorder }
				renderItem={ renderSection }
			/>

			<button type="button" className="ec-add-section" onClick={ addSection }>
				<span className="dashicons dashicons-plus"></span>
				{ __( 'Add Section', 'extrachill-artist-platform' ) }
			</button>

			{ expirationModal && (
				<div className="ec-expiration-modal">
					<div className="ec-expiration-modal__inner">
						<h3 className="ec-expiration-modal__title">
							{ __( 'Set Link Expiration', 'extrachill-artist-platform' ) }
						</h3>
						<label className="ec-expiration-modal__label">
							{ __( 'Expiration Date/Time:', 'extrachill-artist-platform' ) }
							<input
								type="datetime-local"
								className="ec-expiration-modal__input"
								value={ expirationModal.value }
								onChange={ ( e ) =>
									setExpirationModal( { ...expirationModal, value: e.target.value } )
								}
							/>
						</label>
						<div className="ec-expiration-modal__actions">
							<button
								type="button"
								className="button-2 button-small"
								onClick={ saveExpiration }
							>
								{ __( 'Save', 'extrachill-artist-platform' ) }
							</button>
							<button
								type="button"
								className="button-danger button-small"
								onClick={ clearExpiration }
							>
								{ __( 'Clear', 'extrachill-artist-platform' ) }
							</button>
							<button
								type="button"
								className="button-3 button-small"
								onClick={ closeExpirationModal }
							>
								{ __( 'Cancel', 'extrachill-artist-platform' ) }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}

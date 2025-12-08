/**
 * TabSocials Component
 *
 * Social links management with drag-and-drop.
 */

import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useEditor } from '../../context/EditorContext';
import DraggableList from '../shared/DraggableList';

const createTempId = (prefix) =>
	`temp-${ prefix }-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2, 8 ) }`;

export default function TabSocials() {
	const { socials, socialTypes, updateSocials, settings, updateSettings } = useEditor();
	const [ showAddModal, setShowAddModal ] = useState( false );

	const handleReorder = useCallback(
		( newOrder ) => {
			updateSocials( newOrder );
		},
		[ updateSocials ]
	);

	const addSocial = useCallback(
		( type ) => {
			const newSocial = {
				id: createTempId( 'social' ),
				type,
				url: '',
			};
			updateSocials( [ ...socials, newSocial ] );
			setShowAddModal( false );
		},
		[ socials, updateSocials ]
	);

	const removeSocial = useCallback(
		( index ) => {
			const updated = socials.filter( ( _, i ) => i !== index );
			updateSocials( updated );
		},
		[ socials, updateSocials ]
	);

	const updateSocialUrl = useCallback(
		( index, url ) => {
			const updated = [ ...socials ];
			updated[ index ] = { ...updated[ index ], url };
			updateSocials( updated );
		},
		[ socials, updateSocials ]
	);

	const handlePositionChange = useCallback(
		( position ) => {
			updateSettings( { social_icons_position: position } );
		},
		[ updateSettings ]
	);

	const renderSocialItem = ( social, index ) => {
		const socialTypeInfo = socialTypes?.find( ( t ) => t.id === social.type );
		const label = socialTypeInfo?.label || social.type;
		const rowKey = social.id || `social-${ index }`;
		
		return (
			<div key={ rowKey } className="ec-social-item">
				<span className="ec-social-item__drag-handle">
					<span className="dashicons dashicons-menu"></span>
				</span>
				<span className={ `ec-social-item__icon ec-social-icon--${ social.type }` }></span>
				<input
					type="url"
					className="ec-social-item__url"
					value={ social.url || '' }
					onChange={ ( e ) => updateSocialUrl( index, e.target.value ) }
					placeholder={ `${ label } URL` }
				/>
				<button
					type="button"
					className="ec-social-item__remove"
					onClick={ () => removeSocial( index ) }
				>
					<span className="dashicons dashicons-trash"></span>
				</button>
			</div>
		);
	};

	const socialsPosition = settings?.social_icons_position || 'above';

	return (
		<div className="ec-tab ec-tab--socials">
			<div className="ec-field">
				<label className="ec-field__label">
					{ __( 'Position', 'extrachill-artist-platform' ) }
				</label>
				<div className="ec-radio-group">
					<label className="ec-radio">
						<input
							type="radio"
							name="social_icons_position"
							value="above"
							checked={ socialsPosition === 'above' }
							onChange={ () => handlePositionChange( 'above' ) }
						/>
						{ __( 'Above Links', 'extrachill-artist-platform' ) }
					</label>
					<label className="ec-radio">
						<input
							type="radio"
							name="social_icons_position"
							value="below"
							checked={ socialsPosition === 'below' }
							onChange={ () => handlePositionChange( 'below' ) }
						/>
						{ __( 'Below Links', 'extrachill-artist-platform' ) }
					</label>
				</div>
			</div>

			<div className="ec-socials-list">
				<DraggableList
					items={ socials }
					onReorder={ handleReorder }
					renderItem={ renderSocialItem }
				/>
			</div>

			<button
				type="button"
				className="ec-add-social"
				onClick={ () => setShowAddModal( true ) }
			>
				<span className="dashicons dashicons-plus"></span>
				{ __( 'Add Social Link', 'extrachill-artist-platform' ) }
			</button>

			{ showAddModal && (
				<div className="ec-modal-overlay" onClick={ () => setShowAddModal( false ) }>
					<div className="ec-modal" onClick={ ( e ) => e.stopPropagation() }>
						<div className="ec-modal__header">
							<h3>{ __( 'Add Social Link', 'extrachill-artist-platform' ) }</h3>
							<button
								type="button"
								className="ec-modal__close"
								onClick={ () => setShowAddModal( false ) }
							>
								<span className="dashicons dashicons-no-alt"></span>
							</button>
						</div>
						<div className="ec-modal__body">
							<div className="ec-social-type-grid">
								{ ( socialTypes || [] ).map( ( type ) => (
									<button
										key={ type.id }
										type="button"
										className="ec-social-type-button"
										onClick={ () => addSocial( type.id ) }
									>
										<span className={ `ec-social-icon ec-social-icon--${ type.id }` }></span>
										<span>{ type.label }</span>
									</button>
								) ) }
							</div>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}

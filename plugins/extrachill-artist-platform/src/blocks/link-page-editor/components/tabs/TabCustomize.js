/**
 * TabCustomize Component
 *
 * Background, colors, fonts, profile image, and button styling controls.
 * Matches feature parity with tab-customize.php from the old system.
 */

import { useCallback, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useEditor } from '../../context/EditorContext';
import ColorPicker from '../shared/ColorPicker';
import ImageUploader from '../shared/ImageUploader';

export default function TabCustomize() {
	const {
		artistId,
		cssVars,
		updateCssVars,
		settings,
		updateSettings,
		backgroundImageUrl,
		updateBackgroundImage,
		uploadMedia,
		removeMedia,
		isUploading,
		fonts,
	} = useEditor();

	const [ backgroundType, setBackgroundType ] = useState(
		cssVars?.[ '--link-page-background-type' ] || 'color'
	);

	// Sync backgroundType state when cssVars loads asynchronously
	useEffect( () => {
		const loadedType = cssVars?.[ '--link-page-background-type' ];
		if ( loadedType && loadedType !== backgroundType ) {
			setBackgroundType( loadedType );
		}
	}, [ cssVars?.[ '--link-page-background-type' ] ] );

	const handleCssVarChange = useCallback(
		( key, value ) => {
			updateCssVars( { [ key ]: value } );
		},
		[ updateCssVars ]
	);

	const handleSettingChange = useCallback(
		( key, value ) => {
			updateSettings( { [ key ]: value } );
		},
		[ updateSettings ]
	);

	const handleBackgroundTypeChange = useCallback(
		( type ) => {
			setBackgroundType( type );
			handleCssVarChange( '--link-page-background-type', type );
		},
		[ handleCssVarChange ]
	);

	const handleBackgroundUpload = useCallback(
		async ( file ) => {
			const result = await uploadMedia( 'link_page_background', artistId, file );
			if ( result?.id && result?.url ) {
				updateBackgroundImage( result.id, result.url );
			}
		},
		[ artistId, uploadMedia, updateBackgroundImage ]
	);

	const handleBackgroundRemove = useCallback( async () => {
		await removeMedia( 'link_page_background', artistId );
		updateBackgroundImage( null, null );
	}, [ artistId, removeMedia, updateBackgroundImage ] );

	const sliderToEm = ( sliderValue ) => {
		const minEm = 0.8;
		const maxEm = 3.5;
		return ( minEm + ( ( sliderValue / 100 ) * ( maxEm - minEm ) ) ).toFixed( 2 ) + 'em';
	};

	const emToSlider = ( emValue ) => {
		const minEm = 0.8;
		const maxEm = 3.5;
		const numValue = parseFloat( emValue ) || 2.1;
		return Math.round( ( ( numValue - minEm ) / ( maxEm - minEm ) ) * 100 );
	};

	const currentTitleFontSize = cssVars?.[ '--link-page-title-font-size' ] || '2.1em';
	const titleSizeSlider = emToSlider( currentTitleFontSize );

	const currentProfileSize = parseInt( cssVars?.[ '--link-page-profile-img-size' ] ) || 30;
	const currentButtonRadius = parseInt( cssVars?.[ '--link-page-button-radius' ] ) || 8;
	const currentProfileShape = settings?.profile_image_shape || 'circle';
	const overlayEnabled = cssVars?.overlay === '1' || cssVars?.overlay === undefined;

	return (
		<div className="ec-tab ec-tab--customize">
			{ /* Fonts Section */ }
			<div className="ec-customize-card">
				<h3 className="ec-tab__section-title">
					{ __( 'Fonts', 'extrachill-artist-platform' ) }
				</h3>

				<div className="ec-field">
					<label htmlFor="ec-title-font" className="ec-field__label">
						{ __( 'Title Font', 'extrachill-artist-platform' ) }
					</label>
					<select
						id="ec-title-font"
						className="ec-field__select"
						value={ cssVars?.[ '--link-page-title-font-family' ] || '' }
						onChange={ ( e ) =>
							handleCssVarChange( '--link-page-title-font-family', e.target.value )
						}
					>
						<option value="">{ __( 'Default', 'extrachill-artist-platform' ) }</option>
						{ ( fonts || [] ).map( ( font ) => (
							<option key={ font.value } value={ font.value }>
								{ font.label }
							</option>
						) ) }
					</select>
				</div>

				<div className="ec-field">
					<label htmlFor="ec-title-size" className="ec-field__label">
						{ __( 'Title Size', 'extrachill-artist-platform' ) }
					</label>
					<div className="ec-field__range-container">
						<input
							id="ec-title-size"
							type="range"
							min="0"
							max="100"
							value={ titleSizeSlider }
							onChange={ ( e ) =>
								handleCssVarChange(
									'--link-page-title-font-size',
									sliderToEm( parseInt( e.target.value ) )
								)
							}
						/>
						<span className="ec-field__range-value">{ titleSizeSlider }%</span>
					</div>
				</div>

				<div className="ec-field">
					<label htmlFor="ec-body-font" className="ec-field__label">
						{ __( 'Body Font', 'extrachill-artist-platform' ) }
					</label>
					<select
						id="ec-body-font"
						className="ec-field__select"
						value={ cssVars?.[ '--link-page-body-font-family' ] || '' }
						onChange={ ( e ) =>
							handleCssVarChange( '--link-page-body-font-family', e.target.value )
						}
					>
						<option value="">{ __( 'Default', 'extrachill-artist-platform' ) }</option>
						{ ( fonts || [] ).map( ( font ) => (
							<option key={ font.value } value={ font.value }>
								{ font.label }
							</option>
						) ) }
					</select>
				</div>
			</div>

			{ /* Profile Image Section */ }
			<div className="ec-customize-card">
				<h3 className="ec-tab__section-title">
					{ __( 'Profile Image', 'extrachill-artist-platform' ) }
				</h3>

				<div className="ec-field">
					<label className="ec-field__label">
						{ __( 'Profile Image Shape', 'extrachill-artist-platform' ) }
					</label>
					<div className="ec-radio-group">
						{ [ 'circle', 'square', 'rectangle' ].map( ( shape ) => (
							<label key={ shape } className="ec-radio">
								<input
									type="radio"
									name="profile_image_shape"
									value={ shape }
									checked={ currentProfileShape === shape }
									onChange={ () => handleSettingChange( 'profile_image_shape', shape ) }
								/>
								{ shape.charAt( 0 ).toUpperCase() + shape.slice( 1 ) }
							</label>
						) ) }
					</div>
				</div>

				<div className="ec-field">
					<label htmlFor="ec-profile-size" className="ec-field__label">
						{ __( 'Profile Image Size', 'extrachill-artist-platform' ) }
					</label>
					<div className="ec-field__range-container">
						<input
							id="ec-profile-size"
							type="range"
							min="1"
							max="100"
							value={ currentProfileSize }
							onChange={ ( e ) =>
								handleCssVarChange( '--link-page-profile-img-size', e.target.value + '%' )
							}
						/>
						<span className="ec-field__range-value">{ currentProfileSize }%</span>
					</div>
					<p className="ec-field__help">
						{ __( 'Adjust the profile image size (relative to the card width).', 'extrachill-artist-platform' ) }
					</p>
				</div>
			</div>

			{ /* Background Section */ }
			<div className="ec-customize-card">
				<h3 className="ec-tab__section-title">
					{ __( 'Background', 'extrachill-artist-platform' ) }
				</h3>

				<div className="ec-field">
					<label htmlFor="ec-bg-type" className="ec-field__label">
						{ __( 'Background Type', 'extrachill-artist-platform' ) }
					</label>
					<select
						id="ec-bg-type"
						className="ec-field__select"
						value={ backgroundType }
						onChange={ ( e ) => handleBackgroundTypeChange( e.target.value ) }
					>
						<option value="color">{ __( 'Solid Color', 'extrachill-artist-platform' ) }</option>
						<option value="gradient">{ __( 'Gradient', 'extrachill-artist-platform' ) }</option>
						<option value="image">{ __( 'Image', 'extrachill-artist-platform' ) }</option>
					</select>
				</div>

				{ backgroundType === 'color' && (
					<div className="ec-field">
						<label className="ec-field__label">
							{ __( 'Background Color', 'extrachill-artist-platform' ) }
						</label>
						<ColorPicker
							color={ cssVars?.[ '--link-page-background-color' ] || '#121212' }
							onChange={ ( color ) =>
								handleCssVarChange( '--link-page-background-color', color )
							}
						/>
					</div>
				) }

				{ backgroundType === 'gradient' && (
					<>
						<div className="ec-field">
							<label className="ec-field__label">
								{ __( 'Gradient Colors', 'extrachill-artist-platform' ) }
							</label>
							<div className="ec-gradient-colors">
								<ColorPicker
									color={ cssVars?.[ '--link-page-background-gradient-start' ] || '#0b5394' }
									onChange={ ( color ) =>
										handleCssVarChange( '--link-page-background-gradient-start', color )
									}
								/>
								<ColorPicker
									color={ cssVars?.[ '--link-page-background-gradient-end' ] || '#53940b' }
									onChange={ ( color ) =>
										handleCssVarChange( '--link-page-background-gradient-end', color )
									}
								/>
							</div>
						</div>
						<div className="ec-field">
							<label htmlFor="ec-gradient-dir" className="ec-field__label">
								{ __( 'Gradient Direction', 'extrachill-artist-platform' ) }
							</label>
							<select
								id="ec-gradient-dir"
								className="ec-field__select"
								value={ cssVars?.[ '--link-page-background-gradient-direction' ] || 'to right' }
								onChange={ ( e ) =>
									handleCssVarChange( '--link-page-background-gradient-direction', e.target.value )
								}
							>
								<option value="to right">→ { __( 'Left to Right', 'extrachill-artist-platform' ) }</option>
								<option value="to bottom">↓ { __( 'Top to Bottom', 'extrachill-artist-platform' ) }</option>
								<option value="135deg">↘ { __( 'Diagonal', 'extrachill-artist-platform' ) }</option>
							</select>
						</div>
					</>
				) }

				{ backgroundType === 'image' && (
					<div className="ec-field">
						<label className="ec-field__label">
							{ __( 'Background Image', 'extrachill-artist-platform' ) }
						</label>
						<ImageUploader
							imageUrl={ backgroundImageUrl }
							onUpload={ handleBackgroundUpload }
							onRemove={ handleBackgroundRemove }
							isUploading={ isUploading }
							accept="image/*"
						/>
						<p className="ec-field__help">
							{ __( 'Maximum file size: 5MB.', 'extrachill-artist-platform' ) }
						</p>
					</div>
				) }

				<div className="ec-field">
					<label className="ec-checkbox">
						<input
							type="checkbox"
							checked={ overlayEnabled }
							onChange={ ( e ) =>
								handleCssVarChange( 'overlay', e.target.checked ? '1' : '0' )
							}
						/>
						{ __( 'Overlay (Card Background & Shadow)', 'extrachill-artist-platform' ) }
					</label>
				</div>
			</div>

			{ /* Colors Section */ }
			<div className="ec-customize-card">
				<h3 className="ec-tab__section-title">
					{ __( 'Colors', 'extrachill-artist-platform' ) }
				</h3>

				<div className="ec-field">
					<label className="ec-field__label">
						{ __( 'Button Color', 'extrachill-artist-platform' ) }
					</label>
					<ColorPicker
						color={ cssVars?.[ '--link-page-button-bg-color' ] || '#0b5394' }
						onChange={ ( color ) =>
							handleCssVarChange( '--link-page-button-bg-color', color )
						}
					/>
				</div>

				<div className="ec-field">
					<label className="ec-field__label">
						{ __( 'Text Color', 'extrachill-artist-platform' ) }
					</label>
					<ColorPicker
						color={ cssVars?.[ '--link-page-text-color' ] || '#e5e5e5' }
						onChange={ ( color ) =>
							handleCssVarChange( '--link-page-text-color', color )
						}
					/>
				</div>

				<div className="ec-field">
					<label className="ec-field__label">
						{ __( 'Link Text Color', 'extrachill-artist-platform' ) }
					</label>
					<ColorPicker
						color={ cssVars?.[ '--link-page-link-text-color' ] || '#ffffff' }
						onChange={ ( color ) =>
							handleCssVarChange( '--link-page-link-text-color', color )
						}
					/>
				</div>

				<div className="ec-field">
					<label className="ec-field__label">
						{ __( 'Hover Color', 'extrachill-artist-platform' ) }
					</label>
					<ColorPicker
						color={ cssVars?.[ '--link-page-button-hover-bg-color' ] || '#53940b' }
						onChange={ ( color ) =>
							handleCssVarChange( '--link-page-button-hover-bg-color', color )
						}
					/>
				</div>

				<div className="ec-field">
					<label className="ec-field__label">
						{ __( 'Button Border Color', 'extrachill-artist-platform' ) }
					</label>
					<ColorPicker
						color={ cssVars?.[ '--link-page-button-border-color' ] || '#0b5394' }
						onChange={ ( color ) =>
							handleCssVarChange( '--link-page-button-border-color', color )
						}
					/>
				</div>
			</div>

			{ /* Buttons Section */ }
			<div className="ec-customize-card">
				<h3 className="ec-tab__section-title">
					{ __( 'Buttons', 'extrachill-artist-platform' ) }
				</h3>

				<div className="ec-field">
					<label htmlFor="ec-button-radius" className="ec-field__label">
						{ __( 'Button Radius', 'extrachill-artist-platform' ) }
					</label>
					<div className="ec-field__range-container">
						<input
							id="ec-button-radius"
							type="range"
							min="0"
							max="50"
							value={ currentButtonRadius }
							onChange={ ( e ) =>
								handleCssVarChange( '--link-page-button-radius', e.target.value + 'px' )
							}
						/>
						<span className="ec-field__range-value">{ currentButtonRadius }px</span>
					</div>
					<p className="ec-field__help">
						{ __( 'Adjust the button border radius from square (0px) to pill (50px).', 'extrachill-artist-platform' ) }
					</p>
				</div>
			</div>
		</div>
	);
}

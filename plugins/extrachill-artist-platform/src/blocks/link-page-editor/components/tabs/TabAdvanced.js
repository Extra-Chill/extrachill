/**
 * TabAdvanced Component
 *
 * Advanced settings matching canonical tab-advanced.php:
 * - General Settings: link expiration, redirect, YouTube embed
 * - Subscription Settings: display mode, description
 * - Tracking Pixels: Meta Pixel ID, Google Tag ID
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FieldGroup, Panel, PanelHeader } from '@extrachill/components';
import { useEditor } from '../../context/EditorContext';

export default function TabAdvanced() {
	const { settings, updateSettings, links, artist } = useEditor();

	const handleChange = useCallback(
		( field, value ) => {
			updateSettings( { [ field ]: value } );
		},
		[ updateSettings ]
	);

	// Flatten all links from sections for redirect dropdown
	const allLinks = useMemo( () => {
		if ( ! Array.isArray( links ) ) {
			return [];
		}
		const flatLinks = [];
		links.forEach( ( section ) => {
			if ( Array.isArray( section.links ) ) {
				section.links.forEach( ( link ) => {
					if ( link.link_url && link.link_text ) {
						flatLinks.push( {
							url: link.link_url,
							text: link.link_text,
						} );
					}
				} );
			}
		} );
		return flatLinks;
	}, [ links ] );

	// YouTube embed: stored as youtube_embed_enabled (true = enabled)
	// Checkbox label is "Disable" so we invert the value
	const isYoutubeDisabled = settings?.youtube_embed_enabled === false;

	// Default subscribe description uses artist name
	const artistName = artist?.name || __( 'this artist', 'extrachill-artist-platform' );
	const defaultSubscribeDescription = `Enter your email address to receive occasional news and updates from ${ artistName }.`;
	const subscribeDescription = settings?.subscribe_description ?? defaultSubscribeDescription;

	return (
		<div className="ec-tab ec-tab--advanced">
			{ /* General Settings */ }
			<Panel compact>
				<PanelHeader title={ __( 'General Settings', 'extrachill-artist-platform' ) } />

				<FieldGroup>
				<label className="ec-checkbox">
					<input
						type="checkbox"
						checked={ settings?.link_expiration_enabled || false }
						onChange={ ( e ) =>
							handleChange( 'link_expiration_enabled', e.target.checked )
						}
					/>
					{ __( 'Enable Link Expiration Dates', 'extrachill-artist-platform' ) }
				</label>
				</FieldGroup>

				<FieldGroup>
				<label className="ec-checkbox">
					<input
						type="checkbox"
						checked={ settings?.redirect_enabled || false }
						onChange={ ( e ) =>
							handleChange( 'redirect_enabled', e.target.checked )
						}
					/>
					{ __( 'Enable Temporary Redirect', 'extrachill-artist-platform' ) }
				</label>
				</FieldGroup>

			{ settings?.redirect_enabled && (
				<FieldGroup
					label={ __( 'Redirect To:', 'extrachill-artist-platform' ) }
					htmlFor="ec-redirect-target"
					help={ __( 'Select one of your existing links to redirect visitors to.', 'extrachill-artist-platform' ) }
					className="ec-field--indent"
				>
					<select
						id="ec-redirect-target"
						className="ec-field__select"
						value={ settings?.redirect_target_url || '' }
						onChange={ ( e ) =>
							handleChange( 'redirect_target_url', e.target.value )
						}
					>
						<option value="">
							{ __( '-- Select a Link --', 'extrachill-artist-platform' ) }
						</option>
						{ allLinks.map( ( link, index ) => (
							<option key={ index } value={ link.url }>
							{ link.text } ({ link.url })
							</option>
						) ) }
					</select>
				</FieldGroup>
			) }

				<FieldGroup>
				<label className="ec-checkbox">
					<input
						type="checkbox"
						checked={ isYoutubeDisabled }
						onChange={ ( e ) =>
							handleChange( 'youtube_embed_enabled', ! e.target.checked )
						}
					/>
					{ __( 'Disable Inline YouTube Video Player', 'extrachill-artist-platform' ) }
				</label>
				</FieldGroup>
			</Panel>

			{ /* Subscription Settings */ }
			<Panel compact>
				<PanelHeader title={ __( 'Subscription Settings', 'extrachill-artist-platform' ) } />

			<p className="ec-field__intro">
				{ __( 'Choose how the email subscription option is displayed on your public link page.', 'extrachill-artist-platform' ) }
			</p>

				<FieldGroup className="ec-field--radio-group">
				<label className="ec-radio">
					<input
						type="radio"
						name="subscribe_display_mode"
						value="icon_modal"
						checked={ ( settings?.subscribe_display_mode || 'icon_modal' ) === 'icon_modal' }
						onChange={ () => handleChange( 'subscribe_display_mode', 'icon_modal' ) }
					/>
					{ __( 'Show Subscribe Icon (opens modal)', 'extrachill-artist-platform' ) }
				</label>
				<label className="ec-radio">
					<input
						type="radio"
						name="subscribe_display_mode"
						value="inline_form"
						checked={ settings?.subscribe_display_mode === 'inline_form' }
						onChange={ () => handleChange( 'subscribe_display_mode', 'inline_form' ) }
					/>
					{ __( 'Show Inline Subscribe Form (below links)', 'extrachill-artist-platform' ) }
				</label>
				<label className="ec-radio">
					<input
						type="radio"
						name="subscribe_display_mode"
						value="disabled"
						checked={ settings?.subscribe_display_mode === 'disabled' }
						onChange={ () => handleChange( 'subscribe_display_mode', 'disabled' ) }
					/>
					{ __( 'Disable Subscription Feature', 'extrachill-artist-platform' ) }
				</label>
				</FieldGroup>

				<FieldGroup
					label={ __( 'Subscribe Form Description', 'extrachill-artist-platform' ) }
					htmlFor="ec-subscribe-description"
					help={ __( 'This text appears in the subscribe modal or inline form on your public link page.', 'extrachill-artist-platform' ) }
				>
				<textarea
					id="ec-subscribe-description"
					className="ec-field__textarea"
					rows="3"
					value={ subscribeDescription }
					onChange={ ( e ) =>
						handleChange( 'subscribe_description', e.target.value )
					}
				/>
				</FieldGroup>
			</Panel>

			{ /* Tracking Pixels */ }
			<Panel compact>
				<PanelHeader title={ __( 'Tracking Pixels', 'extrachill-artist-platform' ) } />

				<FieldGroup
					label={ __( 'Meta Pixel ID', 'extrachill-artist-platform' ) }
					htmlFor="ec-meta-pixel"
					help={ __( 'Enter your Meta (Facebook) Pixel ID to track page views and events.', 'extrachill-artist-platform' ) }
				>
				<input
					id="ec-meta-pixel"
					type="text"
					className="ec-field__input"
					value={ settings?.meta_pixel_id || '' }
					onChange={ ( e ) => handleChange( 'meta_pixel_id', e.target.value ) }
					placeholder="e.g., 123456789012345"
				/>
				</FieldGroup>

				<FieldGroup
					label={ __( 'Google Tag ID (GA4 / Ads)', 'extrachill-artist-platform' ) }
					htmlFor="ec-google-tag"
					help={ __( 'Enter your Google Tag ID for Google Analytics 4 or Google Ads. This enables tracking page views, events, and allows for targeted advertising campaigns.', 'extrachill-artist-platform' ) }
				>
				<input
					id="ec-google-tag"
					type="text"
					className="ec-field__input"
					value={ settings?.google_tag_id || '' }
					onChange={ ( e ) => handleChange( 'google_tag_id', e.target.value ) }
					placeholder="e.g., G-XXXXXXXXXX or AW-XXXXXXXXXX"
				/>
				</FieldGroup>
			</Panel>
		</div>
	);
}

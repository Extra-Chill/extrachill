/**
 * ConfigPanel Component
 *
 * SEO configuration settings with media picker and text inputs.
 */

import { useState, useCallback } from '@wordpress/element';
import { Button, TextControl, Notice } from '@wordpress/components';
import { getConfig, saveConfig } from '../api/client';

export default function ConfigPanel() {
	const config = getConfig();
	const initialConfig = config.configData || {};

	const [ defaultOgImageId, setDefaultOgImageId ] = useState(
		initialConfig.defaultOgImageId || 0
	);
	const [ defaultOgImageUrl, setDefaultOgImageUrl ] = useState(
		initialConfig.defaultOgImageUrl || ''
	);
	const [ indexNowKey, setIndexNowKey ] = useState(
		initialConfig.indexNowKey || ''
	);
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );

		try {
			const result = await saveConfig( {
				default_og_image_id: defaultOgImageId,
				indexnow_key: indexNowKey,
			} );

			setDefaultOgImageId( result.default_og_image_id || 0 );
			setDefaultOgImageUrl( result.default_og_image_url || '' );
			setIndexNowKey( result.indexnow_key || '' );

			setNotice( { status: 'success', message: 'Settings saved.' } );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error.message || 'Failed to save settings.',
			} );
		} finally {
			setSaving( false );
		}
	}, [ defaultOgImageId, indexNowKey ] );

	const openMediaLibrary = useCallback( () => {
		const frame = wp.media( {
			title: 'Select Default OG Image',
			button: { text: 'Select Image' },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			setDefaultOgImageId( attachment.id );
			setDefaultOgImageUrl( attachment.sizes?.medium?.url || attachment.url );
		} );

		frame.open();
	}, [] );

	const handleRemoveImage = useCallback( () => {
		setDefaultOgImageId( 0 );
		setDefaultOgImageUrl( '' );
	}, [] );

	return (
		<div className="extrachill-seo-config">
			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<table className="form-table">
				<tbody>
					<tr>
						<th scope="row">Default OG Image</th>
						<td>
							<div className="extrachill-seo-config__media">
								{ defaultOgImageUrl && (
									<img
										src={ defaultOgImageUrl }
										alt="Default OG"
										className="extrachill-seo-config__preview"
									/>
								) }
								<div className="extrachill-seo-config__buttons">
									<Button
										variant="secondary"
										onClick={ openMediaLibrary }
									>
										{ defaultOgImageUrl
											? 'Change Image'
											: 'Select Image' }
									</Button>
									{ defaultOgImageUrl && (
										<Button
											variant="tertiary"
											isDestructive
											onClick={ handleRemoveImage }
										>
											Remove
										</Button>
									) }
								</div>
								<p className="description">
									Fallback og:image when no featured image
									exists.
								</p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="indexnow-key">IndexNow Key</label>
						</th>
						<td>
							<TextControl
								id="indexnow-key"
								value={ indexNowKey }
								onChange={ setIndexNowKey }
								className="regular-text"
							/>
							<p className="description">
								When set, posts will ping IndexNow on
								publish/unpublish/delete. You must also host /
								{ '{key}' }.txt as a static file at the domain
								root.
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<p className="submit">
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving }
					onClick={ handleSave }
				>
					{ saving ? 'Saving...' : 'Save Settings' }
				</Button>
			</p>
		</div>
	);
}

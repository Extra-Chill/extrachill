import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';

export const studioClient = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

export const uploadStudioFile = async ( file ) => {
	const formData = new FormData();
	formData.append( 'file', file );

	return studioClient.socials.uploadCroppedMedia( formData );
};

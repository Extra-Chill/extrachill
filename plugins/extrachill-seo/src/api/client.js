/**
 * SEO API Client
 *
 * Delegates all calls to wp-native-client via WpApiFetchTransport.
 * All SEO + redirect functionality flows through client.executeUnchecked()
 * calls using the Abilities API.
 *
 * Exports match the original function names so dashboard components need zero changes.
 */

import apiFetch from '@wordpress/api-fetch';
import { WPNativeClient } from 'wp-native-client';
import { WpApiFetchTransport } from 'wp-native-client/wordpress';

const transport = new WpApiFetchTransport( apiFetch );
const client = new WPNativeClient( transport );

export const getConfig = () => window.ecSeoAdmin || {};

export const runAudit = ( mode ) =>
	client.executeUnchecked( 'extrachill/run-seo-audit', { mode } );

export const getAuditStatus = () =>
	client.executeUnchecked( 'extrachill/get-seo-results', {} );

export const continueAudit = () =>
	client.executeUnchecked( 'extrachill/run-seo-audit', { mode: 'batch' } );

export const getAuditDetails = ( category, page, perPage ) =>
	client.executeUnchecked( 'extrachill/get-seo-results', {
		check: category,
	} );

export const exportAuditDetails = ( category ) =>
	client.executeUnchecked( 'extrachill/get-seo-results', {
		check: category,
	} );

export const saveConfig = ( data ) =>
	client.executeUnchecked( 'extrachill/update-seo-config', data );

export default {
	getConfig,
	runAudit,
	getAuditStatus,
	continueAudit,
	getAuditDetails,
	exportAuditDetails,
	saveConfig,
};

/**
 * SEO API Client
 *
 * Delegates all calls to @extrachill/api-client via WpApiFetchTransport.
 * Exports match the original function names so dashboard components need zero changes.
 */

import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';

const transport = new WpApiFetchTransport( apiFetch );
const client = new ExtraChillClient( transport );

export const getConfig = () => window.ecSeoAdmin || {};

export const runAudit = ( mode ) => client.seo.startAudit( mode );
export const getAuditStatus = () => client.seo.getAuditStatus();
export const continueAudit = () => client.seo.continueAudit();
export const getAuditDetails = ( category, page, perPage ) =>
	client.seo.getAuditDetails( category, page, perPage );
export const exportAuditDetails = ( category ) =>
	client.seo.exportAuditDetails( category );
export const saveConfig = ( data ) => client.seo.updateConfig( data );

export default {
	getConfig,
	runAudit,
	getAuditStatus,
	continueAudit,
	getAuditDetails,
	exportAuditDetails,
	saveConfig,
};

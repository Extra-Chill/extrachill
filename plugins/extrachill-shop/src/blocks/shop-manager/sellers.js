/**
 * Seller reference helpers.
 */

export const SELLER_TYPE_LABELS = {
	artist: 'Artist',
	venue: 'Venue',
	promoter: 'Promoter',
};

export const sellerKey = ( seller ) =>
	seller ? `${ seller.type }:${ Number( seller.id ) }` : '';

export const sellerLabel = ( seller ) => {
	const type = SELLER_TYPE_LABELS[ seller?.type ] || seller?.type || '';
	const name = seller?.name || `#${ seller?.id }`;
	return `${ name } (${ type })`;
};

export const sellerUrl = ( pageUrl, seller ) => {
	const url = new URL(
		pageUrl || window.location.href,
		window.location.href
	);
	url.searchParams.set( 'seller', sellerKey( seller ) );
	return url.toString();
};

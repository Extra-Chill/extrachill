/**
 * Shop manager API.
 *
 * Every call is same-origin on the Shop site and names its seller. Shop
 * abilities run through core `/wp-abilities/v1`; image uploads and Stripe
 * Connect use the existing `extrachill/v1/shop/*` adapters.
 */

let config = { restUrl: '/wp-json/', nonce: '' };

export const configureApi = ( next ) => {
	config = { ...config, ...next };
};

const request = async ( path, { method = 'GET', body } = {} ) => {
	const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
	const headers = { 'X-WP-Nonce': config.nonce };
	if ( body !== undefined && ! isForm ) {
		headers[ 'Content-Type' ] = 'application/json';
	}

	const response = await fetch( config.restUrl + path, {
		method,
		credentials: 'same-origin',
		headers,
		body: body === undefined || isForm ? body : JSON.stringify( body ),
	} );

	let data = null;
	try {
		data = await response.json();
	} catch {
		data = null;
	}
	if ( ! response.ok ) {
		throw new Error( data?.message || 'Shop request failed.' );
	}
	return data;
};

/**
 * Encode ability input as `input[...]` query parameters for GET and DELETE.
 *
 * @param {Object}          input  Ability input.
 * @param {string}          prefix Parameter prefix.
 * @param {URLSearchParams} params Accumulated parameters.
 * @return {URLSearchParams} Query parameters.
 */
export const encodeInput = (
	input,
	prefix = 'input',
	params = new URLSearchParams()
) => {
	Object.entries( input || {} ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null ) {
			return;
		}
		const name = `${ prefix }[${ key }]`;
		if ( typeof value === 'object' ) {
			encodeInput( value, name, params );
		} else {
			params.append( name, String( value ) );
		}
	} );
	return params;
};

const abilityPath = ( name ) =>
	`wp-abilities/v1/abilities/extrachill/${ name }/run`;

const readAbility = ( name, input ) =>
	request( `${ abilityPath( name ) }?${ encodeInput( input ) }` );

const deleteAbility = ( name, input ) =>
	request( `${ abilityPath( name ) }?${ encodeInput( input ) }`, {
		method: 'DELETE',
	} );

const runAbility = ( name, input ) =>
	request( abilityPath( name ), { method: 'POST', body: { input } } );

const sellerRef = ( seller ) => ( {
	type: seller.type,
	id: Number( seller.id ),
} );

// ─── Seller selection ───────────────────────────────────────────────────────

export const selectSeller = ( seller ) =>
	runAbility( 'shop-select-seller', { seller: sellerRef( seller ) } );

// ─── Products ───────────────────────────────────────────────────────────────

export const listShopProducts = ( seller ) =>
	readAbility( 'shop-list-products', { seller: sellerRef( seller ) } );

export const createShopProduct = ( seller, data ) =>
	runAbility( 'shop-create-product', {
		...data,
		seller: sellerRef( seller ),
	} );

export const updateShopProduct = ( productId, data ) =>
	runAbility( 'shop-update-product', { ...data, id: Number( productId ) } );

export const deleteShopProduct = ( productId ) =>
	runAbility( 'shop-delete-product', { id: Number( productId ) } );

export const uploadShopProductImages = ( productId, files ) => {
	const body = new FormData();
	( files || [] ).forEach( ( file ) => body.append( 'files[]', file ) );
	return request(
		`extrachill/v1/shop/products/${ Number( productId ) }/images`,
		{
			method: 'POST',
			body,
		}
	);
};

export const deleteShopProductImage = ( productId, attachmentId ) =>
	deleteAbility( 'shop-delete-product-image', {
		id: Number( productId ),
		attachment_id: Number( attachmentId ),
	} );

// ─── Orders ─────────────────────────────────────────────────────────────────

export const listShopOrders = ( seller, status = 'all', page = 1 ) =>
	readAbility( 'shop-list-orders', {
		seller: sellerRef( seller ),
		status,
		page,
	} );

export const updateShopOrderStatus = (
	orderId,
	seller,
	status,
	trackingNumber = ''
) =>
	runAbility( 'shop-update-order-status', {
		id: Number( orderId ),
		seller: sellerRef( seller ),
		status,
		...( trackingNumber ? { tracking_number: trackingNumber } : {} ),
	} );

export const refundShopOrder = ( orderId, seller ) =>
	runAbility( 'shop-refund-order', {
		id: Number( orderId ),
		seller: sellerRef( seller ),
	} );

// ─── Shipping ───────────────────────────────────────────────────────────────

export const getShippingAddress = ( seller ) =>
	readAbility( 'shop-get-shipping-address', { seller: sellerRef( seller ) } );

export const updateShippingAddress = ( seller, address ) =>
	runAbility( 'shop-update-shipping-address', {
		...address,
		seller: sellerRef( seller ),
	} );

export const purchaseShippingLabel = ( orderId, seller ) =>
	runAbility( 'shop-create-shipping-label', {
		order_id: Number( orderId ),
		seller: sellerRef( seller ),
	} );

// ─── Payments (Stripe Connect) ──────────────────────────────────────────────

const stripeParams = ( seller ) => ( {
	seller_type: seller.type,
	seller_id: Number( seller.id ),
} );

export const getStripeConnectStatus = ( seller ) =>
	request(
		`extrachill/v1/shop/stripe-connect/status?${ new URLSearchParams( stripeParams( seller ) ) }`
	);

export const createStripeConnectOnboardingLink = ( seller ) =>
	request( 'extrachill/v1/shop/stripe-connect/onboarding-link', {
		method: 'POST',
		body: stripeParams( seller ),
	} );

export const createStripeConnectDashboardLink = ( seller ) =>
	request( 'extrachill/v1/shop/stripe-connect/dashboard-link', {
		method: 'POST',
		body: stripeParams( seller ),
	} );

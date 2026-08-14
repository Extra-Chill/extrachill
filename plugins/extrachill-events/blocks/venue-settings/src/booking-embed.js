export const normalizeBookingOrigin = ( value ) => {
	if ( value !== value.trim() || value.includes( '*' ) ) {
		return null;
	}
	try {
		const url = new URL( value );
		if (
			url.protocol !== 'https:' ||
			url.username ||
			url.password ||
			url.port ||
			url.pathname !== '/' ||
			url.search ||
			url.hash ||
			url.hostname === 'localhost' ||
			! url.hostname.includes( '.' ) ||
			/^[\d.]+$/.test( url.hostname )
		) {
			return null;
		}
		return `https://${ url.hostname.toLowerCase() }`;
	} catch {
		return null;
	}
};

export const bookingOriginFromWebsite = ( value ) => {
	try {
		const url = new URL( value );
		return normalizeBookingOrigin( url.origin );
	} catch {
		return null;
	}
};

const escapeAttribute = ( value ) =>
	String( value )
		.replaceAll( '&', '&amp;' )
		.replaceAll( '"', '&quot;' )
		.replaceAll( '<', '&lt;' )
		.replaceAll( '>', '&gt;' );

export const bookingButtonSnippet = ( bookingUrl, venueName ) =>
	`<a href="${ escapeAttribute( bookingUrl ) }">Book ${ escapeAttribute(
		venueName
	) }</a>`;

export const bookingEmbedSnippet = ( bookingUrl, venueName, parentOrigin ) => {
	const src = new URL( bookingUrl );
	src.hash = '';
	src.searchParams.set( 'booking-embed', '1' );
	src.searchParams.set( 'parent-origin', parentOrigin );
	const id = `extrachill-booking-${ parentOrigin.replace(
		/[^a-z0-9]+/gi,
		'-'
	) }`;
	const eventsOrigin = src.origin;
	const title = `Book ${ venueName }`;
	return `<iframe id="${ id }" src="${ escapeAttribute(
		src.toString()
	) }" title="${ escapeAttribute(
		title
	) }" loading="lazy" referrerpolicy="strict-origin" sandbox="allow-forms allow-scripts allow-same-origin" style="width:100%;min-height:720px;border:0" scrolling="no"><a href="${ escapeAttribute(
		bookingUrl
	) }">Open ${ escapeAttribute(
		title
	) } on Extra Chill</a></iframe><script>(function(){var f=document.getElementById('${ id }');window.addEventListener('message',function(e){var d=e.data;if(e.source!==f.contentWindow||e.origin!=='${ eventsOrigin }'||!d||d.type!=='extrachill:booking-height'||!Number.isInteger(d.height)||d.height<320||d.height>10000){return;}f.style.height=d.height+'px';});}());</script>`;
};

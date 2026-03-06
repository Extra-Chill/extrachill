import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { createElement, createRoot, render, useState } from '@wordpress/element';

const ROOT_SELECTOR = '[data-ec-studio-root]';

const mountComponent = ( container, component ) => {
	if ( ! container ) {
		return;
	}

	if ( typeof createRoot === 'function' ) {
		const root = createRoot( container );
		root.render( component );
		return;
	}

	if ( typeof render === 'function' ) {
		render( component, container );
	}
};

const OverviewPane = ( { context } ) => createElement(
	'div',
	{ className: 'ec-studio-pane ec-studio-pane--overview' },
	createElement(
		'div',
		{ className: 'ec-studio-pane__grid' },
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Welcome', 'extrachill-studio' ) ),
			createElement( 'h3', null, sprintf( __( 'Hey %s', 'extrachill-studio' ), context.userName || __( 'team member', 'extrachill-studio' ) ) ),
			createElement( 'p', null, __( 'Studio 0.1.0 is live as the internal shell for publishing workflows, caption drafting, and future AI-assisted team tools.', 'extrachill-studio' ) )
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Current site', 'extrachill-studio' ) ),
			createElement( 'h3', null, context.siteName || __( 'Extra Chill', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'This first release stays lightweight and aligned with the existing multisite theme system, team-member permissions, and shared tabs UI.', 'extrachill-studio' ) )
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'What comes next', 'extrachill-studio' ) ),
			createElement(
				'ul',
				null,
				createElement( 'li', null, __( 'Build on the QR generator with more real team tools.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Add Data Machine-backed caption and publishing jobs where async work makes sense.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Expand toward team-specific assistants and social workflows.', 'extrachill-studio' ) )
			)
		)
	)
);

const QrCodesPane = () => {
	const [ url, setUrl ] = useState( '' );
	const [ size, setSize ] = useState( '1000' );
	const [ imageUrl, setImageUrl ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );

	const generateQrCode = async () => {
		const trimmedUrl = url.trim();
		const parsedSize = Number.parseInt( size, 10 );

		if ( ! trimmedUrl ) {
			setError( __( 'Enter a URL to generate a QR code.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsLoading( true );
		setError( '' );
		setStatus( __( 'Generating QR code…', 'extrachill-studio' ) );

		try {
			const response = await apiFetch( {
				path: '/extrachill/v1/tools/qr-code',
				method: 'POST',
				data: {
					url: trimmedUrl,
					size: Number.isNaN( parsedSize ) ? 1000 : parsedSize,
				},
			} );

			setImageUrl( response.image_url || '' );
			setStatus( __( 'QR code ready to preview and download.', 'extrachill-studio' ) );
		} catch ( fetchError ) {
			setImageUrl( '' );
			setStatus( '' );
			setError( fetchError?.message || __( 'QR generation failed. Please try again.', 'extrachill-studio' ) );
		} finally {
			setIsLoading( false );
		}
	};

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--qr-codes' },
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'QR generator', 'extrachill-studio' ) ),
			createElement( 'h3', null, __( 'Generate a print-ready QR code from any URL', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'This uses the existing Extra Chill QR code tool through the current REST endpoint and ability.', 'extrachill-studio' ) ),
			createElement(
				'div',
				{ className: 'ec-studio-composer' },
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-qr-url' }, __( 'URL', 'extrachill-studio' ) ),
					createElement( 'input', {
						id: 'ec-studio-qr-url',
						type: 'url',
						value: url,
						onChange: ( event ) => setUrl( event.target.value ),
						placeholder: 'https://extrachill.com/',
						autoComplete: 'url',
					} )
				),
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-qr-size' }, __( 'Size', 'extrachill-studio' ) ),
					createElement( 'input', {
						id: 'ec-studio-qr-size',
						type: 'number',
						min: '100',
						max: '2000',
						step: '100',
						value: size,
						onChange: ( event ) => setSize( event.target.value ),
					} )
				),
				createElement(
					'div',
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium',
							onClick: generateQrCode,
							disabled: isLoading,
						},
						isLoading ? __( 'Generating…', 'extrachill-studio' ) : __( 'Generate QR Code', 'extrachill-studio' )
					),
					createElement( 'span', { className: 'ec-studio-composer__hint' }, __( 'Sizes from 100 to 2000 pixels are supported.', 'extrachill-studio' ) )
				)
			),
			error ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, error ) : null,
			! error && status ? createElement( 'p', { className: 'ec-studio-message ec-studio-message--success' }, status ) : null
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Preview', 'extrachill-studio' ) ),
			imageUrl
				? createElement(
					'div',
					{ className: 'ec-studio-qr-result' },
					createElement( 'img', {
						className: 'ec-studio-qr-result__image',
						alt: __( 'Generated QR code preview', 'extrachill-studio' ),
						src: imageUrl,
					} ),
					createElement(
						'a',
						{
							className: 'button-1 button-medium ec-studio-qr-result__download',
							href: imageUrl,
							download: 'extrachill-qr-code.png',
						},
						__( 'Download PNG', 'extrachill-studio' )
					)
				)
				: createElement( 'div', { className: 'ec-studio-preview' }, __( 'Your QR code preview will appear here after generation.', 'extrachill-studio' ) )
		)
	);
};

const PublishingPane = () => createElement(
	'div',
	{ className: 'ec-studio-pane ec-studio-pane--publishing' },
	createElement(
		'div',
		{ className: 'ec-studio-panel' },
		createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Publishing roadmap', 'extrachill-studio' ) ),
			createElement( 'h3', null, __( 'Feature path from QR utility to real workflow engine', 'extrachill-studio' ) ),
			createElement(
				'ul',
				{ className: 'ec-studio-roadmap' },
				createElement( 'li', null, createElement( 'span', null, __( 'QR code generation for flyers, signage, and print materials', 'extrachill-studio' ) ), createElement( 'span', { className: 'ec-studio-roadmap__status' }, __( 'Live', 'extrachill-studio' ) ) ),
				createElement( 'li', null, createElement( 'span', null, __( 'Caption generation with reusable prompts and site context', 'extrachill-studio' ) ), createElement( 'span', { className: 'ec-studio-roadmap__status' }, __( 'Next', 'extrachill-studio' ) ) ),
				createElement( 'li', null, createElement( 'span', null, __( 'Draft social publishing workflows for Instagram and related channels', 'extrachill-studio' ) ), createElement( 'span', { className: 'ec-studio-roadmap__status' }, __( 'Planned', 'extrachill-studio' ) ) ),
				createElement( 'li', null, createElement( 'span', null, __( 'Per-team-member assistants connected to WordPress tools and Data Machine jobs', 'extrachill-studio' ) ), createElement( 'span', { className: 'ec-studio-roadmap__status' }, __( 'Vision', 'extrachill-studio' ) ) )
			)
	)
);

const initRoot = ( root ) => {
	if ( ! root || root.dataset.ecStudioMounted === 'true' ) {
		return;
	}

	root.dataset.ecStudioMounted = 'true';

	const context = {
		userName: root.dataset.userName || '',
		siteName: root.dataset.siteName || '',
		siteUrl: root.dataset.siteUrl || '',
	};

	const mounts = root.querySelectorAll( '[data-ec-studio-pane]' );

	mounts.forEach( ( mount ) => {
		const pane = mount.dataset.ecStudioPane;

		if ( pane === 'overview' ) {
			mountComponent( mount, createElement( OverviewPane, { context } ) );
			return;
		}

		if ( pane === 'qr-codes' ) {
			mountComponent( mount, createElement( QrCodesPane ) );
			return;
		}

		if ( pane === 'publishing' ) {
			mountComponent( mount, createElement( PublishingPane ) );
		}
	} );
};

const init = () => {
	document.querySelectorAll( ROOT_SELECTOR ).forEach( initRoot );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}

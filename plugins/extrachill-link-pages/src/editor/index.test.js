/* global afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, jest */
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import {
	AdapterBoundary,
	cardBackgroundParts,
	cardBackgroundValue,
	Editor,
	exitLinksFor,
	Preview,
	registerAdapter,
} from './index';

describe( 'card background controls', () => {
	it( 'preserves color and opacity including zero across reloads', () => {
		for ( const opacity of [ 0, 0.4, 1 ] ) {
			const value = cardBackgroundValue( '#123456', opacity );
			expect( cardBackgroundParts( value ) ).toEqual( {
				color: '#123456',
				opacity,
			} );
		}
		expect( cardBackgroundParts( 'none' ).opacity ).toBe( 0 );
		expect( cardBackgroundParts( 'transparent' ).opacity ).toBe( 0 );
		expect( cardBackgroundParts( '#abc' ) ).toEqual( {
			color: '#aabbcc',
			opacity: 1,
		} );
	} );
} );

const deferred = () => {
	let resolve;
	let reject;
	const promise = new Promise( ( done, fail ) => {
		resolve = done;
		reject = fail;
	} );
	return { promise, resolve, reject };
};

const documentFor = ( id = 'a', overrides = {} ) => ( {
	identity: { id, name: `Identity ${ id }`, image_url: '' },
	link_page: {
		link_page_id: 10,
		bio: '',
		public_url: `https://extrachill.link/${ id }/`,
		link_sections: [
			{
				id: 'main',
				section_title: 'Main',
				links: [
					{
						id: 'website',
						link_text: 'Website',
						link_url: 'https://example.com/',
					},
				],
			},
		],
		css_vars: { '--link-page-background-type': 'color' },
		settings: {},
		background_image_id: 0,
		background_image_url: '',
		...overrides,
	},
	socials: [],
} );

const configuration = ( overrides = {} ) => ( {
	adapter: 'test-adapter',
	identities: [
		{ id: 'a', label: 'Identity A' },
		{ id: 'b', label: 'Identity B' },
	],
	initialIdentity: 'a',
	limits: {
		sections: 10,
		linksPerSection: 25,
		sectionTitleLength: 200,
		linkTextLength: 200,
		urlLength: 2048,
		bioLength: 5000,
		displayNameLength: 200,
	},
	...overrides,
} );

const flush = async () => {
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
};

const renderEditor = async ( config = configuration() ) => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => root.render( <Editor configuration={ config } /> ) );
	await flush();
	return { container, root };
};

const input = async ( control, value ) => {
	await act( async () => {
		Object.getOwnPropertyDescriptor(
			control.constructor.prototype,
			'value'
		).set.call( control, value );
		control.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
};

describe( 'portable editor behavior', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );
	afterAll( () => {
		delete global.IS_REACT_ACT_ENVIRONMENT;
	} );
	beforeEach( () => {
		document.body.innerHTML = '';
		sessionStorage.clear();
		window.confirm = jest.fn( () => true );
		window.ecLinkPageEditorAdapters = {};
	} );
	afterEach( () => {
		delete window.ecLinkPageEditorAdapters;
	} );

	it( 'previews background treatment and saves focal point and overlay alpha', async () => {
		const save = jest.fn( async () => documentFor() );
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async () =>
				documentFor( 'a', {
					css_vars: {
						'--link-page-background-type': 'image',
						'--link-page-image-position': 'left top',
						'--link-page-overlay-color': 'rgba(12, 34, 56, 0.25)',
					},
					background_image_url: 'https://example.com/photo.jpg',
				} )
			),
			save,
		};
		const { container, root } = await renderEditor();
		await act( async () =>
			container.querySelector( '[role="tab"][id$="-customize"]' ).click()
		);
		const control = ( text ) => {
			const label = Array.from(
				container.querySelectorAll( 'label' )
			).find( ( item ) => item.textContent === text );
			return document.getElementById( label.htmlFor );
		};
		expect( control( 'Overlay Color' ).value ).toBe( '#0c2238' );
		expect( control( 'Overlay Opacity' ).value ).toBe( '0.25' );
		await input( control( 'Background Blur' ), '12' );
		await input( control( 'Background Brightness' ), '80' );
		await input( control( 'Horizontal Focal Point' ), '27' );
		await input( control( 'Vertical Focal Point' ), '63' );
		await input( control( 'Overlay Opacity' ), '0' );
		const preview = container.querySelector(
			'.extrch-link-page-preview-container'
		);
		expect( preview.style.backgroundImage ).toBe( 'none' );
		expect(
			preview.style.getPropertyValue( '--link-page-image-position' )
		).toBe( '27% 63%' );
		expect(
			preview.style.getPropertyValue( '--link-page-overlay-color' )
		).toBe( 'rgba(12, 34, 56, 0)' );
		await act( async () =>
			container.querySelector( 'button[type="submit"]' ).click()
		);
		expect( save.mock.calls[ 0 ][ 1 ].page.styles ).toMatchObject( {
			'--link-page-image-blur': '12px',
			'--link-page-image-brightness': '80%',
			'--link-page-image-position': '27% 63%',
			'--link-page-overlay-color': 'rgba(12, 34, 56, 0)',
		} );
		await act( async () => root.unmount() );
	} );

	it( 'disables edits and identity switching until a save settles', async () => {
		const pending = deferred();
		const dirty = jest.fn();
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) => documentFor( id ) ),
			save: jest.fn( () => pending.promise ),
			onDirtyChange: dirty,
		};
		const { container, root } = await renderEditor();
		await input(
			container.querySelector( 'input[value="Identity a"]' ),
			'Changed'
		);
		await act( async () =>
			container.querySelector( 'form' ).requestSubmit()
		);
		expect(
			container.querySelector( '.ec-editor__controls' ).disabled
		).toBe( true );
		expect(
			container.querySelector( '[aria-label="Link Page identity"]' )
				.disabled
		).toBe( true );
		await input(
			container.querySelector( 'input[value="Changed"]' ),
			'Late edit'
		);
		expect(
			container.querySelector( 'input[value="Changed"]' )
		).not.toBeNull();
		expect(
			window.ecLinkPageEditorAdapters[ 'test-adapter' ].save
		).toHaveBeenCalledWith(
			'a',
			expect.objectContaining( {
				identity: expect.objectContaining( { name: 'Changed' } ),
			} ),
			{ dirtyAreas: [ 'identity' ] }
		);
		await act( async () =>
			pending.resolve( documentFor( 'a', { bio: 'Saved response' } ) )
		);
		expect( container.textContent ).toContain( 'Saved!' );
		expect( dirty ).toHaveBeenLastCalledWith( false );
		await act( async () => root.unmount() );
	} );

	it( 'disables identity interaction and applies a successful upload', async () => {
		const pending = deferred();
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) =>
				documentFor( id, {
					css_vars: { '--link-page-background-type': 'image' },
				} )
			),
			save: jest.fn(),
			upload: jest.fn( () => pending.promise ),
		};
		const { container, root } = await renderEditor();
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => 'Customize' === tab.textContent )
				.click()
		);
		const fileInput = container.querySelector( 'input[type="file"]' );
		Object.defineProperty( fileInput, 'files', {
			value: [ new File( [ 'x' ], 'image.png', { type: 'image/png' } ) ],
		} );
		await act( async () =>
			fileInput.dispatchEvent( new Event( 'change', { bubbles: true } ) )
		);
		expect(
			container.querySelector( '[aria-label="Link Page identity"]' )
				.disabled
		).toBe( true );
		expect(
			container.querySelector( '.ec-editor__controls' ).disabled
		).toBe( true );
		await act( async () =>
			pending.resolve( {
				attachment_id: 99,
				url: 'https://example.com/uploaded.jpg',
			} )
		);
		expect(
			container
				.querySelector( '.extrch-link-page-preview-container' )
				.style.getPropertyValue( '--link-page-background-image-url' )
		).toContain( 'uploaded.jpg' );
		expect(
			container.querySelector( '[aria-label="Link Page identity"]' )
				.disabled
		).toBe( false );
		await act( async () => root.unmount() );
	} );

	it( 'updates the card preview and saves fully transparent color', async () => {
		const save = jest.fn( async ( id, draft ) =>
			documentFor( id, { css_vars: draft.page.styles } )
		);
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) => documentFor( id ) ),
			save,
		};
		const { container, root } = await renderEditor();
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => tab.textContent === 'Customize' )
				.click()
		);
		const controlFor = ( label ) =>
			container.querySelector(
				`#${ [ ...container.querySelectorAll( 'label' ) ].find( ( item ) => item.textContent === label ).htmlFor }`
			);
		await input( controlFor( 'Card Color' ), '#123456' );
		await input( controlFor( 'Card Opacity' ), '0' );
		expect(
			container
				.querySelector( '.extrch-link-page-preview-container' )
				.style.getPropertyValue( '--link-page-card-bg-color' )
		).toBe( 'rgba(18, 52, 86, 0)' );
		await act( async () =>
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				)
		);
		expect(
			save.mock.calls[ 0 ][ 1 ].page.styles[ '--link-page-card-bg-color' ]
		).toBe( 'rgba(18, 52, 86, 0)' );
		expect( controlFor( 'Card Opacity' ).value ).toBe( '0' );
		await act( async () => root.unmount() );
	} );

	it( 'restores dirty state and synchronizes the owning adapter', async () => {
		const dirty = jest.fn();
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn(),
			save: jest.fn(),
			onDirtyChange: dirty,
		};
		sessionStorage.setItem(
			'ec-link-page-editor:dirty:test-adapter:a',
			JSON.stringify( {
				dirtyAreas: [ 'identity' ],
				draft: {
					identity: { id: 'a', name: 'Restored', imageUrl: '' },
					page: {
						...documentFor( 'a' ).link_page,
						links: documentFor( 'a' ).link_page.link_sections,
						styles: {},
						settings: {},
						bio: '',
						publicUrl: '',
						backgroundImageId: 0,
						backgroundImageUrl: '',
					},
					socials: [],
				},
			} )
		);
		const { container, root } = await renderEditor();
		expect(
			container.querySelector( 'input[value="Restored"]' )
		).not.toBeNull();
		expect( dirty ).toHaveBeenLastCalledWith( true );
		expect(
			window.ecLinkPageEditorAdapters[ 'test-adapter' ].read
		).not.toHaveBeenCalled();
		await act( async () => root.unmount() );
	} );

	it( 'rejects blank and malformed links before adapter save', async () => {
		const save = jest.fn();
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) => documentFor( id ) ),
			save,
		};
		const { container, root } = await renderEditor();
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => 'Links' === tab.textContent )
				.click()
		);
		const controlButtons = [
			...container.querySelectorAll(
				'.ec-lpe-section__header button, .ec-link-item button'
			),
		];
		expect( controlButtons ).toHaveLength( 6 );
		controlButtons.forEach( ( button ) => {
			expect( button.classList.contains( 'button-small' ) ).toBe( true );
			expect(
				button.classList.contains(
					button.textContent.trim() === 'Remove'
						? 'button-danger'
						: 'button-3'
				)
			).toBe( true );
		} );
		await input(
			container.querySelector( '[aria-label="Link URL"]' ),
			'not-a-url'
		);
		await act( async () =>
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				)
		);
		expect( save ).not.toHaveBeenCalled();
		expect( container.textContent ).toContain( 'valid URL' );
		await act( async () => root.unmount() );
	} );

	it( 'edits arbitrary gradient angles and enables or removes a middle stop', async () => {
		const save = jest.fn( async ( id, draft ) =>
			documentFor( id, { css_vars: draft.page.styles } )
		);
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: async ( id ) =>
				documentFor( id, {
					css_vars: {
						'--link-page-background-type': 'gradient',
						'--link-page-background-gradient-direction': 'to right',
					},
				} ),
			save,
		};
		const { container, root } = await renderEditor();
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => tab.textContent === 'Customize' )
				.click()
		);
		const control = ( label ) => {
			const element = [ ...container.querySelectorAll( 'label' ) ].find(
				( item ) => item.textContent === label
			);
			return container.querySelector( `[id="${ element.htmlFor }"]` );
		};
		expect( control( 'Gradient Angle (degrees)' ).value ).toBe( '90' );
		await input( control( 'Gradient Angle (degrees)' ), '27.5' );
		await act( async () => control( 'Use Middle Color' ).click() );
		await input( control( 'Gradient Middle' ), '#8000ff' );
		const preview = () =>
			container.querySelector( '.extrch-link-page-preview-container' )
				.dataset.backgroundImage;
		expect( preview() ).toBe(
			'linear-gradient(27.5deg, #0b5394, #8000ff, #53940b)'
		);
		await act( async () =>
			container.querySelector( 'form' ).requestSubmit()
		);
		expect(
			save.mock.calls[ 0 ][ 1 ].page.styles[
				'--link-page-background-gradient-middle'
			]
		).toBe( '#8000ff' );
		await act( async () => control( 'Use Middle Color' ).click() );
		expect( preview() ).toBe(
			'linear-gradient(27.5deg, #0b5394, #53940b)'
		);
		await act( async () =>
			container.querySelector( 'form' ).requestSubmit()
		);
		expect(
			save.mock.calls[ 1 ][ 1 ].page.styles[
				'--link-page-background-gradient-middle'
			]
		).toBe( '' );
		await act( async () => root.unmount() );
	} );

	it( 'renders gradient, cleared image, and public link wrappers faithfully', async () => {
		const container = document.createElement( 'div' );
		const root = createRoot( container );
		const gradient = documentFor( 'a', {
			css_vars: {
				'--link-page-background-type': 'gradient',
				'--link-page-background-gradient-direction': 'to bottom',
				'--link-page-background-gradient-start': '#000000',
				'--link-page-background-gradient-end': '#ffffff',
			},
		} );
		const draft = {
			identity: { id: 'a', name: 'Preview', imageUrl: '' },
			page: {
				links: gradient.link_page.link_sections,
				styles: gradient.link_page.css_vars,
				settings: {},
				bio: '',
				backgroundImageUrl: '',
				publicUrl: '',
			},
			socials: [
				{
					type: 'instagram',
					url: 'https://instagram.com/example',
					icon_class: 'fab fa-instagram',
				},
			],
		};
		await act( async () => root.render( <Preview draft={ draft } /> ) );
		for ( const [ selector, icon ] of [
			[ '.extrch-share-page-trigger', 'fa-ellipsis-h' ],
			[ '.extrch-share-item-trigger', 'fa-ellipsis-v' ],
			[ '.extrch-bell-page-trigger', 'fa-bell' ],
		] ) {
			const trigger = container.querySelector( selector );
			expect( trigger.textContent.trim() ).toBe( '' );
			expect( trigger.querySelector( `i.fas.${ icon }` ) ).not.toBeNull();
			expect( trigger.getAttribute( 'aria-label' ) ).toBeTruthy();
		}
		expect(
			container.querySelector( '.extrch-social-icon i.fa-instagram' )
		).not.toBeNull();
		await act( async () =>
			root.render(
				<Preview
					draft={ {
						...draft,
						page: {
							...draft.page,
							settings: { subscribe_display_mode: 'inline_form' },
						},
					} }
				/>
			)
		);
		const subscribe = container.querySelector(
			'.extrch-subscribe-form button'
		);
		expect( subscribe.className ).toBe( 'extrch-subscribe-btn' );
		expect( subscribe.type ).toBe( 'button' );
		expect(
			container.querySelector(
				'.extrch-subscribe-form .form-group input[type="email"]'
			)
		).not.toBeNull();
		expect(
			container.querySelector( '.extrch-subscribe-form form' )
		).toBeNull();
		expect(
			container.querySelector( '.extrch-link-page-preview-container' )
				.dataset.backgroundImage
		).toContain( 'linear-gradient' );
		expect(
			container.querySelector(
				'.extrch-link-button-wrapper .extrch-share-item-trigger'
			)
		).not.toBeNull();
		await act( async () =>
			root.render(
				<Preview
					draft={ {
						...draft,
						page: {
							...draft.page,
							styles: { '--link-page-background-type': 'image' },
							backgroundImageUrl: '',
						},
					} }
				/>
			)
		);
		expect(
			container.querySelector( '.extrch-link-page-preview-container' )
				.style.backgroundImage
		).toBe( 'none' );
		await act( async () => root.unmount() );
	} );

	it( 'implements keyboard tabs and an Escape-closing focus-restoring QR dialog', async () => {
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) => documentFor( id ) ),
			save: jest.fn(),
			qrCode: jest.fn( async () => 'https://example.com/qr.png' ),
		};
		const { container, root } = await renderEditor();
		const tabs = container.querySelectorAll( '[role="tab"]' );
		tabs[ 0 ].focus();
		await act( async () =>
			tabs[ 0 ].dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'ArrowRight',
					bubbles: true,
				} )
			)
		);
		expect( tabs[ 1 ].getAttribute( 'aria-selected' ) ).toBe( 'true' );
		expect(
			container
				.querySelector( '[role="tabpanel"]' )
				.getAttribute( 'aria-labelledby' )
		).toBe( tabs[ 1 ].id );
		const qrButton = [ ...container.querySelectorAll( 'button' ) ].find(
			( button ) => 'QR Code' === button.textContent
		);
		await act( async () => qrButton.click() );
		await flush();
		const dialog = container.querySelector(
			'.ec-qr-modal [role="dialog"]'
		);
		expect( dialog.querySelector( 'button' ).className ).toBe(
			'button-3 button-small'
		);
		expect( dialog.querySelector( 'button' ) ).toBe(
			document.activeElement
		);
		const download = dialog.querySelector( 'a[href]' );
		download.focus();
		await act( async () =>
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Tab', bubbles: true } )
			)
		);
		expect( dialog.querySelector( 'button' ) ).toBe(
			document.activeElement
		);
		await act( async () =>
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
			)
		);
		expect(
			container.querySelector( '.ec-qr-modal [role="dialog"]' )
		).toBeNull();
		expect( document.activeElement ).toBe( qrButton );
		await act( async () => root.unmount() );
	} );

	it( 'traps one-control QR loading and error states', async () => {
		const pending = deferred();
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) => documentFor( id ) ),
			save: jest.fn(),
			qrCode: jest.fn( () => pending.promise ),
		};
		const { container, root } = await renderEditor();
		const qrButton = [ ...container.querySelectorAll( 'button' ) ].find(
			( button ) => 'QR Code' === button.textContent
		);
		await act( async () => qrButton.click() );
		let close = container.querySelector( '.ec-qr-modal button' );
		expect( close ).toBe( document.activeElement );
		await act( async () =>
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Tab', bubbles: true } )
			)
		);
		expect( close ).toBe( document.activeElement );
		await act( async () => pending.reject( new Error( 'QR failed.' ) ) );
		await flush();
		expect(
			container.querySelector( '.ec-qr-modal [role="alert"]' ).textContent
		).toContain( 'QR failed.' );
		expect( container.textContent ).not.toContain( 'Generating QR Code' );
		close = container.querySelector( '.ec-qr-modal button' );
		close.focus();
		await act( async () =>
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Tab', bubbles: true } )
			)
		);
		expect( close ).toBe( document.activeElement );
		await act( async () => root.unmount() );
	} );

	it.each( [ 'before', 'after' ] )(
		'mounts when the adapter registers %s the runtime boundary',
		async ( order ) => {
			const adapter = {
				read: jest.fn( async ( id ) => documentFor( id ) ),
				save: jest.fn(),
			};
			if ( 'before' === order ) {
				registerAdapter( 'test-adapter', adapter );
			}
			const container = document.createElement( 'div' );
			const root = createRoot( container );
			await act( async () =>
				root.render(
					<AdapterBoundary configuration={ configuration() } />
				)
			);
			if ( 'after' === order ) {
				await act( async () =>
					registerAdapter( 'test-adapter', adapter )
				);
			}
			await flush();
			expect( container.querySelector( '.ec-editor' ) ).not.toBeNull();
			await act( async () => root.unmount() );
		}
	);

	it( 'binds extension changes to their declared dirty area', async () => {
		const save = jest.fn( async ( id ) => documentFor( id ) );
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) => documentFor( id ) ),
			save,
			panels: [
				{
					id: 'extension',
					label: 'Extension',
					area: 'extension-settings',
					render: ( { draft, change } ) => (
						<button
							type="button"
							onClick={ () =>
								change( {
									page: {
										...draft.page,
										bio: 'Extension change',
									},
								} )
							}
						>
							Change Extension
						</button>
					),
				},
			],
		};
		const { container, root } = await renderEditor();
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => 'Extension' === tab.textContent )
				.click()
		);
		await act( async () =>
			[ ...container.querySelectorAll( 'button' ) ]
				.find( ( button ) => 'Change Extension' === button.textContent )
				.click()
		);
		await act( async () =>
			container.querySelector( 'form' ).requestSubmit()
		);
		expect( save ).toHaveBeenCalledWith( 'a', expect.any( Object ), {
			dirtyAreas: [ 'extension-settings' ],
		} );
		await act( async () => root.unmount() );
	} );

	it( 'exposes the full control and public-preview surface', async () => {
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) => documentFor( id ) ),
			save: jest.fn(),
			socialsPanel: () => null,
		};
		const { container, root } = await renderEditor();
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => 'Newsletter' === tab.textContent )
				.click()
		);
		expect( container.textContent ).toContain( 'How fans sign up' );
		expect( container.textContent ).toContain( 'Message to fans' );
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => 'Advanced' === tab.textContent )
				.click()
		);
		expect( container.textContent ).not.toContain( 'How fans sign up' );
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => 'Customize' === tab.textContent )
				.click()
		);
		expect( container.textContent ).toContain( 'Title Size' );
		expect( container.textContent ).toContain( 'Profile Image Size' );
		expect( container.textContent ).toContain( 'Button Hover Color' );
		expect( container.textContent ).toContain( 'Button Finish' );
		const finish = [ ...container.querySelectorAll( 'select' ) ].find(
			( select ) => select.querySelector( 'option[value="gloss"]' )
		);
		expect( finish.value ).toBe( 'flat' );
		await act( async () => {
			finish.value = 'gloss';
			finish.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		const preview = container.querySelector( '.extrch-link-page-preview-container' );
		expect( preview.style.getPropertyValue( '--link-page-button-finish' ) ).toBe( 'gloss' );
		expect( preview.style.getPropertyValue( '--link-page-button-sheen' ) ).toContain( 'linear-gradient' );
		expect(
			container.querySelector( '.extrch-share-page-trigger' )
		).not.toBeNull();
		expect(
			container.querySelector( '#extrch-subscribe-modal' )
		).not.toBeNull();
		await act( async () => root.unmount() );
	} );

	it( 'omits subscription controls and preview when capability is disabled', async () => {
		window.ecLinkPageEditorAdapters[ 'test-adapter' ] = {
			read: jest.fn( async ( id ) => documentFor( id ) ),
			save: jest.fn(),
		};
		const { container, root } = await renderEditor(
			configuration( { capabilities: { subscriptions: false } } )
		);
		await act( async () =>
			[ ...container.querySelectorAll( '[role="tab"]' ) ]
				.find( ( tab ) => 'Advanced' === tab.textContent )
				.click()
		);
		expect(
			[ ...container.querySelectorAll( '[role="tab"]' ) ].map(
				( tab ) => tab.textContent
			)
		).not.toContain( 'Newsletter' );
		expect( container.textContent ).not.toContain( 'How fans sign up' );
		expect(
			container.querySelector( '#extrch-subscribe-modal' )
		).toBeNull();
		await act( async () => root.unmount() );
	} );
} );

describe( 'editor exit links', () => {
	it( 'keeps only labelled http(s) links from the configuration', () => {
		expect(
			exitLinksFor( [
				{ label: 'Manage artist', url: 'https://example.test/manage/' },
				{ label: '', url: 'https://example.test/empty-label/' },
				{ label: 'Script', url: 'javascript:alert(1)' },
				null,
			] )
		).toEqual( [
			{ label: 'Manage artist', url: 'https://example.test/manage/' },
		] );
		expect( exitLinksFor( undefined ) ).toEqual( [] );
	} );
} );

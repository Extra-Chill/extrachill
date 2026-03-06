import { __, sprintf } from '@wordpress/i18n';
import { createElement, createRoot, render, useMemo, useState } from '@wordpress/element';

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
				createElement( 'li', null, __( 'Hook Studio tools into real API endpoints.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Add Data Machine-backed caption and publishing jobs where async work makes sense.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Expand toward team-specific assistants and social workflows.', 'extrachill-studio' ) )
			)
		)
	)
);

const CaptionsPane = () => {
	const [ notes, setNotes ] = useState( '' );
	const [ tone, setTone ] = useState( 'extra-chill' );

	const preview = useMemo( () => {
		if ( ! notes.trim() ) {
			return __( 'Drop a few notes about a post, a show, or a release here. Studio will turn this area into a real caption workflow next.', 'extrachill-studio' );
		}

		const toneLabelMap = {
			'extra-chill': __( 'extra chill', 'extrachill-studio' ),
			editorial: __( 'editorial', 'extrachill-studio' ),
			hype: __( 'hype', 'extrachill-studio' ),
		};

		return sprintf(
			__( 'Draft vibe: %1$s\n\n%2$s\n\n— Phase 0 note: this is a local preview shell. The next step is wiring this tab to a real caption generation workflow.', 'extrachill-studio' ),
			toneLabelMap[ tone ] || tone,
			notes.trim()
		);
	}, [ notes, tone ] );

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--captions' },
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Caption lab', 'extrachill-studio' ) ),
			createElement( 'h3', null, __( 'Shape the next caption workflow', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'This tab is the first shell for future AI caption generation. Use it to preview the workflow shape before wiring backend actions.', 'extrachill-studio' ) ),
			createElement(
				'div',
				{ className: 'ec-studio-composer' },
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-caption-notes' }, __( 'Notes', 'extrachill-studio' ) ),
					createElement( 'textarea', {
						id: 'ec-studio-caption-notes',
						rows: 6,
						value: notes,
						onChange: ( event ) => setNotes( event.target.value ),
						placeholder: __( 'Example: short reel from the Thursday show, spotlight the crowd energy, mention tickets in bio.', 'extrachill-studio' ),
					} )
				),
				createElement(
					'div',
					null,
					createElement( 'label', { htmlFor: 'ec-studio-caption-tone' }, __( 'Tone', 'extrachill-studio' ) ),
					createElement(
						'select',
						{
							id: 'ec-studio-caption-tone',
							value: tone,
							onChange: ( event ) => setTone( event.target.value ),
						},
						createElement( 'option', { value: 'extra-chill' }, __( 'Extra Chill', 'extrachill-studio' ) ),
						createElement( 'option', { value: 'editorial' }, __( 'Editorial', 'extrachill-studio' ) ),
						createElement( 'option', { value: 'hype' }, __( 'Hype', 'extrachill-studio' ) )
					)
				),
				createElement(
					'div',
					{ className: 'ec-studio-composer__actions' },
					createElement( 'button', { type: 'button', className: 'button-1 button-medium', disabled: true }, __( 'Generate with AI', 'extrachill-studio' ) ),
					createElement( 'span', { className: 'ec-studio-composer__hint' }, __( 'Backend wiring comes next.', 'extrachill-studio' ) )
				)
			)
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Preview', 'extrachill-studio' ) ),
			createElement( 'div', { className: 'ec-studio-preview' }, preview )
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
		createElement( 'h3', null, __( 'Feature path from shell to real workflow engine', 'extrachill-studio' ) ),
		createElement(
			'ul',
			{ className: 'ec-studio-roadmap' },
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

		if ( pane === 'captions' ) {
			mountComponent( mount, createElement( CaptionsPane ) );
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

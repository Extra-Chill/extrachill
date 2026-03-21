import { __, sprintf } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';

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
			createElement( 'p', null, __( 'Studio 0.1.0 is now a working internal shell with real utilities for the team.', 'extrachill-studio' ) )
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Current site', 'extrachill-studio' ) ),
			createElement( 'h3', null, context.siteName || __( 'Extra Chill', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'Studio stays aligned with the existing multisite theme system, team-member permissions, and shared tabs UI.', 'extrachill-studio' ) )
		),
		createElement(
			'div',
			{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'What works now', 'extrachill-studio' ) ),
			createElement(
				'ul',
				null,
				createElement( 'li', null, __( 'Compose posts with the full block editor — no wp-admin required.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Generate print-ready QR codes from any URL.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Manage social platforms with live auth status from Data Machine Socials.', 'extrachill-studio' ) ),
				createElement( 'li', null, __( 'Submit social posts for admin review before cross-posting.', 'extrachill-studio' ) )
			)
		)
	)
);

export default OverviewPane;

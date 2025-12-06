/**
 * JumpToPreview Component
 *
 * Mobile-only floating button for navigating between edit and preview sections.
 * Displays search icon + down arrow at edit section, cog + up arrow at preview.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const MOBILE_BREAKPOINT = 900;

export default function JumpToPreview() {
	const [ isNearPreview, setIsNearPreview ] = useState( false );
	const [ isMobile, setIsMobile ] = useState( false );

	const checkMobile = useCallback( () => {
		setIsMobile( window.innerWidth < MOBILE_BREAKPOINT );
	}, [] );

	const checkPosition = useCallback( () => {
		const previewContainer = document.querySelector(
			'.ec-editor__preview-container'
		);
		if ( ! previewContainer ) return;

		const rect = previewContainer.getBoundingClientRect();
		const viewportHeight = window.innerHeight;
		const adminBar = document.getElementById( 'wpadminbar' );
		const adminBarHeight = adminBar?.offsetHeight || 0;

		setIsNearPreview(
			rect.top < viewportHeight / 2 + adminBarHeight
		);
	}, [] );

	const handleScroll = useCallback( () => {
		if ( isMobile ) {
			checkPosition();
		}
	}, [ isMobile, checkPosition ] );

	const handleClick = useCallback( () => {
		const sidebar = document.querySelector( '.ec-editor__sidebar' );
		const preview = document.querySelector(
			'.ec-editor__preview-container'
		);
		const adminBar = document.getElementById( 'wpadminbar' );
		const adminBarHeight = adminBar?.offsetHeight || 0;

		const target = isNearPreview ? sidebar : preview;
		if ( ! target ) return;

		const targetTop =
			target.getBoundingClientRect().top +
			window.pageYOffset -
			adminBarHeight -
			10;
		window.scrollTo( {
			top: targetTop,
			behavior: 'smooth',
		} );
	}, [ isNearPreview ] );

	useEffect( () => {
		checkMobile();
		checkPosition();

		window.addEventListener( 'resize', checkMobile );
		window.addEventListener( 'scroll', handleScroll, { passive: true } );

		return () => {
			window.removeEventListener( 'resize', checkMobile );
			window.removeEventListener( 'scroll', handleScroll );
		};
	}, [ checkMobile, handleScroll, checkPosition ] );

	if ( ! isMobile ) {
		return null;
	}

	return (
		<button
			type="button"
			className="ec-jump-to-preview"
			onClick={ handleClick }
			title={
				isNearPreview
					? __( 'Scroll to Settings', 'extrachill-artist-platform' )
					: __( 'Scroll to Preview', 'extrachill-artist-platform' )
			}
			aria-label={
				isNearPreview
					? __( 'Scroll to Settings', 'extrachill-artist-platform' )
					: __( 'Scroll to Preview', 'extrachill-artist-platform' )
			}
		>
			<span className="ec-jump-to-preview__main-icon">
				<span
					className={ `dashicons ${
						isNearPreview
							? 'dashicons-admin-generic'
							: 'dashicons-search'
					}` }
				/>
			</span>
			<span
				className={ `ec-jump-to-preview__arrow arrow-${
					isNearPreview ? 'up' : 'down'
				}` }
			>
				<span
					className={ `dashicons dashicons-arrow-${
						isNearPreview ? 'up' : 'down'
					}-alt2` }
				/>
			</span>
		</button>
	);
}

/**
 * Preview Component
 *
 * Live preview panel using production markup and CSS (extrch-links.css).
 * Renders exactly as the link page will appear on the frontend.
 */

import { useEffect, useMemo, useRef } from '@wordpress/element';
import { useEditor } from '../context/EditorContext';

export default function Preview() {
	const {
		computedStyles,
		previewData,
		googleFontsUrl,
		linkPageCssUrl,
		socialIconsCssUrl,
	} = useEditor();

	const {
		name,
		bio,
		profileImageUrl,
		profileShape,
		links,
		socials,
		socialsPosition,
		overlayEnabled,
		backgroundType,
	} = previewData;

	const googleFontLinkRef = useRef( null );

	// Load production CSS for preview
	useEffect( () => {
		if ( linkPageCssUrl ) {
			const linkId = 'ec-link-page-preview-css';
			if ( ! document.getElementById( linkId ) ) {
				const link = document.createElement( 'link' );
				link.id = linkId;
				link.rel = 'stylesheet';
				link.href = linkPageCssUrl;
				document.head.appendChild( link );
			}
		}

		if ( socialIconsCssUrl ) {
			const socialLinkId = 'ec-social-icons-preview-css';
			if ( ! document.getElementById( socialLinkId ) ) {
				const link = document.createElement( 'link' );
				link.id = socialLinkId;
				link.rel = 'stylesheet';
				link.href = socialIconsCssUrl;
				document.head.appendChild( link );
			}
		}
	}, [ linkPageCssUrl, socialIconsCssUrl ] );

	// Load Google Fonts dynamically when fonts change
	useEffect( () => {
		const linkId = 'ec-google-fonts-preview';

		if ( googleFontsUrl ) {
			let link = document.getElementById( linkId );

			if ( ! link ) {
				link = document.createElement( 'link' );
				link.id = linkId;
				link.rel = 'stylesheet';
				document.head.appendChild( link );
				googleFontLinkRef.current = link;
			}

			if ( link.href !== googleFontsUrl ) {
				link.href = googleFontsUrl;
			}
		} else {
			const existingLink = document.getElementById( linkId );
			if ( existingLink ) {
				existingLink.remove();
				googleFontLinkRef.current = null;
			}
		}
	}, [ googleFontsUrl ] );

	const socialIconsAbove = socialsPosition === 'above';

	const renderSocialIcons = () => {
		if ( ! socials || socials.length === 0 ) {
			return null;
		}

		return (
			<div className="extrch-link-page-socials">
				{ socials.map( ( social, index ) => (
					<a
						key={ social.id || index }
						href={ social.url || '#' }
						className="extrch-social-icon"
						target="_blank"
						rel="noopener noreferrer"
						title={ social.label || social.type }
					>
						<i className={ `fa-brands fa-${ social.type }` }></i>
					</a>
				) ) }
			</div>
		);
	};

	const renderLinks = () => {
		if ( ! links || links.length === 0 ) {
			return (
				<div className="extrch-link-page-links">
					<p style={ { textAlign: 'center', opacity: 0.6 } }>
						No links added yet.
					</p>
				</div>
			);
		}

		return (
			<div className="extrch-link-page-links">
				{ links.map( ( section, sectionIndex ) => (
					<div
						key={ section.id || sectionIndex }
						className="extrch-link-page-section"
					>
						{ section.section_title && (
							<div className="extrch-link-page-section-title">
								{ section.section_title }
							</div>
						) }
						<div className="extrch-link-page-links-container">
							{ section.links?.map( ( link, linkIndex ) => (
								<a
									key={ link.id || linkIndex }
									href={ link.link_url || '#' }
									className="extrch-link-page-link"
									target="_blank"
									rel="noopener noreferrer"
								>
									<span className="extrch-link-page-link-text">
										{ link.link_text || 'Untitled Link' }
									</span>
								</a>
							) ) }
						</div>
					</div>
				) ) }
			</div>
		);
	};

	// Build CSS variables style object for the container
	const containerStyle = useMemo( () => {
		const style = {};
		Object.entries( computedStyles ).forEach( ( [ key, value ] ) => {
			if ( value ) {
				style[ key ] = value;
			}
		} );
		return style;
	}, [ computedStyles ] );

	// Profile image classes based on shape
	const profileImgClasses = [
		'extrch-link-page-profile-img',
		`shape-${ profileShape }`,
		! profileImageUrl ? 'no-image' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	// Content wrapper classes
	const wrapperClasses = [
		'extrch-link-page-content-wrapper',
		! overlayEnabled ? 'no-overlay' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className="ec-preview-wrapper">
			<div className="ec-preview-indicator">Live Preview</div>
			<div
				className="extrch-link-page-container extrch-link-page-preview-container"
				data-bg-type={ backgroundType }
				style={ containerStyle }
			>
				<div className={ wrapperClasses }>
					<div className="extrch-link-page-header-content">
						<div className={ profileImgClasses }>
							{ profileImageUrl && (
								<img
									src={ profileImageUrl }
									alt={ name || 'Profile' }
								/>
							) }
						</div>
						{ name && (
							<h1 className="extrch-link-page-title">{ name }</h1>
						) }
						{ bio && (
							<div className="extrch-link-page-bio">{ bio }</div>
						) }
					</div>

					{ socialIconsAbove && renderSocialIcons() }

					{ renderLinks() }

					{ ! socialIconsAbove && renderSocialIcons() }

					<div className="extrch-link-page-powered">
						<a
							href="https://extrachill.link"
							target="_blank"
							rel="noopener noreferrer"
						>
							Powered by Extra Chill
						</a>
					</div>
				</div>
			</div>
		</div>
	);
}

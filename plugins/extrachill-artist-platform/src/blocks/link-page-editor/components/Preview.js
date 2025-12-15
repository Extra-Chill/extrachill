/**
 * Preview Component
 *
 * Live preview panel using production markup and CSS (extrch-links.css).
 * Renders exactly as the link page will appear on the frontend.
 */

import { useEffect, useMemo, useRef, Fragment } from '@wordpress/element';
import { useEditor } from '../context/EditorContext';

export default function Preview() {
	const {
		computedStyles,
		previewData,
		googleFontsUrl,
		linkPageCssUrl,
		socialIconsCssUrl,
		shareModalCssUrl,
		fontAwesomeUrl,
		localFontsCss,
	} = useEditor();

	const {
		name,
		bio,
		profileImageUrl,
		profileShape,
		links,
		socials,
		socialsPosition,
		subscribeDisplayMode,
		overlayEnabled,
		backgroundType,
	} = previewData;

	const googleFontLinkRef = useRef( null );

	// Load production CSS for preview (core styles, icons, share modal, Font Awesome)
	useEffect( () => {
		const ensureLink = ( id, href ) => {
			if ( ! href ) {
				return;
			}

			const existing = document.getElementById( id );
			if ( existing && existing.href === href ) {
				return;
			}

			if ( existing && existing.parentNode ) {
				existing.parentNode.removeChild( existing );
			}

			const link = document.createElement( 'link' );
			link.id = id;
			link.rel = 'stylesheet';
			link.href = href;
			document.head.appendChild( link );
		};

		ensureLink( 'ec-link-page-preview-css', linkPageCssUrl );
		ensureLink( 'ec-social-icons-preview-css', socialIconsCssUrl );
		ensureLink( 'ec-share-modal-preview-css', shareModalCssUrl );
		ensureLink( 'ec-font-awesome-preview-css', fontAwesomeUrl );
	}, [ linkPageCssUrl, socialIconsCssUrl, shareModalCssUrl, fontAwesomeUrl ] );

	// Inject local font CSS (e.g., Loft Sans) when provided
	useEffect( () => {
		const styleId = 'ec-local-fonts-css';

		if ( localFontsCss ) {
			let styleTag = document.getElementById( styleId );

			if ( ! styleTag ) {
				styleTag = document.createElement( 'style' );
				styleTag.id = styleId;
				document.head.appendChild( styleTag );
			}

			if ( styleTag.textContent !== localFontsCss ) {
				styleTag.textContent = localFontsCss;
			}
		} else {
			const existing = document.getElementById( styleId );
			if ( existing ) {
				existing.remove();
			}
		}
	}, [ localFontsCss ] );

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
				{ socials.map( ( social ) => (
					<a
						key={ social.id }
						href={ social.url }
						className="extrch-social-icon"
						target="_blank"
						rel="noopener noreferrer"
						title={ social.label || social.type }
					>
						<i className={ social.icon_class }></i>
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
			<>
				{ links.map( ( section, sectionIndex ) => (
					<Fragment key={ section.id || sectionIndex }>
						{ section.section_title && (
							<div className="extrch-link-page-section-title">
								{ section.section_title }
							</div>
						) }
						<div className="extrch-link-page-links">
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
					</Fragment>
				) ) }
			</>
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

	const renderSubscribeInline = () => {
		if ( subscribeDisplayMode !== 'inline_form' ) {
			return null;
		}

		return (
			<div className="extrch-link-page-subscribe-inline-form-container">
				<h3 className="extrch-subscribe-header">Subscribe</h3>
				<p className="extrch-subscribe-description">
					Enter your email address to receive updates.
				</p>
				<form className="extrch-subscribe-form">
					<div className="form-group">
						<input
							type="email"
							placeholder="Your email address"
							required
							aria-label="Email Address"
						/>
					</div>
					<button type="button" className="button-1 button-medium">
						Subscribe
					</button>
					<div className="extrch-form-message" aria-live="polite"></div>
				</form>
			</div>
		);
	};

	const renderSubscribeModal = () => {
		if ( subscribeDisplayMode !== 'icon_modal' ) {
			return null;
		}

		return (
			<div
				id="extrch-subscribe-modal"
				className="extrch-subscribe-modal extrch-modal extrch-modal-hidden"
				role="dialog"
				aria-modal="true"
				aria-labelledby="extrch-subscribe-modal-title"
			>
				<div className="extrch-subscribe-modal-overlay extrch-modal-overlay"></div>
				<div className="extrch-subscribe-modal-content extrch-modal-content">
					<button
						className="extrch-subscribe-modal-close extrch-modal-close"
						aria-label="Close subscription modal"
						type="button"
					>
						&times;
					</button>
					<div className="extrch-subscribe-modal-header">
						<h3 id="extrch-subscribe-modal-title" className="extrch-subscribe-header">
							Subscribe
						</h3>
						<p className="extrch-subscribe-description">
							Enter your email address to receive updates.
						</p>
					</div>
					<form className="extrch-subscribe-form">
						<div className="form-group">
							<input
								type="email"
								placeholder="Your email address"
								required
								aria-label="Email Address"
							/>
						</div>
						<button type="button" className="button-1 button-medium">
							Subscribe
						</button>
						<div className="extrch-form-message" aria-live="polite"></div>
					</form>
				</div>
			</div>
		);
	};

	const renderShareModal = () => {
		return (
			<div id="extrch-share-modal" className="extrch-share-modal extrch-modal extrch-modal-hidden">
				<div className="extrch-share-modal-overlay extrch-modal-overlay"></div>
				<div className="extrch-share-modal-content extrch-modal-content">
					<button className="extrch-share-modal-close extrch-modal-close" aria-label="Close share modal" type="button">
						&times;
					</button>
					<div className="extrch-share-modal-body">
						<div className="extrch-share-modal-main">
							<div className="extrch-share-modal-profile-img extrch-link-page-profile-img">
								{ profileImageUrl && (
									<img src={ profileImageUrl } alt={ name || 'Profile' } />
								) }
							</div>
							<div className="extrch-share-modal-text">
								<div className="extrch-share-modal-main-title">{ name || 'Share' }</div>
								<div className="extrch-share-modal-subtitle">Share this page</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		);
	};

	return (
		<div className="ec-preview-wrapper">
			<div
				className="extrch-link-page-container extrch-link-page-preview-container"
				data-bg-type={ backgroundType }
				style={ containerStyle }
			>
				<div className={ wrapperClasses }>
					<div className="extrch-link-page-header-content">
						{ subscribeDisplayMode === 'icon_modal' && (
							<button
								type="button"
								className="extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger"
								aria-label="Subscribe to this artist"
							>
								<i className="fas fa-bell"></i>
							</button>
						) }
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
						<button
							type="button"
							className="extrch-share-trigger extrch-share-page-trigger"
							aria-label="Share this page"
						>
							<i className="fas fa-ellipsis-h"></i>
						</button>
					</div>

					{ socialIconsAbove && renderSocialIcons() }

					{ renderLinks() }

					{ renderSubscribeInline() }

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

					{ renderSubscribeModal() }
					{ renderShareModal() }
				</div>
			</div>
		</div>
	);
}

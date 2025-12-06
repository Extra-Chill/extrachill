/**
 * Preview Component
 *
 * Live preview panel showing link page with computed styles.
 */

import { useMemo } from '@wordpress/element';
import { usePreview } from '../context/PreviewContext';

export default function Preview() {
	const { computedStyles, previewData } = usePreview();

	const {
		name,
		bio,
		profileImageUrl,
		links,
		socials,
		socialsPosition,
	} = previewData;

	const socialIconsTop = socialsPosition === 'above';

	const renderSocialIcons = () => {
		if ( ! socials || socials.length === 0 ) {
			return null;
		}

		return (
			<div className="ec-preview__socials">
				{ socials.map( ( social, index ) => (
					<a
						key={ social.id || index }
						href={ social.url || '#' }
						className="ec-preview__social-icon"
						target="_blank"
						rel="noopener noreferrer"
						title={ social.label || social.type }
					>
						<span className={ `ec-social-icon ec-social-icon--${ social.type }` }></span>
					</a>
				) ) }
			</div>
		);
	};

	const renderLinks = () => {
		if ( ! links || links.length === 0 ) {
			return (
				<div className="ec-preview__empty">
					<p>No links added yet.</p>
				</div>
			);
		}

		return (
			<div className="ec-preview__links">
				{ links.map( ( section, sectionIndex ) => (
				<div key={ section.id || sectionIndex } className="ec-preview__section">
					{ section.section_title && (
						<h3 className="ec-preview__section-title">{ section.section_title }</h3>
					) }
					<div className="ec-preview__section-links">
						{ section.links?.map( ( link, linkIndex ) => (
							<a
								key={ link.id || linkIndex }
								href={ link.link_url || '#' }
								className="ec-preview__link"
								target="_blank"
								rel="noopener noreferrer"
							>
								{ link.link_text || 'Untitled Link' }
							</a>
						) ) }
						</div>
					</div>
				) ) }
			</div>
		);
	};

	const previewStyle = useMemo( () => {
		const style = {};
		Object.entries( computedStyles ).forEach( ( [ key, value ] ) => {
			if ( key.startsWith( '--' ) ) {
				style[ key ] = value;
			} else {
				style[ key ] = value;
			}
		} );
		return style;
	}, [ computedStyles ] );

	return (
		<div className="ec-preview" style={ previewStyle }>
			<div className="ec-preview__phone-frame">
				<div className="ec-preview__content">
					{ profileImageUrl && (
						<div className="ec-preview__avatar">
							<img src={ profileImageUrl } alt={ name || 'Profile' } />
						</div>
					) }

					{ name && <h1 className="ec-preview__name">{ name }</h1> }

					{ bio && <p className="ec-preview__bio">{ bio }</p> }

					{ socialIconsTop && renderSocialIcons() }

					{ renderLinks() }

					{ ! socialIconsTop && renderSocialIcons() }
				</div>
			</div>
		</div>
	);
}

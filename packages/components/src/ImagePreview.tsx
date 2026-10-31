import type { ReactNode } from 'react';

export interface ImagePreviewProps {
	src: string;
	alt?: string;
	className?: string;
	classPrefix?: string;
	overlay?: ReactNode;
}

export function ImagePreview( {
	src,
	alt = '',
	className = '',
	classPrefix = 'ec-image-preview',
	overlay,
}: ImagePreviewProps ) {
	return (
		<div className={ [ classPrefix, className ].filter( Boolean ).join( ' ' ) }>
			<img src={ src } alt={ alt } className={ `${ classPrefix }__image` } />
			{ overlay ? <div className={ `${ classPrefix }__overlay` }>{ overlay }</div> : null }
		</div>
	);
}

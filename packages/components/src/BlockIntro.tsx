import type { ReactNode } from 'react';

export interface BlockIntroProps {
	title?: ReactNode;
	description?: ReactNode;
	className?: string;
	classPrefix?: string;
}

export function BlockIntro( {
	title,
	description,
	className = '',
	classPrefix = 'ec-block-intro',
}: BlockIntroProps ) {
	return (
		<div className={ [ classPrefix, className ].filter( Boolean ).join( ' ' ) }>
			{ title && <div className={ `${ classPrefix }__title` }>{ title }</div> }
			{ description && <div className={ `${ classPrefix }__description` }>{ description }</div> }
		</div>
	);
}

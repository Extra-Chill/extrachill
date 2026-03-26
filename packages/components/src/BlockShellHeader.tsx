import type { ReactNode } from 'react';

export interface BlockShellHeaderProps {
	title?: ReactNode;
	description?: ReactNode;
	actions?: ReactNode;
	className?: string;
	classPrefix?: string;
}

export function BlockShellHeader( {
	title,
	description,
	actions,
	className = '',
	classPrefix = 'ec-block-shell-header',
}: BlockShellHeaderProps ) {
	return (
		<div
			className={ [
				classPrefix,
				'ec-edge-gutter',
				className,
			].filter( Boolean ).join( ' ' ) }
		>
			<div className={ `${ classPrefix }__main` }>
				{ title && <div className={ `${ classPrefix }__title` }>{ title }</div> }
				{ description && <div className={ `${ classPrefix }__description` }>{ description }</div> }
			</div>
			{ actions && <div className={ `${ classPrefix }__actions` }>{ actions }</div> }
		</div>
	);
}

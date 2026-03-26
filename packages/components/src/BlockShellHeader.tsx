import type { ReactNode } from 'react';

export interface BlockShellHeaderProps {
	title?: ReactNode;
	description?: ReactNode;
	actions?: ReactNode;
	className?: string;
	classPrefix?: string;
	showDivider?: boolean;
}

export function BlockShellHeader( {
	title,
	description,
	actions,
	className = '',
	classPrefix = 'ec-block-shell-header',
	showDivider = true,
}: BlockShellHeaderProps ) {
	return (
		<div
			className={ [
				classPrefix,
				'ec-edge-gutter',
				showDivider ? `${ classPrefix }--with-divider` : `${ classPrefix }--without-divider`,
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

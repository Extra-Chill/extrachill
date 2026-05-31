import type { ReactNode } from 'react';

export interface ProgressBarProps {
	/** 0-100 determinate value. Omit/undefined renders the indeterminate variant. */
	value?: number;
	label?: ReactNode;
	tone?: 'default' | 'success' | 'error' | 'warning' | 'info';
	className?: string;
	classPrefix?: string;
}

export function ProgressBar( {
	value,
	label,
	tone = 'default',
	className = '',
	classPrefix = 'ec-progress-bar',
}: ProgressBarProps ) {
	const indeterminate = value === undefined || value === null || Number.isNaN( value );
	const clamped = indeterminate ? 0 : Math.max( 0, Math.min( 100, value ) );

	const classes = [
		classPrefix,
		`${ classPrefix }--${ tone }`,
		indeterminate ? `${ classPrefix }--indeterminate` : `${ classPrefix }--determinate`,
		className,
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes }>
			{ label ? <span className={ `${ classPrefix }__label` }>{ label }</span> : null }
			<div
				className={ `${ classPrefix }__track` }
				role="progressbar"
				aria-valuemin={ indeterminate ? undefined : 0 }
				aria-valuemax={ indeterminate ? undefined : 100 }
				aria-valuenow={ indeterminate ? undefined : clamped }
			>
				<div
					className={ `${ classPrefix }__fill` }
					style={ indeterminate ? undefined : { width: `${ clamped }%` } }
				/>
			</div>
		</div>
	);
}

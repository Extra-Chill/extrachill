import type { ReactNode } from 'react';

export interface FieldGroupProps {
	label?: ReactNode;
	help?: ReactNode;
	error?: ReactNode;
	required?: boolean;
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	htmlFor?: string;
}

export function FieldGroup( {
	label,
	help,
	error,
	required = false,
	children,
	className = '',
	classPrefix = 'ec-field-group',
	htmlFor,
}: FieldGroupProps ) {
	return (
		<div className={ [ classPrefix, className ].filter( Boolean ).join( ' ' ) }>
			{ label && (
				<label className={ `${ classPrefix }__label` } htmlFor={ htmlFor }>
					{ label }
					{ required && <span className={ `${ classPrefix }__required` }> *</span> }
				</label>
			) }
			<div className={ `${ classPrefix }__control` }>{ children }</div>
			{ help && <div className={ `${ classPrefix }__help` }>{ help }</div> }
			{ error && <div className={ `${ classPrefix }__error` }>{ error }</div> }
		</div>
	);
}

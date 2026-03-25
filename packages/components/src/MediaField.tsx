import type { ReactNode } from 'react';
import { ActionRow } from './ActionRow.tsx';
import { FieldGroup } from './FieldGroup.tsx';
import { ImagePreview } from './ImagePreview.tsx';

export interface MediaFieldProps {
	label?: ReactNode;
	help?: ReactNode;
	error?: ReactNode;
	required?: boolean;
	htmlFor?: string;
	previewUrl?: string | null;
	previewAlt?: string;
	preview?: ReactNode;
	empty?: ReactNode;
	actions?: ReactNode;
	children?: ReactNode;
	className?: string;
	classPrefix?: string;
}

export function MediaField( {
	label,
	help,
	error,
	required = false,
	htmlFor,
	previewUrl,
	previewAlt = '',
	preview,
	empty,
	actions,
	children,
	className = '',
	classPrefix = 'ec-media-field',
}: MediaFieldProps ) {
	const mediaClassName = [ classPrefix, className ].filter( Boolean ).join( ' ' );
	const hasMeta = Boolean( label || help || error || required );
	const previewContent = preview || ( previewUrl ? <ImagePreview src={ previewUrl } alt={ previewAlt } /> : null );
	const body = (
		<>
			{ children ? <div className={ `${ classPrefix }__input` }>{ children }</div> : null }
			<div className={ `${ classPrefix }__surface` }>
				{ previewContent ? (
					<div className={ `${ classPrefix }__preview` }>{ previewContent }</div>
				) : empty ? (
					<div className={ `${ classPrefix }__empty` }>{ empty }</div>
				) : null }
			</div>
			{ actions ? <ActionRow className={ `${ classPrefix }__actions` }>{ actions }</ActionRow> : null }
		</>
	);

	if ( hasMeta ) {
		return (
			<FieldGroup
				label={ label }
				help={ help }
				error={ error }
				required={ required }
				htmlFor={ htmlFor }
				className={ mediaClassName }
			>
				{ body }
			</FieldGroup>
		);
	}

	return <div className={ mediaClassName }>{ body }</div>;
}

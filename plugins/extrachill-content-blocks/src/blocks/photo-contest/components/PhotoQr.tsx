import { useState } from '@wordpress/element';
import type { ContestConfig } from '../types';
import { useEntryQr } from '../use-entry-qr';
import { ContestDialog } from './ContestDialog';

/**
 * Vote QR pinned to the bottom-right of an entry photo, so an entrant can
 * hold up their screen and someone scans it. Tap to enlarge.
 */
export function PhotoQr( {
	config,
	entryId,
	name,
}: {
	config: ContestConfig;
	entryId: number;
	name: string;
} ) {
	const { image } = useEntryQr( config, entryId, true );
	const [ big, setBig ] = useState( false );
	if ( ! image ) {
		return null;
	}
	return (
		<>
			<button
				type="button"
				className="extrachill-blocks-photo-contest-photo-qr"
				onClick={ () => setBig( true ) }
				aria-label={ `Enlarge QR code to vote for ${ name }` }
			>
				<img src={ image } alt={ `Scan to vote for ${ name }` } />
			</button>
			<ContestDialog open={ big } onClose={ () => setBig( false ) }>
				<div className="extrachill-blocks-photo-contest-form-step extrachill-blocks-photo-contest-qr">
					<h3>Scan to vote for { name }</h3>
					<img src={ image } alt={ `Scan to vote for ${ name }` } />
					<button
						type="button"
						className="button-2 button-large"
						onClick={ () => setBig( false ) }
					>
						Done
					</button>
				</div>
			</ContestDialog>
		</>
	);
}

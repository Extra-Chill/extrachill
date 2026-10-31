import { useEffect, useRef } from '@wordpress/element';
import type { ReactNode } from 'react';

export function ContestDialog( {
	open,
	onClose,
	children,
}: {
	open: boolean;
	onClose: () => void;
	children: ReactNode;
} ) {
	const ref = useRef< HTMLDialogElement | null >( null );
	useEffect( () => {
		const dialog = ref.current;
		if ( open && dialog && ! dialog.open ) dialog.showModal();
		else if ( ! open && dialog?.open ) dialog.close();
	}, [ open ] );
	return (
		<dialog
			ref={ ref }
			className="extrachill-blocks-photo-contest-dialog"
			aria-label="Photo contest"
			onClose={ onClose }
			onCancel={ ( event ) => {
				event.preventDefault();
				onClose();
			} }
			onClick={ ( event ) => {
				if ( event.target !== event.currentTarget ) return;
				const rect = event.currentTarget.getBoundingClientRect();
				if (
					event.clientX < rect.left ||
					event.clientX > rect.right ||
					event.clientY < rect.top ||
					event.clientY > rect.bottom
				)
					onClose();
			} }
		>
			<button
				type="button"
				className="button-3 button-small extrachill-blocks-photo-contest-dialog-close"
				aria-label="Close"
				onClick={ onClose }
			>
				×
			</button>
			{ children }
		</dialog>
	);
}

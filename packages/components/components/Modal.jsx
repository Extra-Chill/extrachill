/**
 * Modal Component
 *
 * Reusable modal wrapper using WordPress components.
 */

import { Modal as WPModal } from '@wordpress/components';

export default function Modal( {
	title,
	isOpen,
	onClose,
	children,
	className = '',
} ) {
	if ( ! isOpen ) {
		return null;
	}

	return (
		<WPModal
			title={ title }
			onRequestClose={ onClose }
			className={ `ec-modal ${ className }` }
		>
			{ children }
		</WPModal>
	);
}

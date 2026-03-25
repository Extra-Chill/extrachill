import { useEffect, useCallback, type ReactNode } from 'react';

export interface ModalProps {
	title: string;
	isOpen: boolean;
	onClose: () => void;
	children: ReactNode;
	className?: string;
}

export function Modal({
	title,
	isOpen,
	onClose,
	children,
	className = '',
}: ModalProps) {
	const handleKeyDown = useCallback(
		(e: KeyboardEvent) => {
			if (e.key === 'Escape') {
				onClose();
			}
		},
		[onClose]
	);

	useEffect(() => {
		if (isOpen) {
			document.addEventListener('keydown', handleKeyDown);
			return () => document.removeEventListener('keydown', handleKeyDown);
		}
	}, [isOpen, handleKeyDown]);

	if (!isOpen) {
		return null;
	}

	return (
		<div className={`ec-modal ${className}`} role="dialog" aria-label={title}>
			<div className="ec-modal__backdrop" onClick={onClose} aria-hidden="true" />
			<div className="ec-modal__content">
				<div className="ec-modal__header">
					<h2>{title}</h2>
					<button type="button" onClick={onClose} aria-label="Close">
						&times;
					</button>
				</div>
				<div className="ec-modal__body">{children}</div>
			</div>
		</div>
	);
}

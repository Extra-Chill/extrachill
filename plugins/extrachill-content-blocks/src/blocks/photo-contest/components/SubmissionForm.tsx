import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type { ContestConfig, Entry, SubmitResponse } from '../types';
import { getSavedEmail, saveEmail } from '../storage';
import { isValidEmail } from '../utils';

declare global {
	interface Window {
		turnstile?: { reset: () => void };
		ecTurnstileBoot?: () => void;
	}
}


export function SubmissionForm( {
	config,
	onSubmitted,
}: {
	config: ContestConfig;
	onSubmitted: ( entry: Entry ) => void;
} ) {
	const [ step, setStep ] = useState< 'email' | 'details' >( 'email' );
	const [ email, setEmail ] = useState( getSavedEmail() );
	const [ displayName, setDisplayName ] = useState( '' );
	const [ instagram, setInstagram ] = useState( '' );
	const [ honeypot, setHoneypot ] = useState( '' );
	const [ file, setFile ] = useState< File | null >( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const formRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		if ( step !== 'details' || ! config.turnstileHtml ) {
			return;
		}
		// The form mounts after the network runtime's initial page scan.
		// Wait for its bootstrap, then let it render the shared widget markup.
		const boot = () => {
			if ( ! window.turnstile || ! window.ecTurnstileBoot ) {
				return false;
			}
			window.ecTurnstileBoot();
			return true;
		};
		if ( boot() ) {
			return;
		}
		const timer = window.setInterval( () => {
			if ( boot() ) {
				window.clearInterval( timer );
			}
		}, 100 );
		return () => window.clearInterval( timer );
	}, [ step, config.turnstileHtml ] );

	const handleEmailNext = () => {
		const trimmed = email.trim();
		if ( ! trimmed || ! isValidEmail( trimmed ) ) {
			setError( 'Please enter a valid email address' );
			return;
		}
		saveEmail( trimmed );
		setError( '' );
		setStep( 'details' );
	};

	const handleSubmit = async () => {
		if ( isSubmitting ) {
			return;
		}
		if ( ! displayName.trim() ) {
			setError( 'Please enter a display name' );
			return;
		}
		if ( ! file ) {
			setError( 'Please choose a photo' );
			return;
		}
		setError( '' );
		setIsSubmitting( true );

		const formData = new FormData();
		formData.append( 'post_id', String( config.postId ) );
		formData.append( 'contest_id', config.contestId );
		formData.append( 'email', email.trim() );
		formData.append( 'display_name', displayName.trim() );
		formData.append( 'instagram', instagram.trim() );
		formData.append( 'website', honeypot );
		formData.append( 'photo', file );
		const turnstileInput =
			formRef.current?.querySelector< HTMLInputElement >(
				'input[name="cf-turnstile-response"]'
			);
		formData.append(
			'turnstile_response',
			turnstileInput?.value.trim() || ''
		);

		try {
			const response = ( await apiFetch( {
				path: '/extrachill/v1/content-blocks/photo-contest/submit',
				method: 'POST',
				body: formData,
			} ) ) as SubmitResponse;

			onSubmitted( response.entry );
		} catch ( err ) {
			const errorData = err as {
				code?: string;
				message?: string;
				data?: { entry?: Entry };
			};
			if (
				errorData.code === 'already_entered' &&
				errorData.data?.entry
			) {
				onSubmitted( errorData.data.entry );
			} else {
				setError( errorData.message || 'Submission failed' );
			}
		} finally {
			setIsSubmitting( false );
			if ( typeof window.turnstile?.reset === 'function' ) {
				try {
					window.turnstile.reset();
				} catch {
					// The widget may already be gone after a successful submission.
				}
			}
			if ( turnstileInput ) {
				turnstileInput.value = '';
			}
		}
	};

	return (
		<div ref={ formRef } className="extrachill-blocks-photo-contest-form">
			<h3>{ config.heading }</h3>
			{ step === 'email' && (
				<div className="extrachill-blocks-photo-contest-form-step">
					<input
						type="email"
						className="extrachill-blocks-photo-contest-input"
						placeholder="Your email to enter"
						value={ email }
						onChange={ ( e ) => setEmail( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								handleEmailNext();
							}
						} }
						required
					/>
					<button
						className="button-1 button-large"
						onClick={ handleEmailNext }
					>
						Enter the Contest
					</button>
				</div>
			) }
			{ step === 'details' && (
				<div className="extrachill-blocks-photo-contest-form-step">
					<input
						type="text"
						className="extrachill-blocks-photo-contest-input"
						placeholder="Display name"
						value={ displayName }
						onChange={ ( e ) => setDisplayName( e.target.value ) }
						required
					/>
					<input
						type="text"
						className="extrachill-blocks-photo-contest-input"
						placeholder="Instagram handle (optional)"
						value={ instagram }
						onChange={ ( e ) => setInstagram( e.target.value ) }
					/>
					{ /* Honeypot: hidden from humans, bots fill it. */ }
					<input
						type="text"
						className="extrachill-blocks-photo-contest-hp"
						tabIndex={ -1 }
						autoComplete="off"
						aria-hidden="true"
						value={ honeypot }
						onChange={ ( e ) => setHoneypot( e.target.value ) }
					/>
					<label
						className="extrachill-blocks-photo-contest-file-label"
						htmlFor={ `photo-contest-file-${ config.contestId }` }
					>
						{ file ? file.name : 'Choose your photo' }
						<input
							id={ `photo-contest-file-${ config.contestId }` }
							type="file"
							accept="image/*"
							className="extrachill-blocks-photo-contest-file"
							onChange={ ( e ) =>
								setFile( e.target.files?.[ 0 ] ?? null )
							}
						/>
					</label>
					<div
						dangerouslySetInnerHTML={ {
							__html: config.turnstileHtml,
						} }
					/>
					<button
						className="button-1 button-large"
						disabled={ isSubmitting }
						onClick={ handleSubmit }
					>
						{ isSubmitting ? 'Submitting...' : 'Submit Entry' }
					</button>
				</div>
			) }
			{ error && (
				<div className="notice notice-error extrachill-blocks-photo-contest-notice">
					{ error }
				</div>
			) }
		</div>
	);
}


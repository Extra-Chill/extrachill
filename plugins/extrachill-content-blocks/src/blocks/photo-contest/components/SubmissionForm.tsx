import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type { ContestConfig, Entry, SubmitResponse } from '../types';
import { getSavedEmail, saveEmail } from '../storage';
import { isValidEmail } from '../utils';
import { ContestDialog } from './ContestDialog';
import { SharePanel } from './SharePanel';

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
	const [ open, setOpen ] = useState( false );
	const [ submitted, setSubmitted ] = useState< Entry | null >( null );
	const [ alreadyEntered, setAlreadyEntered ] = useState( false );
	const [ preview, setPreview ] = useState( '' );
	const [ email, setEmail ] = useState( getSavedEmail() );
	const [ displayName, setDisplayName ] = useState( '' );
	const [ instagram, setInstagram ] = useState( '' );
	const [ honeypot, setHoneypot ] = useState( '' );
	const [ file, setFile ] = useState< File | null >( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const formRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		if ( ! open || ! file || submitted ) {
			setPreview( '' );
			return;
		}
		const url = URL.createObjectURL( file );
		setPreview( url );
		return () => URL.revokeObjectURL( url );
	}, [ open, file, submitted ] );

	useEffect( () => {
		if ( ! open || ! config.turnstileHtml ) {
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
	}, [ open, config.turnstileHtml ] );

	const handleSubmit = async () => {
		if ( isSubmitting ) {
			return;
		}
		if ( ! displayName.trim() ) {
			setError( 'Please enter a display name' );
			return;
		}
		if ( ! isValidEmail( email.trim() ) ) {
			setError( 'Please enter a valid email address' );
			return;
		}
		if ( ! file || ! file.type.startsWith( 'image/' ) ) {
			setError( 'Please choose an image file' );
			return;
		}
		saveEmail( email.trim() );
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

			setSubmitted( response.entry );
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
				setAlreadyEntered( true );
				setSubmitted( errorData.data.entry );
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

	const choosePhoto = ( selected?: File ) => {
		if ( ! selected ) return;
		setFile( selected );
		setError(
			selected.type.startsWith( 'image/' )
				? ''
				: 'Please choose an image file'
		);
		setOpen( true );
	};

	return (
		<div
			ref={ formRef }
			className={
				submitted ? '' : 'extrachill-blocks-photo-contest-form'
			}
		>
			{ ! submitted && (
				<>
					<h3>{ config.heading }</h3>
					<label className="button-1 button-large extrachill-blocks-photo-contest-upload">
						📸 Upload your costume photo
						<input
							type="file"
							accept="image/*"
							className="extrachill-blocks-photo-contest-file"
							onChange={ ( e ) => {
								choosePhoto( e.target.files?.[ 0 ] );
								e.target.value = '';
							} }
						/>
					</label>
					<p>Then add your name and email. Takes 30 seconds.</p>
				</>
			) }
			<ContestDialog open={ open } onClose={ () => setOpen( false ) }>
				{ submitted ? (
					<div className="extrachill-blocks-photo-contest-form-step">
						<h3>
							{ alreadyEntered
								? "You already entered, here's your entry"
								: `You're in, ${ submitted.display_name }! 🎉` }
						</h3>
						<img
							className="extrachill-blocks-photo-contest-preview"
							src={ submitted.image_url }
							alt={ `${ submitted.display_name }'s entry` }
						/>
						<SharePanel
							config={ config }
							entry={ submitted }
							prompt="Share this to get votes."
							initialShowQr
						/>
						<button
							className="button-2 button-large"
							onClick={ () => setOpen( false ) }
						>
							Done
						</button>
					</div>
				) : (
					open && (
						<form
							className="extrachill-blocks-photo-contest-form-step"
							onSubmit={ ( e ) => {
								e.preventDefault();
								handleSubmit();
							} }
						>
							{ preview && (
								<img
									className="extrachill-blocks-photo-contest-preview"
									src={ preview }
									alt="Your costume preview"
								/>
							) }
							<label className="button-3 button-small extrachill-blocks-photo-contest-upload">
								Change photo
								<input
									type="file"
									accept="image/*"
									className="extrachill-blocks-photo-contest-file"
									onChange={ ( e ) => {
										choosePhoto( e.target.files?.[ 0 ] );
										e.target.value = '';
									} }
								/>
							</label>
							<label>
								Name
								<input
									type="text"
									className="extrachill-blocks-photo-contest-input"
									placeholder="Name"
									autoComplete="name"
									value={ displayName }
									onChange={ ( e ) =>
										setDisplayName( e.target.value )
									}
									required
								/>
							</label>
							<label>
								Email
								<input
									type="email"
									autoComplete="email"
									required
									className="extrachill-blocks-photo-contest-input"
									value={ email }
									onChange={ ( e ) =>
										setEmail( e.target.value )
									}
								/>
							</label>
							<p>
								So we can reach you if you win. One entry per
								email.
							</p>
							<label>
								Instagram (optional)
								<input
									type="text"
									className="extrachill-blocks-photo-contest-input"
									placeholder="Instagram handle (optional)"
									value={ instagram }
									onChange={ ( e ) =>
										setInstagram( e.target.value )
									}
								/>
							</label>
							{ /* Honeypot: hidden from humans, bots fill it. */ }
							<input
								type="text"
								className="extrachill-blocks-photo-contest-hp"
								tabIndex={ -1 }
								autoComplete="off"
								aria-hidden="true"
								value={ honeypot }
								onChange={ ( e ) =>
									setHoneypot( e.target.value )
								}
							/>
							<div
								dangerouslySetInnerHTML={ {
									__html: config.turnstileHtml,
								} }
							/>
							<button
								className="button-1 button-large"
								disabled={ isSubmitting }
								type="submit"
							>
								{ isSubmitting
									? 'Uploading your photo...'
									: 'Enter my costume' }
							</button>
						</form>
					)
				) }
				{ error && (
					<div className="notice notice-error extrachill-blocks-photo-contest-notice">
						{ error }
					</div>
				) }
			</ContestDialog>
		</div>
	);
}

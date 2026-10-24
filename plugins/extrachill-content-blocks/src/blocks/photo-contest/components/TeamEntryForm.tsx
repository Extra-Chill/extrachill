import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type { ContestConfig, Entry, SubmitResponse } from '../types';
import { isValidEmail } from '../utils';
import { ContestDialog } from './ContestDialog';
import { shrinkPhoto } from '../image';
import { SharePanel } from './SharePanel';

/**
 * Moderator backup path: add an entry for someone else. Same dialog as the
 * public form, but email is optional and there is no Turnstile (the route
 * trusts the moderator's REST nonce). Nothing is saved as "my entry".
 */
export function TeamEntryForm( {
	config,
	onAdded,
}: {
	config: ContestConfig;
	onAdded: () => void;
} ) {
	const [ open, setOpen ] = useState( false );
	const [ added, setAdded ] = useState< Entry | null >( null );
	const [ file, setFile ] = useState< File | null >( null );
	const [ preview, setPreview ] = useState( '' );
	const [ displayName, setDisplayName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ instagram, setInstagram ] = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const statusRef = useRef< HTMLDivElement | null >( null );
	useEffect( () => {
		if ( isSubmitting || error ) {
			statusRef.current?.scrollIntoView( { block: 'start' } );
		}
	}, [ isSubmitting, error ] );
	const pageFileRef = useRef< HTMLInputElement | null >( null );
	const dialogFileRef = useRef< HTMLInputElement | null >( null );

	useEffect( () => {
		if ( ! file ) {
			setPreview( '' );
			return;
		}
		const url = URL.createObjectURL( file );
		setPreview( url );
		return () => URL.revokeObjectURL( url );
	}, [ file ] );

	const reset = () => {
		setAdded( null );
		setFile( null );
		setDisplayName( '' );
		setEmail( '' );
		setInstagram( '' );
		setError( '' );
	};

	const choosePhoto = ( selected?: File ) => {
		if ( ! selected ) return;
		setAdded( null );
		setFile( selected );
		setError(
			selected.type.startsWith( 'image/' )
				? ''
				: 'Please choose an image file'
		);
		setOpen( true );
	};

	const handleSubmit = async () => {
		if ( isSubmitting ) return;
		if ( ! displayName.trim() ) {
			setError( 'Please enter their name' );
			return;
		}
		if ( email.trim() && ! isValidEmail( email.trim() ) ) {
			setError( 'That email looks wrong. Leave it blank if unsure.' );
			return;
		}
		if ( ! file || ! file.type.startsWith( 'image/' ) ) {
			setError( 'Please choose an image file' );
			return;
		}
		setError( '' );
		setIsSubmitting( true );
		const formData = new FormData();
		formData.append( 'post_id', String( config.postId ) );
		formData.append( 'contest_id', config.contestId );
		if ( email.trim() ) formData.append( 'email', email.trim() );
		formData.append( 'display_name', displayName.trim() );
		formData.append( 'instagram', instagram.trim() );
		formData.append( 'photo', await shrinkPhoto( file ) );
		try {
			const response = ( await apiFetch( {
				path: '/extrachill/v1/content-blocks/photo-contest/submit',
				method: 'POST',
				headers: { 'X-WP-Nonce': config.restNonce },
				body: formData,
			} ) ) as SubmitResponse;
			setAdded( response.entry );
			onAdded();
		} catch ( err ) {
			setError(
				( err as { message?: string } )?.message ||
					'Could not add the entry'
			);
		} finally {
			setIsSubmitting( false );
		}
	};

	const fileInput = ( ref: typeof pageFileRef ) => (
		<input
			ref={ ref }
			type="file"
			accept="image/*"
			className="extrachill-blocks-photo-contest-file"
			onChange={ ( e ) => {
				choosePhoto( e.target.files?.[ 0 ] );
				e.target.value = '';
			} }
		/>
	);

	return (
		<>
			<button
				type="button"
				className="button-3 button-small"
				onClick={ () => pageFileRef.current?.click() }
			>
				Add an entry for someone
			</button>
			{ fileInput( pageFileRef ) }
			<ContestDialog
				open={ open }
				onClose={ () => {
					setOpen( false );
					if ( added ) reset();
				} }
			>
				{ added ? (
					<div className="extrachill-blocks-photo-contest-form-step">
						<h3>Added { added.display_name } 🎉</h3>
						<img
							className="extrachill-blocks-photo-contest-preview"
							src={ added.image_url }
							alt={ `${ added.display_name }'s entry` }
						/>
						<SharePanel
							config={ config }
							entry={ added }
							prompt="Let them scan this to share and vote."
							initialShowQr
						/>
						<button
							type="button"
							className="button-1 button-large"
							onClick={ () => {
								reset();
								dialogFileRef.current?.click();
							} }
						>
							Add another
						</button>
						{ fileInput( dialogFileRef ) }
						<button
							type="button"
							className="button-2 button-large"
							onClick={ () => {
								setOpen( false );
								reset();
							} }
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
							<h3>Add an entry</h3>
							<div ref={ statusRef } aria-live="polite">
								{ isSubmitting && (
									<div className="notice notice-info extrachill-blocks-photo-contest-notice">
										⏳ Uploading the photo… keep this screen
										open. On cell data this can take up to
										30 seconds.
									</div>
								) }
								{ error && ! isSubmitting && (
									<div className="notice notice-error extrachill-blocks-photo-contest-notice">
										{ error }
									</div>
								) }
							</div>
							{ preview && (
								<img
									className="extrachill-blocks-photo-contest-preview"
									src={ preview }
									alt="Costume preview"
								/>
							) }
							<button
								type="button"
								className="button-3 button-small extrachill-blocks-photo-contest-upload"
								onClick={ () => dialogFileRef.current?.click() }
							>
								Change photo
							</button>
							{ fileInput( dialogFileRef ) }
							<label>
								Their name
								<input
									type="text"
									className="extrachill-blocks-photo-contest-input"
									value={ displayName }
									onChange={ ( e ) =>
										setDisplayName( e.target.value )
									}
									required
								/>
							</label>
							<label>
								Their email (optional)
								<input
									type="email"
									className="extrachill-blocks-photo-contest-input"
									value={ email }
									onChange={ ( e ) =>
										setEmail( e.target.value )
									}
								/>
							</label>
							<label>
								Instagram (optional)
								<input
									type="text"
									className="extrachill-blocks-photo-contest-input"
									value={ instagram }
									onChange={ ( e ) =>
										setInstagram( e.target.value )
									}
								/>
							</label>
							<button
								type="submit"
								className="button-1 button-large"
								disabled={ isSubmitting }
							>
								{ isSubmitting
									? 'Uploading the photo…'
									: 'Add entry' }
							</button>
						</form>
					)
				) }
			</ContestDialog>
		</>
	);
}

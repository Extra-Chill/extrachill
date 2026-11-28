/**
 * Build a font from a letter sheet using the Sweatpants font-builder module.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import {
	useCallback,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
/**
 * External dependencies
 */
import type { FormEvent } from 'react';
/**
 * Internal dependencies
 */
import {
	createSweatpantsClient,
	MAX_UPLOAD_BYTES,
} from '../../sweatpants/client';
import type { SweatpantsJob } from '../../sweatpants/types';
import { isTerminal, useJobPolling } from '../../sweatpants/useJobPolling';
import {
	buildFontJob,
	downloadFont,
	FONT_NAME_PATTERN,
	fontJobError,
	STARTER_GLYPH_MAP,
} from './font-builder';
import type { FontFormat, FontResult } from './font-builder';

const { upload, submitJob, getJob, getResults } = createSweatpantsClient();
const IMAGE_TYPES = [ 'image/jpeg', 'image/png', 'image/webp' ];

const errorMessage = ( error: unknown, fallback: string ): string =>
	( error as Error )?.message || fallback;

export default function FontsPane() {
	const fieldId = useId();
	const [ fontName, setFontName ] = useState( '' );
	const [ glyphMap, setGlyphMap ] = useState( STARTER_GLYPH_MAP );
	const [ stage, setStage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState< {
		name: string;
		data: FontResult;
	} | null >( null );
	const mounted = useRef( false );
	const activeJob = useRef< { id: string; name: string } | null >( null );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const getActiveJobId = useCallback( () => activeJob.current?.id, [] );
	const onJob = useCallback( async ( job: SweatpantsJob ) => {
		setError( '' );
		const current = activeJob.current;
		if ( ! current || ! isTerminal( job.status ) ) {
			return;
		}
		activeJob.current = null;

		try {
			if ( job.status !== 'completed' ) {
				throw new Error( fontJobError( job.error ) );
			}
			setStage( __( 'Fetching font files…', 'extrachill-studio' ) );
			const response = await getResults< FontResult >( job.id );
			const data = response.results?.[ 0 ]?.data;
			if (
				! data?.content?.preview ||
				! data.content.otf ||
				! data.content.ttf ||
				typeof data.stats?.glyphs !== 'number'
			) {
				throw new Error(
					__(
						'The font build did not return a preview and both font files.',
						'extrachill-studio'
					)
				);
			}
			if ( mounted.current ) {
				setResult( { name: current.name, data } );
			}
		} catch ( jobError ) {
			if ( mounted.current ) {
				setError( errorMessage( jobError, fontJobError( null ) ) );
			}
		} finally {
			if ( mounted.current ) {
				setStage( '' );
			}
		}
	}, [] );

	const onPollError = useCallback( ( pollError: unknown ) => {
		setError(
			errorMessage(
				pollError,
				__( 'Could not check the job. Retrying…', 'extrachill-studio' )
			)
		);
	}, [] );
	const startPolling = useJobPolling(
		getJob,
		getActiveJobId,
		onJob,
		onPollError
	);

	const build = async ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		if ( stage ) {
			return;
		}
		setError( '' );
		const file = new FormData( event.currentTarget ).get( 'sheet' );
		if (
			! ( file instanceof File ) ||
			! file.size ||
			! IMAGE_TYPES.includes( file.type )
		) {
			setError(
				__( 'Choose a JPG, PNG, or WebP image.', 'extrachill-studio' )
			);
			return;
		}
		if ( file.size > MAX_UPLOAD_BYTES ) {
			setError(
				__(
					'Choose an image no larger than 500 MB.',
					'extrachill-studio'
				)
			);
			return;
		}
		const name = fontName.trim();
		if ( ! name || ! glyphMap.trim() ) {
			setError(
				__( 'Enter a font name and glyph map.', 'extrachill-studio' )
			);
			return;
		}

		setResult( null );
		try {
			setStage( __( 'Uploading image…', 'extrachill-studio' ) );
			const { path } = await upload( file );
			if ( ! mounted.current ) {
				return;
			}
			setStage( __( 'Submitting font build…', 'extrachill-studio' ) );
			const job = await submitJob(
				buildFontJob( { uploadPath: path, fontName: name, glyphMap } )
			);
			if ( ! mounted.current ) {
				return;
			}
			activeJob.current = { id: job.id, name };
			setStage(
				__(
					'Building font… Keep this tab open to download your files.',
					'extrachill-studio'
				)
			);
			startPolling();
		} catch ( buildError ) {
			if ( mounted.current ) {
				setError( errorMessage( buildError, fontJobError( null ) ) );
				setStage( '' );
			}
		}
	};

	const download = ( format: FontFormat ) => {
		if ( ! result ) {
			return;
		}
		setError( '' );
		try {
			downloadFont( result.data.content[ format ], result.name, format );
		} catch {
			setError(
				__( 'Could not download the font file.', 'extrachill-studio' )
			);
		}
	};

	return (
		<div>
			<form className="card" onSubmit={ build }>
				<p>
					{ __(
						'Build a custom font from a photo of a letter sheet.',
						'extrachill-studio'
					) }
				</p>
				<p>
					<label htmlFor={ `${ fieldId }-sheet` }>
						{ __(
							'Letter sheet (JPG, PNG, or WebP)',
							'extrachill-studio'
						) }
						<br />
						<input
							id={ `${ fieldId }-sheet` }
							type="file"
							name="sheet"
							accept={ IMAGE_TYPES.join( ',' ) }
							required
							disabled={ !! stage }
						/>
					</label>
				</p>
				<p>
					<label htmlFor={ `${ fieldId }-name` }>
						{ __(
							'Font name (letters, numbers, spaces, hyphens, or underscores)',
							'extrachill-studio'
						) }
						<input
							id={ `${ fieldId }-name` }
							type="text"
							value={ fontName }
							pattern={ FONT_NAME_PATTERN }
							onChange={ ( event ) =>
								setFontName( event.target.value )
							}
							required
							disabled={ !! stage }
						/>
					</label>
				</p>
				<p>
					<label htmlFor={ `${ fieldId }-map` }>
						{ __( 'Glyph map', 'extrachill-studio' ) }
						<br />
						<small>
							{ __(
								'Use one line per sheet row. Separate glyph names with spaces, in reading order. Case matters, and X.alt is an alternate for X.',
								'extrachill-studio'
							) }
						</small>
						<br />
						<small>
							{ __(
								'Example: A a X X.alt ampersand exclam question period comma',
								'extrachill-studio'
							) }
						</small>
						<textarea
							id={ `${ fieldId }-map` }
							rows={ 4 }
							value={ glyphMap }
							onChange={ ( event ) =>
								setGlyphMap( event.target.value )
							}
							required
							spellCheck={ false }
							disabled={ !! stage }
						/>
					</label>
				</p>
				<button
					type="submit"
					className="button-1 button-medium"
					disabled={ !! stage }
				>
					{ __( 'Build font', 'extrachill-studio' ) }
				</button>
			</form>
			{ stage && (
				<p className="notice notice-info" role="status">
					{ stage }
				</p>
			) }
			{ error && (
				<p className="notice notice-error" role="alert">
					{ error }
				</p>
			) }
			{ result && (
				<section className="card">
					<h3>{ result.name }</h3>
					<p role="status">
						{ sprintf(
							/* translators: %d: number of glyphs in the font. */
							_n(
								'%d glyph',
								'%d glyphs',
								result.data.stats.glyphs,
								'extrachill-studio'
							),
							result.data.stats.glyphs
						) }
					</p>
					<img
						src={ `data:image/png;base64,${ result.data.content.preview }` }
						alt={ __(
							'Preview of the generated font',
							'extrachill-studio'
						) }
					/>
					<p>
						<button
							type="button"
							className="button-2 button-medium"
							onClick={ () => download( 'otf' ) }
						>
							{ __( 'Download .otf', 'extrachill-studio' ) }
						</button>{ ' ' }
						<button
							type="button"
							className="button-2 button-medium"
							onClick={ () => download( 'ttf' ) }
						>
							{ __( 'Download .ttf', 'extrachill-studio' ) }
						</button>
					</p>
					<p>
						{ __(
							'Upload the .otf in Canva under Brand Kit → Brand fonts.',
							'extrachill-studio'
						) }
					</p>
				</section>
			) }
		</div>
	);
}

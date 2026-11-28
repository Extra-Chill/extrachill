/**
 * Poll a Sweatpants job immediately, every five seconds, and on refocus.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef } from '@wordpress/element';
/**
 * Internal dependencies
 */
import type { SweatpantsJob, SweatpantsJobStatus } from './types';

const POLL_INTERVAL_MS = 5000;

export const isTerminal = ( status: SweatpantsJobStatus ): boolean =>
	status === 'completed' || status === 'failed' || status === 'stopped';

export const useJobPolling = (
	getJob: ( id: string ) => Promise< SweatpantsJob >,
	getActiveJobId: () => string | undefined,
	onJob: ( job: SweatpantsJob ) => void | Promise< void >,
	onError: ( error: unknown ) => void
) => {
	const timer = useRef< ReturnType< typeof setInterval > | null >( null );
	const stopPolling = useCallback( () => {
		if ( timer.current !== null ) {
			clearInterval( timer.current );
			timer.current = null;
		}
	}, [] );

	const pollOnce = useCallback( async () => {
		const id = getActiveJobId();
		const currentTimer = timer.current;
		if (
			! id ||
			currentTimer === null ||
			document.visibilityState === 'hidden'
		) {
			return;
		}
		try {
			const job = await getJob( id );
			if ( timer.current !== currentTimer ) {
				return;
			}
			if ( isTerminal( job.status ) ) {
				stopPolling();
			}
			await onJob( job );
		} catch ( error ) {
			// A network blip doesn't stop a running job.
			if ( timer.current === currentTimer ) {
				onError( error );
			}
		}
	}, [ getActiveJobId, getJob, onJob, onError, stopPolling ] );

	const startPolling = useCallback( () => {
		stopPolling();
		timer.current = setInterval( pollOnce, POLL_INTERVAL_MS );
		void pollOnce();
	}, [ pollOnce, stopPolling ] );

	useEffect( () => {
		const onVisibilityChange = () => {
			if ( document.visibilityState === 'visible' ) {
				void pollOnce();
			}
		};
		document.addEventListener( 'visibilitychange', onVisibilityChange );
		return () => {
			document.removeEventListener(
				'visibilitychange',
				onVisibilityChange
			);
		};
	}, [ pollOnce ] );

	useEffect( () => stopPolling, [ stopPolling ] );

	return startPolling;
};

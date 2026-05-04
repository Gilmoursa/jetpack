import debounce from 'debounce';
import { useCallback, useEffect, useRef, useState } from 'react';
import { SERVER_OBJECT_NAME } from '../lib/constants';

/**
 * Fetches search query suggestions from the WPCOM suggestions API.
 *
 * @param {object}  args         - Arguments.
 * @param {string}  args.query   - Current input value.
 * @param {string}  args.siteId  - The site ID used in the API URL.
 * @param {boolean} args.enabled - Whether suggestions are enabled.
 * @return {{ suggestions: string[], isLoading: boolean }} Suggestions state.
 */
export default function useSearchSuggestions( { query, siteId, enabled } ) {
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const abortRef = useRef( null );

	// eslint-disable-next-line react-hooks/exhaustive-deps
	const fetchSuggestions = useCallback(
		debounce( async ( q, sId ) => {
			if ( ! q || q.length < 2 || ! sId ) {
				setSuggestions( [] );
				return;
			}

			if ( abortRef.current ) {
				abortRef.current.abort();
			}
			abortRef.current = new AbortController();
			setIsLoading( true );

			try {
				const { apiNonce, homeUrl, isPrivateSite, isWpcom } = window[ SERVER_OBJECT_NAME ] ?? {};
				// eslint-disable-next-line no-console
				console.log( '[search-suggestions] routing', {
					isPrivateSite,
					isWpcom,
					homeUrl,
					hasNonce: !! apiNonce,
				} );
				const path = `/${ encodeURIComponent(
					sId
				) }/search-suggestions?query=${ encodeURIComponent( q ) }&size=5`;
				const url =
					isPrivateSite && isWpcom
						? `${ homeUrl }/wp-json/wpcom-origin/wpcom/v2/sites${ path }`
						: `https://public-api.wordpress.com/wpcom/v2/sites${ path }`;
				// eslint-disable-next-line no-console
				console.log( '[search-suggestions] url', url );
				const fetchOptions = {
					signal: abortRef.current.signal,
					...( isPrivateSite && {
						headers: { 'X-WP-Nonce': apiNonce },
						credentials: 'include',
					} ),
				};
				const response = await fetch( url, fetchOptions );
				if ( ! response.ok ) {
					setSuggestions( [] );
					return;
				}
				const data = await response.json();
				const items = Array.isArray( data ) ? data : data.suggestions ?? data.results ?? [];
				setSuggestions(
					items
						.map( item => ( typeof item === 'string' ? item : item.query ?? item.text ?? '' ) )
						.filter( Boolean )
				);
			} catch ( err ) {
				if ( err.name !== 'AbortError' ) {
					setSuggestions( [] );
				}
			} finally {
				setIsLoading( false );
			}
		}, 50 ),
		[]
	);

	useEffect( () => {
		if ( ! enabled ) {
			setSuggestions( [] );
			return;
		}
		fetchSuggestions( query, siteId );
	}, [ query, siteId, enabled, fetchSuggestions ] );

	useEffect( () => {
		return () => {
			fetchSuggestions.clear?.();
			if ( abortRef.current ) {
				abortRef.current.abort();
			}
		};
	}, [ fetchSuggestions ] );

	return { suggestions, isLoading };
}

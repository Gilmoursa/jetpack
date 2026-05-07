import debounce from 'debounce';
import { useCallback, useEffect, useRef, useState } from 'react';
import { SERVER_OBJECT_NAME } from '../lib/constants';

/**
 * @typedef {object} SuggestionItem
 * @property {'query'|'post'|'taxonomy'} type  - The kind of suggestion.
 * @property {string}                    text  - Display text.
 * @property {string}                    [url] - Navigation URL (post and taxonomy types).
 */

// Maps the API's `type` query param to our internal SuggestionItem type.
const API_TYPE_MAP = { query: 'query', post_title: 'post', taxonomy: 'taxonomy' };

/**
 * Fetches suggestions for a single type from the WPCOM suggestions API.
 *
 * @param {string}      q       - Search query.
 * @param {string}      sId     - Site ID.
 * @param {string}      type    - API type param ('query', 'post_title', or 'taxonomy').
 * @param {object}      options - Server options (apiNonce, homeUrl, isPrivateSite, isWpcom).
 * @param {AbortSignal} signal  - Abort signal.
 * @return {Promise<SuggestionItem[]>} Resolved suggestion items for this type.
 */
async function fetchType( q, sId, type, options, signal ) {
	const { apiNonce, homeUrl, isPrivateSite, isWpcom } = options;
	const path = `/${ encodeURIComponent( sId ) }/search-suggestions?query=${ encodeURIComponent(
		q
	) }&size=5&type=${ type }`;
	const url =
		isPrivateSite && isWpcom
			? `${ homeUrl }/wp-json/wpcom-origin/wpcom/v2/sites${ path }`
			: `https://public-api.wordpress.com/wpcom/v2/sites${ path }`;
	const fetchOptions = {
		signal,
		...( isPrivateSite && {
			headers: { 'X-WP-Nonce': apiNonce },
			credentials: 'include',
		} ),
	};
	const response = await fetch( url, fetchOptions );
	if ( ! response.ok ) {
		return [];
	}
	const data = await response.json();
	const items = Array.isArray( data ) ? data : data.suggestions ?? [];
	const internalType = API_TYPE_MAP[ type ] ?? 'query';
	return items
		.map( item => {
			const text = item.text ?? '';
			if ( ! text ) {
				return null;
			}
			if ( internalType === 'post' || internalType === 'taxonomy' ) {
				const itemUrl = item.url ?? null;
				if ( ! itemUrl ) {
					return null;
				}
				return { type: internalType, text, url: itemUrl };
			}
			return { type: 'query', text };
		} )
		.filter( Boolean );
}

/**
 * Fetches search query suggestions from the WPCOM suggestions API.
 *
 * @param {object}  args         - Arguments.
 * @param {string}  args.query   - Current input value.
 * @param {string}  args.siteId  - The site ID used in the API URL.
 * @param {boolean} args.enabled - Whether suggestions are enabled.
 * @return {{ suggestions: SuggestionItem[], isLoading: boolean }} Suggestions state.
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
				const options = window[ SERVER_OBJECT_NAME ] ?? {};
				const results = await Promise.all(
					[ 'query', 'post_title', 'taxonomy' ].map( type =>
						fetchType( q, sId, type, options, abortRef.current.signal )
					)
				);
				setSuggestions( results.flat() );
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

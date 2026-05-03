import * as React from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import useSearchSuggestions from '../hooks/use-search-suggestions';
import SearchBox from './search-box';
import SearchSuggestions from './search-suggestions';

/**
 * Search form with optional autocomplete suggestions dropdown.
 *
 * @param {object}   props                      - Component props.
 * @param {string}   props.searchQuery          - Committed search query (from Redux).
 * @param {Function} props.onChangeSearch       - Callback to commit a new query.
 * @param {boolean}  props.isVisible            - Whether the overlay is visible.
 * @param {string}   props.className            - Optional CSS class for the form element.
 * @param {boolean}  props.suggestionsEnabled   - When true, show autocomplete dropdown instead of search-as-you-type.
 * @param {string}   props.siteId               - Site ID used for the suggestions API.
 * @return {React.ReactElement} The search form.
 */
export default function SearchForm( {
	searchQuery,
	onChangeSearch,
	isVisible,
	className,
	suggestionsEnabled = false,
	siteId = null,
} ) {
	const searchInputRef = useRef( null );

	// Local input value used only in suggestions mode.
	const [ localQuery, setLocalQuery ] = useState( searchQuery );
	const [ showSuggestions, setShowSuggestions ] = useState( false );
	const [ activeIndex, setActiveIndex ] = useState( -1 );

	// Keep localQuery in sync when the committed query changes externally
	// (e.g. user navigates back, query cleared from outside).
	useEffect( () => {
		setLocalQuery( searchQuery );
	}, [ searchQuery ] );

	const { suggestions } = useSearchSuggestions( {
		query: suggestionsEnabled ? localQuery : '',
		siteId,
		enabled: suggestionsEnabled,
	} );

	const onClear = useCallback( () => {
		if ( suggestionsEnabled ) {
			setLocalQuery( '' );
			setShowSuggestions( false );
			setActiveIndex( -1 );
		}
		onChangeSearch( '' );
	}, [ suggestionsEnabled, onChangeSearch ] );

	const handleChange = useCallback(
		event => {
			// Safari's "Use advanced tracking and fingerprinting protection" privacy setting
			// can block access to event.currentTarget.value, returning empty/undefined.
			// In such cases, fall back to reading the value directly from the input element via ref.
			let value;
			try {
				value = event.currentTarget.value;
				if ( value === undefined || value === null ) {
					throw new Error( 'Event value blocked by browser privacy settings' );
				}
			} catch {
				value = searchInputRef.current?.value ?? '';
			}

			if ( suggestionsEnabled ) {
				setLocalQuery( value );
				setShowSuggestions( value.length >= 2 );
				setActiveIndex( -1 );
			} else {
				onChangeSearch( value );
			}
		},
		[ suggestionsEnabled, onChangeSearch ]
	);

	const handleSelectSuggestion = useCallback(
		suggestion => {
			setLocalQuery( suggestion );
			setShowSuggestions( false );
			setActiveIndex( -1 );
			onChangeSearch( suggestion );
		},
		[ onChangeSearch ]
	);

	const handleKeyDown = useCallback(
		event => {
			if ( ! suggestionsEnabled ) {
				return;
			}
			const count = suggestions.length;
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					setShowSuggestions( true );
					setActiveIndex( i => ( i < count - 1 ? i + 1 : i ) );
					break;
				case 'ArrowUp':
					event.preventDefault();
					setActiveIndex( i => ( i > 0 ? i - 1 : -1 ) );
					break;
				case 'Enter':
					if ( showSuggestions && activeIndex >= 0 && activeIndex < count ) {
						event.preventDefault();
						handleSelectSuggestion( suggestions[ activeIndex ] );
					} else if ( showSuggestions ) {
						// Commit the typed query and run search.
						setShowSuggestions( false );
						onChangeSearch( localQuery );
					}
					break;
				case 'Escape':
					setShowSuggestions( false );
					setActiveIndex( -1 );
					break;
				default:
					break;
			}
		},
		[ suggestionsEnabled, suggestions, showSuggestions, activeIndex, localQuery, handleSelectSuggestion, onChangeSearch ]
	);

	const handleBlur = useCallback( () => {
		// Small delay so a click on a suggestion fires before the list is removed.
		setTimeout( () => {
			setShowSuggestions( false );
			setActiveIndex( -1 );
		}, 150 );
	}, [] );

	const noop = event => event.preventDefault();
	const displayQuery = suggestionsEnabled ? localQuery : searchQuery;

	return (
		<form
			autoComplete="off"
			onSubmit={ noop }
			role="search"
			className={ className }
			style={ { position: 'relative' } }
		>
			<div className="jetpack-instant-search__search-form">
				<SearchBox
					ref={ searchInputRef }
					isVisible={ isVisible }
					onChange={ handleChange }
					onClear={ onClear }
					onKeyDown={ suggestionsEnabled ? handleKeyDown : undefined }
					onBlur={ suggestionsEnabled ? handleBlur : undefined }
					shouldRestoreFocus
					searchQuery={ displayQuery }
				/>
				{ suggestionsEnabled && showSuggestions && suggestions.length > 0 && (
					<SearchSuggestions
						suggestions={ suggestions }
						activeIndex={ activeIndex }
						onSelect={ handleSelectSuggestion }
					/>
				) }
			</div>
		</form>
	);
}

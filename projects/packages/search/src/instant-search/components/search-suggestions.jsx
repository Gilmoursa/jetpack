import { __ } from '@wordpress/i18n';
import * as React from 'react';
import './search-suggestions.scss';

/**
 * Dropdown list of autocomplete query suggestions.
 *
 * @param {object}   props               - Component props.
 * @param {string[]} props.suggestions   - Array of suggestion strings.
 * @param {number}   props.activeIndex   - Index of the keyboard-highlighted suggestion (-1 for none).
 * @param {Function} props.onSelect      - Called with the selected suggestion string.
 * @return {React.ReactElement|null} The rendered suggestions list or null.
 */
export default function SearchSuggestions( { suggestions, activeIndex, onSelect } ) {
	if ( ! suggestions || suggestions.length === 0 ) {
		return null;
	}

	return (
		<ul
			className="jetpack-instant-search__search-suggestions"
			role="listbox"
			aria-label={ __( 'Search suggestions', 'jetpack-search-pkg' ) }
		>
			{ suggestions.map( ( suggestion, index ) => (
				<li
					key={ index }
					className={
						'jetpack-instant-search__search-suggestion' +
						( index === activeIndex ? ' is-active' : '' )
					}
					role="option"
					aria-selected={ index === activeIndex }
					// mousedown fires before blur; preventDefault keeps the input focused
					// so the click handler can run before the input loses focus.
					onMouseDown={ e => e.preventDefault() }
					onClick={ () => onSelect( suggestion ) }
				>
					{ suggestion }
				</li>
			) ) }
		</ul>
	);
}

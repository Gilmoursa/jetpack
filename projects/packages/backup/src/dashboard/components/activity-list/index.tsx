import { SearchControl, Spinner } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { settings as settingsIcon } from '@wordpress/icons';
import { Button, Card, Stack } from '@wordpress/ui';
import { useMockActivityLog } from '../../hooks/use-mock-activity-log';
import ActivityRow from '../activity-row';
import './style.scss';
import type { ActivityItem } from '../../types/activity';

type Props = {
	selectedId: string | null;
	onSelect: ( id: string ) => void;
};

/**
 * Left pane of the modernized Overview: a searchable, scrollable activity list.
 *
 * Owns its own search state and reads items from the mock hook. Selection is
 * owned by the parent so it can be reflected in the URL and used by the
 * right-hand detail pane.
 *
 * @param props            - Component props.
 * @param props.selectedId - Currently selected row id, or null when nothing is selected.
 * @param props.onSelect   - Callback invoked with the new selection id when a row is activated.
 * @return The rendered activity list card.
 */
export default function ActivityList( { selectedId, onSelect }: Props ) {
	const [ search, setSearch ] = useState( '' );
	const { items, isLoading } = useMockActivityLog( {
		page: 1,
		pageSize: 100,
		search,
	} );

	const handleSearchChange = useCallback( ( next: string ) => {
		setSearch( next );
	}, [] );

	return (
		<Card.Root className="jpb-activity-list">
			<Stack direction="row" gap="sm" align="center" className="jpb-activity-list__header">
				<SearchControl
					value={ search }
					onChange={ handleSearchChange }
					label={ __( 'Search backups', 'jetpack-backup-pkg' ) }
					placeholder={ __( 'Search backups', 'jetpack-backup-pkg' ) }
					__nextHasNoMarginBottom
				/>
				<Button
					variant="minimal"
					tone="neutral"
					size="small"
					aria-label={ __( 'Filter activity', 'jetpack-backup-pkg' ) }
				>
					<Button.Icon icon={ settingsIcon } />
				</Button>
			</Stack>
			<div className="jpb-activity-list__rows" aria-busy={ isLoading }>
				{ isLoading ? (
					<div className="jpb-activity-list__loading">
						<Spinner />
					</div>
				) : (
					items.map( ( item: ActivityItem ) => (
						<ActivityRow
							key={ item.id }
							item={ item }
							isSelected={
								selectedId !== null &&
								( item.kind === 'backup' ? item.rewindId : item.id ) === selectedId
							}
							onSelect={ onSelect }
						/>
					) )
				) }
			</div>
		</Card.Root>
	);
}

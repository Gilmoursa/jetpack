import { Page } from '@wordpress/admin-ui';
import { __ } from '@wordpress/i18n';
import { Icon, cloud } from '@wordpress/icons';
import { Stack, Text } from '@wordpress/ui';
import DevModeBanner from '../dev-mode-banner';
import './style.scss';
import type { ReactNode } from 'react';

type Props = {
	children: ReactNode;
	actions?: ReactNode;
};

const PRODUCT_NAME = 'VaultPress Backup'; // Product name; do not translate.

const title = (
	<Stack direction="row" align="center" gap="sm">
		<span className="jpb-dashboard-layout__title-icon" aria-hidden="true">
			<Icon icon={ cloud } size={ 20 } />
		</span>
		<span>{ PRODUCT_NAME }</span>
	</Stack>
);

/**
 * Footer rendered at the bottom of every modernized Backup page.
 *
 * Inlined here (rather than reaching for `@automattic/jetpack-components`'s
 * `<JetpackFooter>`) because that package's SCSS uses sass-embedded's
 * `pkg:` import scheme, which the wp-build sass-plugin in this worktree
 * doesn't resolve. The visual content matches the legacy admin's footer.
 *
 * @return The rendered footer.
 */
function DashboardFooter() {
	return (
		<Stack
			direction="row"
			align="center"
			justify="space-between"
			className="jpb-dashboard-layout__footer"
		>
			<Stack direction="row" align="center" gap="md">
				<Text weight="600">Jetpack</Text>
				<a className="jpb-dashboard-layout__footer-link" href="admin.php?page=my-jetpack#/products">
					{ __( 'Products', 'jetpack-backup-pkg' ) }
				</a>
				<a className="jpb-dashboard-layout__footer-link" href="admin.php?page=my-jetpack#/help">
					{ __( 'Help', 'jetpack-backup-pkg' ) }
				</a>
			</Stack>
			<Text size="small" variant="muted" className="jpb-dashboard-layout__footer-byline">
				{ __( 'An Automattic Airline', 'jetpack-backup-pkg' ) }
			</Text>
		</Stack>
	);
}

/**
 * Shared shell for every screen of the modernized Backup dashboard.
 *
 * Wraps a `<Page>` from `@wordpress/admin-ui` (which provides the
 * standard wp-admin chrome) with the dev-mode banner, the page body, and
 * a footer matching the legacy admin's Jetpack | Products | Help line.
 *
 * @param props          - Component props.
 * @param props.children - Screen contents to render inside the page body.
 * @param props.actions  - Optional nodes rendered in the page header's top-right action slot.
 * @return The rendered dashboard shell.
 */
export default function DashboardLayout( { children, actions }: Props ) {
	return (
		<Page
			title={ title }
			ariaLabel={ PRODUCT_NAME }
			subTitle={ __(
				'Save changes and restore quickly with one-click recovery.',
				'jetpack-backup-pkg'
			) }
			hasPadding={ false }
			actions={ actions }
		>
			<DevModeBanner />
			<div className="jpb-dashboard-body">{ children }</div>
			<DashboardFooter />
		</Page>
	);
}

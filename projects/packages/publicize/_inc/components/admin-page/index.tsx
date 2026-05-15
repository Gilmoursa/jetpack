import {
	AdminPage,
	AdminSectionHero,
	Container,
	Col,
	GlobalNotices,
} from '@automattic/jetpack-components';
import {
	ConnectionError,
	useConnection,
	useConnectionErrorNotice,
} from '@automattic/jetpack-connection';
import { isJetpackSelfHostedSite, isSimpleSite } from '@automattic/jetpack-script-data';
import { useSelect } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as socialStore } from '../../social-store';
import { hasSocialPaidFeatures } from '../../utils';
import ConnectionScreen from './connection-screen';
import PricingPage from './pricing-page';
import styles from './styles.module.scss';

/**
 * Pre-empt screens shown when the chassis defers to legacy at the PHP
 * layer (`Social_Admin_Page::should_preempt_to_legacy`). Two cases
 * land here:
 *
 * 1. Site not connected → `ConnectionScreen`.
 * 2. Free Jetpack + pricing nudge not dismissed → `PricingPage`.
 *
 * The full Social tab UI (Overview + Settings) is owned by the wp-build
 * chassis. This entry no longer ports the legacy `Header`, the
 * `AdminSection` toggle bank, or the `InfoSection` — those retired
 * with the legacy single-page surface in #48824.
 *
 * @return The pre-empt screen, or `null` when neither condition holds
 * (defensive — PHP-level pre-empt is the source of truth).
 */
export const SocialAdminPage = () => {
	const isSimple = isSimpleSite();
	const isJetpackSite = isJetpackSelfHostedSite();
	const { isUserConnected, isRegistered } = useConnection();
	const { hasConnectionError } = useConnectionErrorNotice();
	const showConnectionCard = ! isSimple && ( ! isRegistered || ! isUserConnected );

	const [ pricingPageDismissed, setPricingPageDismissed ] = useState( false );
	const onPricingPageDismiss = useCallback( () => setPricingPageDismissed( true ), [] );

	const showPricingPage = useSelect(
		select => select( socialStore ).getSocialSettings().showPricingPage,
		[]
	);

	const subTitle = __( 'Publish once. Share everywhere.', 'jetpack-publicize-pkg' );

	if ( showConnectionCard ) {
		return (
			<AdminPage
				title={ 'Social' /** "Social" is a product name, do not translate. */ }
				subTitle={ subTitle }
				showBackground={ false }
			>
				<Container horizontalSpacing={ 3 } horizontalGap={ 3 }>
					<Col>
						<ConnectionScreen />
					</Col>
				</Container>
			</AdminPage>
		);
	}

	if ( isJetpackSite && ! hasSocialPaidFeatures() && showPricingPage && ! pricingPageDismissed ) {
		return (
			<AdminPage
				title={ 'Social' /** "Social" is a product name, do not translate. */ }
				subTitle={ subTitle }
			>
				<GlobalNotices />
				<div className={ styles.content }>
					<AdminSectionHero>
						<Container horizontalSpacing={ 0 }>
							{ hasConnectionError && (
								<Col className={ styles[ 'connection-error-col' ] }>
									<ConnectionError />
								</Col>
							) }
							<Col>
								<div id="jp-admin-notices" className="jetpack-social-jitm-card" />
							</Col>
						</Container>
						<Container horizontalSpacing={ 3 } horizontalGap={ 3 }>
							<Col>
								<PricingPage onDismiss={ onPricingPageDismiss } />
							</Col>
						</Container>
					</AdminSectionHero>
				</div>
			</AdminPage>
		);
	}

	return null;
};

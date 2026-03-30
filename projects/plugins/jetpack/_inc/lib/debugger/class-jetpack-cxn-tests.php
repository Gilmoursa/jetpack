<?php
/**
 * Jetpack-specific connection tests.
 *
 * Extends the connection package's Connection_Health_Tests with Jetpack-specific
 * tests (sync health, XML parser, WP.com self-test) and Jetpack-specific helper overrides.
 *
 * @package automattic/jetpack
 */

use Automattic\Jetpack\Connection\Connection_Health_Tests;
use Automattic\Jetpack\Redirect;
use Automattic\Jetpack\Status;
use Automattic\Jetpack\Sync\Health as Sync_Health;
use Automattic\Jetpack\Sync\Settings as Sync_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 0 );
}

/**
 * Class Jetpack_Cxn_Tests contains the Jetpack-specific connection tests.
 *
 * Inherits all connection-generic tests from the connection package.
 */
class Jetpack_Cxn_Tests extends Connection_Health_Tests {

	/**
	 * Jetpack_Cxn_Tests constructor.
	 */
	public function __construct() {
		parent::__construct();

		/*
		 * Add Jetpack-specific tests that are not part of the connection package.
		 * Connection-generic tests (token health, outbound HTTP, IDC, etc.) are
		 * inherited from Connection_Health_Tests.
		 */
		$jetpack_methods = array( 'test__xml_parser_available', 'test__sync_health' );
		foreach ( $jetpack_methods as $method ) {
			if ( method_exists( $this, $method ) ) {
				$this->add_test( array( $this, $method ), $method, 'direct' );
			}
		}

		/**
		 * Fires after loading default Jetpack Connection tests.
		 *
		 * @since 7.1.0
		 * @since 8.3.0 Passes the Jetpack_Cxn_Tests instance.
		 */
		do_action( 'jetpack_connection_tests_loaded', $this );

		/**
		 * Determines if the WP.com testing suite should be included.
		 *
		 * @since 7.1.0
		 * @since 8.1.0 Default false.
		 *
		 * @param bool $run_test To run the WP.com testing suite. Default false.
		 */
		if ( apply_filters( 'jetpack_debugger_run_self_test', false ) ) {
			$this->add_test( array( $this, 'last__wpcom_self_test' ), 'test__wpcom_self_test', 'direct' );
		}
	}

	/**
	 * Returns a support url based on using a development version.
	 *
	 * @return string
	 */
	protected function helper_get_support_url() {
		return Jetpack::is_development_version()
			? Redirect::get_url( 'jetpack-contact-support-beta-group' )
			: Redirect::get_url( 'jetpack-contact-support' );
	}

	/**
	 * Test that PHP's XML library is installed.
	 *
	 * @return array Test results.
	 */
	protected function test__xml_parser_available() {
		$name = 'test__xml_parser_available';
		if ( function_exists( 'xml_parser_create' ) ) {
			return self::passing_test( array( 'name' => $name ) );
		}

		return self::failing_test(
			array(
				'name'              => $name,
				'label'             => __( 'PHP XML manipulation libraries are not available.', 'jetpack' ),
				'short_description' => __( 'Please ask your hosting provider to refer to our server requirements and enable PHP\'s XML module.', 'jetpack' ),
				'action_label'      => __( 'View our server requirements', 'jetpack' ),
				'action'            => Redirect::get_url( 'jetpack-support-server-requirements' ),
			)
		);
	}

	/**
	 * Sync Health Tests.
	 *
	 * @return array Test results.
	 */
	protected function test__sync_health() {
		$name = 'test__sync_health';

		if ( ! $this->helper_is_connected() ) {
			return self::skipped_test(
				array(
					'name'                => $name,
					'show_in_site_health' => false,
				)
			);
		}

		if ( ! Sync_Settings::is_sync_enabled() ) {
			return self::failing_test(
				array(
					'name'              => $name,
					'label'             => __( 'Jetpack Sync has been disabled on your site.', 'jetpack' ),
					'severity'          => 'recommended',
					'action'            => 'https://github.com/Automattic/jetpack/blob/trunk/projects/packages/sync/src/class-settings.php',
					'action_label'      => __( 'See Github for more on Sync Settings', 'jetpack' ),
					'short_description' => __( 'Jetpack Sync has been disabled on your site. This could be impacting some of your site\'s Jetpack-powered features. Developers may enable / disable syncing using the Sync Settings API.', 'jetpack' ),
				)
			);
		}

		if ( Sync_Health::get_status() === Sync_Health::STATUS_OUT_OF_SYNC ) {
			return self::failing_test(
				array(
					'name'              => $name,
					'label'             => __( 'Jetpack has detected a problem with the communication between your site and WordPress.com', 'jetpack' ),
					'severity'          => 'critical',
					'action'            => Redirect::get_url( 'jetpack-contact-support' ),
					'action_label'      => __( 'Contact Jetpack Support', 'jetpack' ),
					'short_description' => __( 'There is a problem with the communication between your site and WordPress.com. This could be impacting some of your site\'s Jetpack-powered features. If you continue to see this error, please contact support for assistance.', 'jetpack' ),
				)
			);
		}

		return self::passing_test( array( 'name' => $name ) );
	}

	/**
	 * Calls to WP.com to run the connection diagnostic testing suite.
	 *
	 * Intentionally added last as it will be skipped if any local failed conditions exist.
	 *
	 * @since 7.1.0
	 *
	 * @return array Test results.
	 */
	protected function last__wpcom_self_test() {
		$name = 'test__wpcom_self_test';

		$status = new Status();
		if ( ! Jetpack::is_connection_ready() || $status->is_offline_mode() || $status->in_safe_mode() || ! $this->pass ) {
			return self::skipped_test( array( 'name' => $name ) );
		}

		$self_xml_rpc_url = site_url( 'xmlrpc.php' );

		$testsite_url = JETPACK__API_BASE . 'testsite/1/?url=';

		add_filter( 'http_request_timeout', array( 'Jetpack_Cxn_Tests', 'increase_timeout' ), PHP_INT_MAX - 1 );

		$response = wp_remote_get( $testsite_url . $self_xml_rpc_url );

		remove_filter( 'http_request_timeout', array( 'Jetpack_Cxn_Tests', 'increase_timeout' ), PHP_INT_MAX - 1 );

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			return self::passing_test( array( 'name' => $name ) );
		} elseif ( is_wp_error( $response ) && str_contains( $response->get_error_message(), 'cURL error 28' ) ) {
			return self::skipped_test(
				array(
					'name'              => $name,
					'short_description' => self::helper_get_timeout_text(),
				)
			);
		}

		return self::failing_test(
			array(
				'name'              => $name,
				'short_description' => sprintf(
					/* translators: %1$s - A debugging url */
					__( 'Jetpack.com detected an error on the WP.com Self Test. Visit the Jetpack Debug page for more info: %1$s, or contact support.', 'jetpack' ),
					Redirect::get_url( 'jetpack-support-debug', array( 'query' => 'url=' . rawurlencode( site_url() ) ) )
				),
				'action_label'      => $this->helper_get_support_text(),
				'action'            => $this->helper_get_support_url(),
			)
		);
	}
}

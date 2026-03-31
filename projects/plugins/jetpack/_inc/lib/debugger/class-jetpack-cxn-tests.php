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
 * Inherits all connection-generic tests from Connection_Health_Tests in the connection package.
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
	 * Adds a new test to the Jetpack Connection Testing suite.
	 *
	 * Extends the parent to maintain backwards compatibility with the pre-7.3.0 method signature.
	 *
	 * @since 7.1.0
	 * @since 7.3.0 Adds name parameter and returns WP_Error on failure.
	 *
	 * @param callable $callable Test to add to queue.
	 * @param string   $name Unique name for the test.
	 * @param string   $type   Optional. Core Site Health type: 'direct' if test can be run during initial load or 'async' if test should run async.
	 * @param array    $groups Optional. Testing groups to add test to.
	 *
	 * @return mixed True if successfully added. WP_Error on failure.
	 */
	public function add_test( $callable, $name, $type = 'direct', $groups = array( 'default' ) ) {
		if ( is_array( $name ) ) {
			// Pre-7.3.0 method passed the $groups parameter here.
			return new WP_Error( __( 'add_test arguments changed in 7.3.0. Please reference inline documentation.', 'jetpack' ) );
		}
		return parent::add_test( $callable, $name, $type, $groups );
	}

	/**
	 * Provide WP_CLI friendly testing results.
	 *
	 * @since 7.1.0
	 * @since 7.3.0 Add 'type'
	 *
	 * @param string $type  Test type, direct or async.
	 * @param string $group Testing group whose results we are outputting. Default all tests.
	 */
	public function output_results_for_cli( $type = 'all', $group = 'all' ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			if ( ( new Status() )->is_offline_mode() ) {
				WP_CLI::line( __( 'Jetpack is in Offline Mode:', 'jetpack' ) );
				WP_CLI::line( Jetpack::development_mode_trigger_text() );
			}
			WP_CLI::line( __( 'TEST RESULTS:', 'jetpack' ) );
			foreach ( $this->raw_results( $group ) as $test ) {
				if ( true === $test['pass'] ) {
					WP_CLI::log( WP_CLI::colorize( '%gPassed:%n  ' . $test['name'] ) );
				} elseif ( 'skipped' === $test['pass'] ) {
					WP_CLI::log( WP_CLI::colorize( '%ySkipped:%n ' . $test['name'] ) );
					if ( $test['short_description'] ) {
						WP_CLI::log( '         ' . $test['short_description'] );
					}
				} elseif ( 'informational' === $test['pass'] ) {
					WP_CLI::log( WP_CLI::colorize( '%yInfo:%n    ' . $test['name'] ) );
					if ( $test['short_description'] ) {
						WP_CLI::log( '         ' . $test['short_description'] );
					}
				} else {
					WP_CLI::log( WP_CLI::colorize( '%rFailed:%n  ' . $test['name'] ) );
					WP_CLI::log( '         ' . $test['short_description'] );
				}
			}
		}
	}

	/**
	 * Output results of failures in format expected by Core's Site Health tool for async tests.
	 *
	 * @since 7.3.0
	 *
	 * @return array Array of test results
	 */
	public function output_results_for_core_async_site_health() {
		$result = array(
			'label'       => __( 'Jetpack passed all async tests.', 'jetpack' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Jetpack', 'jetpack' ),
				'color' => 'green',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( "Jetpack's async local testing suite passed all tests!", 'jetpack' )
			),
			'actions'     => '',
			'test'        => 'jetpack_debugger_local_testing_suite_core',
		);

		if ( $this->pass() ) {
			return $result;
		}

		$fails = $this->list_fails( 'async' );
		$error = false;
		foreach ( $fails as $fail ) {
			if ( ! $error ) {
				$error                 = true;
				$result['label']       = $fail['message'];
				$result['status']      = $fail['severity'];
				$result['description'] = sprintf(
					'<p>%s</p>',
					$fail['resolution']
				);
				if ( ! empty( $fail['action'] ) ) {
					$result['actions'] = sprintf(
						'<a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span class="screen-reader-text">%3$s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
						esc_url( $fail['action'] ),
						__( 'Resolve', 'jetpack' ),
						/* translators: accessibility text */
						__( '(opens in a new tab)', 'jetpack' )
					);
				}
			} else {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					__( 'There was another problem:', 'jetpack' )
				) . ' ' . $fail['message'] . ': ' . $fail['resolution'];
				if ( 'critical' === $fail['severity'] ) {
					$result['status'] = 'critical';
				}
			}
		}

		return $result;
	}

	/**
	 * Encrypt data for sending to WordPress.com.
	 *
	 * @param string $data Data to encrypt with the WP.com Public Key.
	 *
	 * @return false|array False if functionality not available. Array of encrypted data, encryption key.
	 */
	public function encrypt_string_for_wpcom( $data ) {
		$return = false;
		if ( ! function_exists( 'openssl_get_publickey' ) || ! function_exists( 'openssl_seal' ) ) {
			return $return;
		}

		$public_key = openssl_get_publickey( JETPACK__DEBUGGER_PUBLIC_KEY );

		// Select the first allowed cipher method.
		$allowed_methods = array( 'aes-256-ctr', 'aes-256-cbc' );
		$methods         = array_intersect( $allowed_methods, openssl_get_cipher_methods() );
		$method          = array_shift( $methods );

		$iv = '';
		if ( $public_key && $method && openssl_seal( $data, $encrypted_data, $env_key, array( $public_key ), $method, $iv ) ) {
			// We are returning base64-encoded values to ensure they're characters we can use in JSON responses without issue.
			$return = array(
				'data'   => base64_encode( $encrypted_data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'key'    => base64_encode( $env_key[0] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'iv'     => base64_encode( $iv ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'cipher' => strtoupper( $method ),
			);
		}

		// openssl_free_key was deprecated as no longer needed in PHP 8.0+. Can remove when PHP 8.0 is our minimum. (lol).
		if ( PHP_VERSION_ID < 80000 ) {
			openssl_free_key( $public_key ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.openssl_free_keyDeprecated, Generic.PHP.DeprecatedFunctions.Deprecated
		}

		return $return;
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

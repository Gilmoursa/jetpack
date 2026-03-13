<?php
/**
 * SSO functionality testing.
 *
 * @package automattic/jetpack-connection
 */

namespace Automattic\Jetpack\Connection\SSO;

use Automattic\Jetpack\Connection\SSO;
use Automattic\Jetpack\Connection\Tokens;
use PHPUnit\Framework\Attributes\RequiresMethod;
use WorDBless\BaseTestCase;

/**
 * SSO functionality testing.
 */
class SSO_Test extends BaseTestCase {

	/**
	 * SSO instance created via reflection (bypasses private constructor).
	 *
	 * @var SSO
	 */
	private $sso;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$reflection = new \ReflectionClass( SSO::class );
		$this->sso  = $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		parent::tear_down();
		wp_set_current_user( 0 );
	}

	/**
	 * Invoke a private method on an object via reflection.
	 *
	 * @param object $object     The object.
	 * @param string $method     The method name.
	 * @param array  $parameters The parameters.
	 * @return mixed
	 */
	private function invoke_private_method( $object, $method, $parameters = array() ) {
		$reflection = new \ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invokeArgs( $object, $parameters );
	}

	/**
	 * Invoke a private static method via reflection.
	 *
	 * @param string $class      The class name.
	 * @param string $method     The method name.
	 * @param array  $parameters The parameters.
	 * @return mixed
	 */
	private function invoke_private_static_method( $class, $method, $parameters = array() ) {
		$reflection = new \ReflectionClass( $class );
		$method     = $reflection->getMethod( $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invokeArgs( null, $parameters );
	}

	// ---- verify_user_token tests ----

	/**
	 * Test verify_user_token returns true when user_token_valid is true and user matches.
	 */
	public function test_verify_user_token_fast_path_valid() {
		$tokens    = $this->createMock( Tokens::class );
		$user_data = (object) array( 'user_token_valid' => true );

		$result = $this->invoke_private_method(
			$this->sso,
			'verify_user_token',
			array( 42, $user_data, $tokens, 42 )
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test verify_user_token disconnects and returns false when user_token_valid is false.
	 */
	public function test_verify_user_token_fast_path_invalid() {
		$tokens = $this->createMock( Tokens::class );
		$tokens->expects( $this->once() )
			->method( 'disconnect_user' )
			->with( 42 );

		$user_data = (object) array( 'user_token_valid' => false );

		$result = $this->invoke_private_method(
			$this->sso,
			'verify_user_token',
			array( 42, $user_data, $tokens, 42 )
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test verify_user_token ignores user_token_valid when the token was for a different user.
	 */
	public function test_verify_user_token_user_mismatch_forces_fallback() {
		$tokens = $this->createMock( Tokens::class );
		$tokens->expects( $this->never() )
			->method( 'disconnect_user' );
		$tokens->expects( $this->once() )
			->method( 'validate' )
			->with( 42 )
			->willReturn(
				array(
					'user_token_is_healthy' => true,
					'blog_token_is_healthy' => true,
				)
			);

		// user_token_valid is false, but it was validated for user 99, not user 42.
		$user_data = (object) array( 'user_token_valid' => false );

		$result = $this->invoke_private_method(
			$this->sso,
			'verify_user_token',
			array( 42, $user_data, $tokens, 99 )
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test verify_user_token falls back to Tokens::validate when no user_token_valid in response.
	 */
	public function test_verify_user_token_fallback_healthy() {
		$tokens = $this->createMock( Tokens::class );
		$tokens->expects( $this->never() )
			->method( 'disconnect_user' );
		$tokens->expects( $this->once() )
			->method( 'validate' )
			->with( 42 )
			->willReturn(
				array(
					'user_token_is_healthy' => true,
					'blog_token_is_healthy' => true,
				)
			);

		$user_data = (object) array( 'ID' => 123 );

		$result = $this->invoke_private_method(
			$this->sso,
			'verify_user_token',
			array( 42, $user_data, $tokens, 0 )
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test verify_user_token fallback disconnects when token is unhealthy.
	 */
	public function test_verify_user_token_fallback_unhealthy() {
		$tokens = $this->createMock( Tokens::class );
		$tokens->expects( $this->once() )
			->method( 'disconnect_user' )
			->with( 42 );
		$tokens->expects( $this->once() )
			->method( 'validate' )
			->with( 42 )
			->willReturn(
				array(
					'user_token_is_healthy' => false,
					'blog_token_is_healthy' => true,
				)
			);

		$user_data = (object) array( 'ID' => 123 );

		$result = $this->invoke_private_method(
			$this->sso,
			'verify_user_token',
			array( 42, $user_data, $tokens, 0 )
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test verify_user_token fails open when Tokens::validate returns false (network error).
	 */
	public function test_verify_user_token_fail_open_on_error() {
		$tokens = $this->createMock( Tokens::class );
		$tokens->expects( $this->never() )
			->method( 'disconnect_user' );
		$tokens->expects( $this->once() )
			->method( 'validate' )
			->with( 42 )
			->willReturn( false );

		$user_data = (object) array( 'ID' => 123 );

		$result = $this->invoke_private_method(
			$this->sso,
			'verify_user_token',
			array( 42, $user_data, $tokens, 0 )
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test verify_user_token fails open when Tokens::validate returns WP_Error.
	 */
	public function test_verify_user_token_fail_open_on_wp_error() {
		$tokens = $this->createMock( Tokens::class );
		$tokens->expects( $this->never() )
			->method( 'disconnect_user' );
		$tokens->expects( $this->once() )
			->method( 'validate' )
			->with( 42 )
			->willReturn( new \WP_Error( 'site_not_registered', 'Site not registered.' ) );

		$user_data = (object) array( 'ID' => 123 );

		$result = $this->invoke_private_method(
			$this->sso,
			'verify_user_token',
			array( 42, $user_data, $tokens, 0 )
		);

		$this->assertTrue( $result );
	}

	// ---- set_wpcom_user_id_meta tests ----

	/**
	 * Test set_wpcom_user_id_meta sets meta on the target user.
	 */
	public function test_set_wpcom_user_id_meta_sets_meta() {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'sso_meta_test_user',
				'user_pass'  => 'password',
			)
		);

		$this->invoke_private_static_method(
			SSO::class,
			'set_wpcom_user_id_meta',
			array( $user_id, 12345 )
		);

		$this->assertEquals( 12345, get_user_meta( $user_id, 'wpcom_user_id', true ) );

		wp_delete_user( $user_id );
	}

	/**
	 * Test set_wpcom_user_id_meta removes stale meta from other users.
	 *
	 * Note: WP_User_Query meta queries require a full WordPress database.
	 * This test is skipped in WorDBless environments.
	 *
	 * @requires function WP_User_Query::prepare_query
	 */
	#[RequiresMethod( \WP_User_Query::class, 'prepare_query' )]
	public function test_set_wpcom_user_id_meta_removes_stale_meta() {
		$user_a = wp_insert_user(
			array(
				'user_login' => 'sso_meta_user_a',
				'user_pass'  => 'password',
			)
		);
		$user_b = wp_insert_user(
			array(
				'user_login' => 'sso_meta_user_b',
				'user_pass'  => 'password',
			)
		);

		update_user_meta( $user_b, 'wpcom_user_id', 12345 );

		// Verify WP_User_Query meta queries work in this environment.
		$probe = new \WP_User_Query(
			array(
				'meta_key'   => 'wpcom_user_id',
				'meta_value' => 12345,
				'fields'     => 'ID',
			)
		);
		if ( empty( $probe->get_results() ) ) {
			$this->markTestSkipped( 'WP_User_Query meta queries not supported in this environment.' );
		}

		$this->invoke_private_static_method(
			SSO::class,
			'set_wpcom_user_id_meta',
			array( $user_a, 12345 )
		);

		$this->assertEquals( 12345, get_user_meta( $user_a, 'wpcom_user_id', true ) );
		$this->assertEmpty( get_user_meta( $user_b, 'wpcom_user_id', true ) );

		wp_delete_user( $user_a );
		wp_delete_user( $user_b );
	}

	/**
	 * Test set_wpcom_user_id_meta handles multiple stale users.
	 *
	 * @requires function WP_User_Query::prepare_query
	 */
	#[RequiresMethod( \WP_User_Query::class, 'prepare_query' )]
	public function test_set_wpcom_user_id_meta_removes_from_multiple_stale_users() {
		$user_a = wp_insert_user(
			array(
				'user_login' => 'sso_multi_a',
				'user_pass'  => 'password',
			)
		);
		$user_b = wp_insert_user(
			array(
				'user_login' => 'sso_multi_b',
				'user_pass'  => 'password',
			)
		);
		$user_c = wp_insert_user(
			array(
				'user_login' => 'sso_multi_c',
				'user_pass'  => 'password',
			)
		);

		update_user_meta( $user_b, 'wpcom_user_id', 99999 );
		update_user_meta( $user_c, 'wpcom_user_id', 99999 );

		$probe = new \WP_User_Query(
			array(
				'meta_key'   => 'wpcom_user_id',
				'meta_value' => 99999,
				'fields'     => 'ID',
			)
		);
		if ( empty( $probe->get_results() ) ) {
			$this->markTestSkipped( 'WP_User_Query meta queries not supported in this environment.' );
		}

		$this->invoke_private_static_method(
			SSO::class,
			'set_wpcom_user_id_meta',
			array( $user_a, 99999 )
		);

		$this->assertEquals( 99999, get_user_meta( $user_a, 'wpcom_user_id', true ) );
		$this->assertEmpty( get_user_meta( $user_b, 'wpcom_user_id', true ) );
		$this->assertEmpty( get_user_meta( $user_c, 'wpcom_user_id', true ) );

		wp_delete_user( $user_a );
		wp_delete_user( $user_b );
		wp_delete_user( $user_c );
	}

	/**
	 * Test set_wpcom_user_id_meta does not remove meta from the target user when re-setting.
	 */
	public function test_set_wpcom_user_id_meta_preserves_target_user_meta() {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'sso_preserve_test',
				'user_pass'  => 'password',
			)
		);

		update_user_meta( $user_id, 'wpcom_user_id', 55555 );

		// Re-set the same meta on the same user.
		$this->invoke_private_static_method(
			SSO::class,
			'set_wpcom_user_id_meta',
			array( $user_id, 55555 )
		);

		$this->assertEquals( 55555, get_user_meta( $user_id, 'wpcom_user_id', true ) );

		wp_delete_user( $user_id );
	}

	// ---- get_signed_user_token_for_wpcom_id tests ----

	/**
	 * Test get_signed_user_token_for_wpcom_id returns empty when wpcom_user_id is 0.
	 */
	public function test_get_signed_token_returns_empty_for_zero_wpcom_id() {
		$result = $this->invoke_private_method(
			$this->sso,
			'get_signed_user_token_for_wpcom_id',
			array( 0 )
		);

		$this->assertSame( '', $result['signed_token'] );
		$this->assertSame( 0, $result['local_user_id'] );
	}

	/**
	 * Test get_signed_user_token_for_wpcom_id returns empty when no local user has the meta.
	 */
	public function test_get_signed_token_returns_empty_when_no_user_found() {
		$result = $this->invoke_private_method(
			$this->sso,
			'get_signed_user_token_for_wpcom_id',
			array( 999999 )
		);

		$this->assertSame( '', $result['signed_token'] );
		$this->assertSame( 0, $result['local_user_id'] );
	}

	/**
	 * Test get_signed_user_token_for_wpcom_id returns empty when user exists but has no token.
	 */
	public function test_get_signed_token_returns_empty_when_no_token() {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'sso_no_token_user',
				'user_pass'  => 'password',
			)
		);
		update_user_meta( $user_id, 'wpcom_user_id', 77777 );

		$result = $this->invoke_private_method(
			$this->sso,
			'get_signed_user_token_for_wpcom_id',
			array( 77777 )
		);

		$this->assertSame( '', $result['signed_token'] );
		$this->assertSame( 0, $result['local_user_id'] );

		wp_delete_user( $user_id );
	}

	// ---- get_user_by_wpcom_id tests ----

	/**
	 * Test get_user_by_wpcom_id returns the correct user.
	 *
	 * @requires function WP_User_Query::prepare_query
	 */
	#[RequiresMethod( \WP_User_Query::class, 'prepare_query' )]
	public function test_get_user_by_wpcom_id_returns_correct_user() {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'sso_wpcom_lookup',
				'user_pass'  => 'password',
			)
		);
		update_user_meta( $user_id, 'wpcom_user_id', 44444 );

		$probe = new \WP_User_Query(
			array(
				'meta_key'   => 'wpcom_user_id',
				'meta_value' => 44444,
				'number'     => 1,
			)
		);
		if ( empty( $probe->get_results() ) ) {
			wp_delete_user( $user_id );
			$this->markTestSkipped( 'WP_User_Query meta queries not supported in this environment.' );
		}

		$found = SSO::get_user_by_wpcom_id( 44444 );

		$this->assertNotNull( $found );
		$this->assertEquals( $user_id, $found->ID );

		wp_delete_user( $user_id );
	}

	/**
	 * Test get_user_by_wpcom_id returns null when no user matches.
	 */
	public function test_get_user_by_wpcom_id_returns_null_when_not_found() {
		$found = SSO::get_user_by_wpcom_id( 11111 );

		$this->assertNull( $found );
	}
}

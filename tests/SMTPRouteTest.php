<?php

require_once(__DIR__ . '/SMTPCommon.php');


use BFITech\ZapAdmin\SMTPError;


class SMTPRouteTest extends SMTPCommon {

	public function test_list_service() {
		extract(self::vars());

		$bogus = self::get_account('bogus');

		list($router, $rdev, $core) = $this->make_router();

		$rdev
			->request('/smtp/srv')
			->route('/smtp/srv', [$router, 'route_smtp_list']);
		$eq($core::$errno, 0);
		$sm($core::$data[0][0], $bogus['host']);
		$sm($core::$data[0][1], $bogus['port']);
	}

	public function test_route_bogus() {
		extract(self::vars());

		$bogus = self::get_account('bogus');

		list($router, $rdev, $core) = $this->make_router();

		# incomplete data
		$post = [
			'host' => $bogus['host'],
			'port' => $bogus['port'],
			'username' => $bogus['username'],
		];
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_INCOMPLETE_DATA);

		# service down or invalid cred
		$post['password'] = $bogus['password'];
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::CONNECT_FAILED);
	}

	public function test_route_valid() {
		extract(self::vars());

		### only run if there's a live server
		$valid = self::get_account('valid');
		if ($valid['username'] == null) {
			$this->markTestIncomplete('Valid host not provided.');
			return;
		}

		list($router, $rdev, $core) = $this->make_router();

		# wrong password
		$post = [
			'host' => $valid['host'],
			'port' => $valid['port'],
			'username' => $valid['username'],
			'password' => $valid['password'] . 'xxxx',
		];
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_FAILED);

		# success
		$post['password'] = $valid['password'];
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, 0);
		$data = $core::$data;
		$token = $data['token'];

		# test status
		#$uname = sprintf('+%s:smtp[%s:%s]',
		#	rawurlencode($post['username']),
		#	$post['host'], $post['port']);
		#$smtp->set_user_token($data['token']);
		#$rv = $smtp->adm_status();
		#$this->assertEquals($rv['uname'], $uname);

		# relogin will fail
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$router, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$code, 401);
	}

}


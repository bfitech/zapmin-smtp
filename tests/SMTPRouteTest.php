<?php


require_once(__DIR__ . '/SMTPCommon.php');


use BFITech\ZapAdmin\Error;
use BFITech\ZapAdmin\SMTPError;


class SMTPRouteTest extends SMTPCommon {

	public function make_zcore() {
		return $this->make_zcore_from_class(
			"\\BFITech\\ZapAdmin\\SMTPRouteDefault");
	}

	public function test_list_service() {
		extract(self::vars());

		$bogus = self::get_account('bogus');

		list($router, $rdev, $core) = $this->make_zcore();

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

		# incomplete data
		list($zcore, $rdev, $core) = $this->make_zcore();
		$post = [
			'host' => $bogus['host'],
			'port' => $bogus['port'],
			'username' => $bogus['username'],
		];
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_INCOMPLETE_DATA);

		# service down or invalid cred
		list($zcore, $rdev, $core) = $this->make_zcore();
		$post['password'] = $bogus['password'];
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$zcore, 'route_smtp_auth'], 'POST');
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

		# wrong password
		list($zcore, $rdev, $core) = $this->make_zcore();
		$post = [
			'host' => $valid['host'],
			'port' => $valid['port'],
			'username' => $valid['username'],
			'password' => $valid['password'] . 'xxxx',
		];
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_FAILED);

		# success
		list($zcore, $rdev, $core) = $this->make_zcore();
		$post['password'] = $valid['password'];
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, 0);
		$data = $core::$data;  # for later use, see below
		$token = $data['token'];

		# already signed in
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post], [
				'testing' => $token,
			])
			->route('/smtp/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::USER_ALREADY_LOGGED_IN);

		# login afresh
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/smtp/auth', 'POST', ['post' => $post])
			->route('/smtp/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($data['uid'], $core::$data['uid']);
		$eq($data['uname'], $core::$data['uname']);
		$ne($data['token'], $core::$data['token']);
	}

}

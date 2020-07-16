<?php


require_once(__DIR__ . '/SMTPCommon.php');


use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdminDev\SMTPRouteDev;
use BFITech\ZapAdmin\Error;
use BFITech\ZapAdmin\SMTPError;


class SMTPRouteDevTest extends SMTPCommon {

	public function make_zcore() {
		return $this->make_zcore_from_class(
			'\BFITech\ZapAdminDev\SMTPRouteDev');
	}

	public function test_smtp_dev() {
		extract(self::vars());

		list($zcore, $rdev, $core) = $this->make_zcore();
		$bogus = self::get_account('bogus');

		# ZAPMIN_SMTP_DEV not defined
		$post = [
			'host' => $bogus['host'],
			'port' => $bogus['port'],
		];
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, 1);
		$eq($core::$code, 401);

		if (!defined('ZAPMIN_SMTP_DEV'))
			define('ZAPMIN_SMTP_DEV', true);

		# no username or password
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_INCOMPLETE_DATA);

		# host not recognized
		list($zcore, $rdev, $core) = $this->make_zcore();
		$post['host'] = 'x' . $bogus['host'];
		$post['username'] = 'john';
		$post['password'] = 'lalala';
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::SRV_NOT_FOUND);

		# bad username
		list($zcore, $rdev, $core) = $this->make_zcore();
		$post['host'] = $bogus['host'];
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_FAILED);

		# bad password
		list($zcore, $rdev, $core) = $this->make_zcore();
		$post['username'] = 'john@' . $bogus['host'];
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_FAILED);

		# success
		list($zcore, $rdev, $core) = $this->make_zcore();
		$post['password'] = md5($post['username']);
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, 0);
		$zuid = $core::$data['uid'];     # for later use, see below
		$zuname = $core::$data['uname'];
		$token = $core::$data['token'];

		# get status
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/status', 'GET', [], ['testing' => $token])
			->route('/status', [$zcore, 'route_fake_status']);
		$eq(strpos(urldecode($core::$data['uname']),
			$post['username']), 1);

		# already signed in
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/auth', 'POST', ['post' => $post], [
				'testing' => $token,
			])
			->route('/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::USER_ALREADY_LOGGED_IN);

		### renew fake routing
		list($zcore, $rdev, $core) = $this->make_zcore();
		# relogin with the same name doesn't change zapmin identifier
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$zcore, 'route_smtp_auth'], 'POST');
		$eq($zuid, $core::$data['uid']);
		$eq($zuname, $core::$data['uname']);
	}

}


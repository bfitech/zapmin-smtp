<?php

require_once(__DIR__ . '/SMTPCommon.php');


use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdminDev\SMTPRouteDev;
use BFITech\ZapAdmin\SMTPError;


class SMTPRouteDevTest extends SMTPCommon {

	public function make_router() {
		$manage = $this->make_smtp();
		$log = $manage::$logger;
		$core = (new RouterDev())
			->config('home', '/')
			->config('shutdown', false)
			->config('logger', $log);
		$rdev = new RoutingDev($core);
		$ctrl = new AuthCtrl($manage::$admin, $log);
		$router = new SMTPRouteDev($core, $ctrl, $manage);
		return [$router, $rdev, $core];
	}

	public function test_smtp_dev() {
		extract(self::vars());

		list($router, $rdev, $core) = $this->make_router();
		$bogus = self::get_account('bogus');

		# ZAPMIN_SMTP_DEV not defined
		$post = [
			'host' => $bogus['host'],
			'port' => $bogus['port'],
		];
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, 1);
		$eq($core::$code, 401);

		if (!defined('ZAPMIN_SMTP_DEV'))
			define('ZAPMIN_SMTP_DEV', true);

		# no username or password
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_INCOMPLETE_DATA);

		# host not recognized
		$post['host'] = 'x' . $bogus['host'];
		$post['username'] = 'john';
		$post['password'] = 'lalala';
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::SRV_NOT_FOUND);

		# bad username
		$post['host'] = $bogus['host'];
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_FAILED);

		# bad password
		$post['username'] = 'john@' . $bogus['host'];
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, SMTPError::AUTH_FAILED);

		# success
		$post['password'] = md5($post['username']);
		$rdev
			->request('/auth', 'POST', ['post' => $post])
			->route('/auth', [$router, 'route_smtp_auth'], 'POST');
		$eq($core::$errno, 0);
		$token = $core::$data['token'];

		$rdev->request('/status', 'GET', [], [
			'testing' => $token
		]);
		### cannot chain here because $rdev->route() != $router->route()
		### when dealing with cookies
		$router->route('/status', [$router, 'route_fake_status']);
		$eq(strpos(urldecode($core::$data['uname']),
			$post['username']), 1);

	}

}


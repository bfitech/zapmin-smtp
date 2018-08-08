<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCommonDev\CommonDev;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdminDev\SMTPRouteDev;
use BFITech\ZapAdmin\AdminStoreError as AErr;
use BFITech\ZapAdmin\SMTPRouteError as SErr;


class SMTPRouteDevTest extends TestCase {

	public static $logger;

	public static function setUpBeforeClass() {
		$logfile = CommonDev::testdir(__FILE__) . '/zapmin-smtp-dev.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);
	}

	private function make_smtp() {
		$store = new SQLite3(['dbname' => ':memory:'], self::$logger);
		$core = (new RouterDev())
			->config('home', '/')
			->config('logger', self::$logger);
		return new SMTPRouteDev($store, self::$logger, null, $core);
	}

	public function test_smtp_dev() {
		$smtp = $this->make_smtp();
		$smtp->smtp_add_service('example.xyz', 587);
		$smtp->adm_set_token_name('testing');

		$core = $smtp->core;
		$rdev = new RoutingDev($core);

		$post = [
			'smtp_host' => 'localhost',
			'smtp_port' => 587,
		];
		$rdev->request('/auth', 'POST', ['post' => $post]);
		$smtp->route('/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, SErr::AUTH_INCOMPLETE_DATA);

		$post['username'] = 'john';
		$post['password'] = 'lalala';
		$rdev->request('/auth', 'POST', ['post' => $post]);
		$smtp->route('/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, SErr::CONNECT_FAILED);

		if (!defined('ZAPMIN_SMTP_DEV'))
			define('ZAPMIN_SMTP_DEV', true);
		$post['username'] = 'john';
		$post['password'] = 'lalala';
		$rdev->request('/auth', 'POST', ['post' => $post]);
		$smtp->route('/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, SErr::SRV_NOT_FOUND);

		$post['smtp_host'] = 'example.xyz';
		$rdev->request('/auth', 'POST', ['post' => $post]);
		$smtp->route('/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, SErr::AUTH_FAILED);

		$post['username'] = 'john@example.xyz';
		$rdev->request('/auth', 'POST', ['post' => $post]);
		$smtp->route('/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, SErr::AUTH_FAILED);

		$post['password'] = md5($post['username']);
		$rdev->request('/auth', 'POST', ['post' => $post]);
		$smtp->route('/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, 0);

		$rdev->request('/status', 'GET', [], [
			['testing' => $_COOKIE['testing']]
		]);
		$smtp->route('/status', [$smtp, 'route_fake_status']);
		$this->assertEquals(strpos(urldecode($core::$data['uname']),
			$post['username']), 1);

	}

}


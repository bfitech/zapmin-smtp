<?php


use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Config;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapCoreDev\TestCase;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\SMTPAuthManage;
use BFITech\ZapAdmin\SMTPRouteDefault;


abstract class SMTPCommon extends TestCase {

	public static $logger;
	public static $core;
	public static $cfile;

	public static function setUpBeforeClass() {
		$logfile = self::tdir(__FILE__) . '/zapmin-smtp.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);

		$cfile = self::tdir(__FILE__) . "/zapmin-smtp.json";
		self::$cfile = $cfile;

		# use existing config
		if (file_exists($cfile))
			return;

		$accounts = [
			'bogus' => [
				'host' => 'localhost',
				'port' => 587,
				'ssl' => false,
				'timeout' => 1,
				'opts' => [],
				'username' => 'you@localhost',
				'password' => 'quux',
			],
			'valid' => [
				'host' => 'localhost',
				'port' => 587,
				'ssl' => true,
				'timeout' => 1,
				'opts' => [],
				'username' => null,
				'password' => null,
			],
		];
		file_put_contents($cfile, json_encode(
			$accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	public static function get_account($account) {
		return (new Config(self::$cfile))->get($account);
	}


	public function make_smtp() {

		$log = self::$logger;
		$store = new SQLite3(['dbname' => ':memory:'], $log);
		$admin = new Admin($store, $log);
		$admin
			->config('expire', 3600)
			->config('token_name', 'testing')
			->config('check_tables', true);
		$manage = new SMTPAuthManage($admin, $log);

		foreach(['bogus', 'valid'] as $account) {
			call_user_func_array([$manage, 'add_service'],
				self::get_account($account));
		}
		return $manage;
	}

	public function make_router() {
		$manage = $this->make_smtp();
		$log = $manage::$logger;
		$core = (new RouterDev())
			->config('home', '/')
			->config('shutdown', false)
			->config('logger', $log);
		$rdev = new RoutingDev($core);
		$ctrl = new AuthCtrl($manage::$admin, $log);
		$router = new SMTPRouteDefault($core, $ctrl, $manage);
		return [$router, $rdev, $core];
	}
}


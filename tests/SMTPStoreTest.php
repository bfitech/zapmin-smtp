<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\CoreDev;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\SMTPRoute;
use BFITech\ZapAdmin\AdminStoreError;
use BFITech\ZapAdmin\SMTPRouteError as Err;


class SMTPFixture {

	public static $config = null;

	public static function read_config($configfile=null) {
		if (self::$config !== null)
			return;
		if (!$configfile)
			$configfile = CoreDev::testdir(__FILE__) .
				'/zapmin-smtp.json';
		if (file_exists($configfile))
			self::$config = json_decode(
				file_get_contents($configfile), true);
		else {
			self::$config = [
				'bogus' => [
					'host' => 'localhost',
					'port' => 1587,
					'ssl' => false,
					'timeout' => 1,
					'opts' => [],
					'username' => 'you@localhost',
					'password' => 'quux',
				],
				'valid' => [
					'host' => null,
					'port' => 587,
					'ssl' => true,
					'timeout' => 1,
					'opts' => [],
					'username' => null,
					'password' => null,
				],
			];
			file_put_contents($configfile,
				json_encode(self::$config, JSON_PRETTY_PRINT));
		}
	}

}


class SMTPRouteTest extends TestCase {

	public static $logger;
	public static $core;

	public static function setUpBeforeClass() {
		$logfile = CoreDev::testdir(__FILE__) . '/zapmin-smtp.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);
		SMTPFixture::read_config();
	}

	public function make_smtp() {
		$store = new SQLite3(['dbname' => ':memory:'], self::$logger);
		$core = (new RouterDev())
			->config('home', '/')
			->config('logger', self::$logger);
		return new SMTPRoute($store, self::$logger, null, $core);
	}

	private function make_service($smtp, $args) {
		extract($args);
		if (!isset($ssl))
			$ssl = true;
		if (!isset($timeout))
			$timeout = 1;
		if (!isset($opts))
			$opts = [];
		$smtp->smtp_add_service($host, $port, $ssl, $timeout, $opts);
	}

	private function core_reinit($core) {
		$core->deinit()->reset();
		$core->config('home', '/');
	}

	public function test_connection() {
		$smtp = $this->make_smtp();

		extract(SMTPFixture::$config['bogus']);

		$this->assertEquals(0,
			$smtp->smtp_add_service($host, $port));
		# cannot re-add existing service
		$this->assertEquals(Err::SRV_ADD_FAILED,
			$smtp->smtp_add_service($host, $port));

		$this->assertSame($smtp->smtp_list_services()[0],
			[$host, $port]);

		# connect to wrong port
		$this->assertEquals(Err::SRV_NOT_FOUND,
			$smtp->smtp_connect($host, $port + 1));

		# fail opening connection
		$this->assertEquals(Err::CONNECT_FAILED,
			$smtp->smtp_connect($host, $port));

	}

	public function test_authentication() {
		$conf = SMTPFixture::$config;

		$acc_bogus = $conf['bogus'];
		$acc_valid = $conf['valid'];

		if ($acc_valid['host'] === null)
			return;

		$smtp = $this->make_smtp();

		$smtp->smtp_add_service($acc_valid['host'], $acc_valid['port']);

		$this->assertEquals(Err::NOT_CONNECTED,
			$smtp->smtp_authenticate(
				$acc_bogus['username'], $acc_bogus['password']));

		$this->assertEquals(0,
			$smtp->smtp_connect(
				$acc_valid['host'], $acc_valid['port']));

		# connect again, will internally reconnect
		$smtp->smtp_connect($acc_valid['host'], $acc_valid['port']);

		$this->assertEquals(Err::AUTH_FAILED,
			$smtp->smtp_authenticate(
				$acc_bogus['username'], $acc_bogus['password']));

		if ($acc_valid['username'] === null)
			return;

		$this->assertEquals(0,
			$smtp->smtp_authenticate(
				$acc_valid['username'], $acc_valid['password']));
	}

	public function test_list_service() {
		$smtp = $this->make_smtp();
		$core = $smtp->core;

		$conf = [];
		$i = 0;
		while ($i < 4)
			$this->make_service($smtp, [
				'host' => 'localhost',
				'port' => 3587 + $i++,
			]);

		$_SERVER['REQUEST_URI'] = '/smtp/srv';
		$smtp->route('/smtp/srv', [$smtp, 'route_smtp_list']);
		$this->assertEquals($core::$errno, 0);
		$this->assertSame($core::$data[0][0], 'localhost');
		$this->assertSame($core::$data[0][1], 3587);
		$this->assertSame($core::$data[1][1], 3588);
	}

	public function test_route() {
		$conf = SMTPFixture::$config;
		$acc_bogus = $conf['bogus'];
		$acc_valid = $conf['valid'];

		$smtp = $this->make_smtp();
		$core = $smtp->core;
		$this->make_service($smtp, $acc_bogus);
		$this->make_service($smtp, $acc_valid);

		# incomplete data

		$_SERVER['REQUEST_URI'] = '/smtp/auth';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'smtp_host' => $acc_bogus['host'],
			'smtp_port' => $acc_bogus['port'],
			'username' => $acc_bogus['username'],
		];
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, Err::AUTH_INCOMPLETE_DATA);
		$this->core_reinit($core);

		# service down or invalid

		$_SERVER['REQUEST_URI'] = '/smtp/auth';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'smtp_host' => $acc_bogus['host'],
			'smtp_port' => $acc_bogus['port'],
			'username' => $acc_bogus['username'],
			'password' => $acc_bogus['password'],
		];
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, Err::CONNECT_FAILED);
		$this->core_reinit($core);

		# only run if there's a live server
		if ($acc_valid['host'] == null)
			return;

		# wrong password

		$_SERVER['REQUEST_URI'] = '/smtp/auth';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'smtp_host' => $acc_valid['host'],
			'smtp_port' => $acc_valid['port'],
			'username' => $acc_bogus['username'],
			'password' => $acc_bogus['password'],
		];
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, Err::AUTH_FAILED);
		$this->core_reinit($core);

		# success

		$_SERVER['REQUEST_URI'] = '/smtp/auth';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'smtp_host' => $acc_valid['host'],
			'smtp_port' => $acc_valid['port'],
			'username' => $acc_valid['username'],
			'password' => $acc_valid['password'],
		];
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$errno, 0);
		$data = $core::$data;

		# test status

		$uname = sprintf('+%s:smtp[%s:%s]',
			rawurlencode($_POST['username']),
			$_POST['smtp_host'], $_POST['smtp_port']);
		$smtp->adm_set_user_token($data['token']);
		$rv = $smtp->adm_status();
		$this->assertEquals($rv['uname'], $uname);
		$this->core_reinit($core);

		# resign-in will fail

		$_SERVER['REQUEST_URI'] = '/smtp/auth';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'smtp_host' => $acc_valid['host'],
			'smtp_port' => $acc_valid['port'],
			'username' => $acc_valid['username'],
			'password' => $acc_valid['password'],
		];
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno,
			AdminStoreError::USER_ALREADY_LOGGED_IN);
	}

}

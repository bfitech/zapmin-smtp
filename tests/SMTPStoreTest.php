<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\SMTPRoute;
use BFITech\ZapAdmin\SMTPRouteError as Err;


class SMTPFixture {

	public static $config = null;

	public static function read_config() {
		if (self::$config !== null)
			return;
		$config = __DIR__ . '/zapmin-smtp-test.json';
		if (file_exists($config))
			self::$config = json_decode(
				file_get_contents($config), true);
		else {
			self::$config = [
				'host_bogus' => [
					'host' => 'localhost',
					'port' => 1587,
					'ssl' => false,
					'timeout' => 4,
					'opts' => [],
				],
				'host_valid' => [
					'host' => null,
					'port' => null,
					'ssl' => true,
					'timeout' => 4,
					'opts' => [],
				],
				'account_bogus' => [
					'username' => 'you@localhost',
					'password' => 'blablabla',
				],
				'account_valid' => [
					'username' => null,
					'password' => null,
				],
			];
			file_put_contents($config,
				json_encode(self::$config, JSON_PRETTY_PRINT));
		}
	}

}


class SMTPRouteTest extends TestCase {

	public static $logger;
	public static $core;

	public static function setUpBeforeClass() {
		$_SERVER['REQUEST_URI'] = '/';

		$logfile = __DIR__ . '/zapmin-smtp-test.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);

		SMTPFixture::read_config();
	}

	public function make_store() {
		return new SQLite3(['dbname' => ':memory:'], self::$logger);
	}

	public function make_router() {
		return new RouterDev('/', 'http://localhost', true,
			self::$logger);
	}

	public function make_smtp($core=null, $store=null) {
		if (!$core)
			$core = $this->make_router();
		if (!$store)
			$store = $this->make_store();
		return new SMTPRoute([
			'core_instance' => $core,
			'store_instance' => $store,
			'logger_instance' => self::$logger,
		]);
	}

	public function test_connection() {
		$smtp = $this->make_smtp();

		extract(SMTPFixture::$config['host_bogus']);

		$this->assertEquals(0,
			$smtp->smtp_add_service($host, $port));
		# cannot re-add existing service
		$this->assertEquals(Err::ADD_SRV_FAILED,
			$smtp->smtp_add_service($host, $port, true));

		$this->assertSame($smtp->smtp_list_services()[0],
			[$host, $port]
		);

		$this->assertEquals(Err::SRV_NOT_FOUND,
			$smtp->smtp_connect($host, $port + 1));

		$this->assertEquals(Err::CONNECT_FAILED,
			$smtp->smtp_connect($host, $port));

	}

	public function test_authentication() {
		$smtp = $this->make_smtp();
		$conf = SMTPFixture::$config;

		$hbogus = $conf['host_bogus'];
		$hvalid = $conf['host_valid'];
		$abogus = $conf['account_bogus'];
		$avalid = $conf['account_valid'];

		$smtp->smtp_add_service($hbogus['host'], $hbogus['port']);

		if ($hvalid['host'] === null)
			return;

		$smtp->smtp_add_service($hvalid['host'], $hvalid['port']);

		$this->assertEquals(Err::NOT_CONNECTED,
			$smtp->smtp_authenticate(
				$abogus['username'], $abogus['password']));

		$this->assertEquals(0,
			$smtp->smtp_connect(
				$hvalid['host'], $hvalid['port']));

		# accidental reconnect
		$smtp->smtp_connect($hvalid['host'], $hvalid['port']);

		$this->assertEquals(Err::AUTH_FAILED,
			$smtp->smtp_authenticate(
				$abogus['username'], $abogus['password']));

		if ($avalid['username'] === null)
			return;

		$this->assertEquals(0,
			$smtp->smtp_authenticate(
				$avalid['username'], $avalid['password']));
	}

	public function test_route() {
		$store = $this->make_store();
		$conf = SMTPFixture::$config;

		$hbogus = $conf['host_bogus'];
		$hvalid = $conf['host_valid'];
		$abogus = $conf['account_bogus'];
		$avalid = $conf['account_valid'];

		# list services

		$_SERVER['REQUEST_URI'] = '/smtp/srv';
		$smtp = $this->make_smtp(null, $store);

		$smtp->smtp_add_service($hbogus['host'], $hbogus['port']);
		$smtp->smtp_add_service($hbogus['host'], $hbogus['port'] + 1);
		$smtp->smtp_add_service($hbogus['host'], $hbogus['port'] + 2);

		$smtp->route('/smtp/srv', [$smtp, 'route_smtp_list']);
		$core = $smtp->core;
		extract($core::$body);
		$this->assertEquals($errno, 0);
		$this->assertSame($data[0][0], $hbogus['host']);
		$this->assertSame($data[2][1], $hbogus['port'] + 2);
		$core::reset();

		# incomplete data

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'smtp_host' => $hbogus['host'],
			'smtp_port' => $hbogus['port'],
			'username' => $abogus['username'],
		];
		$_SERVER['REQUEST_URI'] = '/smtp/auth';
		$smtp = $this->make_smtp(null, $store);
		$smtp->smtp_add_service($hbogus['host'], $hbogus['port']);
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$core = $smtp->core;
		extract($core::$body);
		$this->assertEquals($errno, Err::AUTH_INCOMPLETE_DATA);

		# service down or invalid

		$_POST['password'] = $abogus['password'];
		$smtp = $this->make_smtp(null, $store);
		$smtp->smtp_add_service($hbogus['host'], $hbogus['port']);
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$core = $smtp->core;
		extract($core::$body);
		$this->assertEquals($errno, Err::CONNECT_FAILED);
		$core::$head = [];

		if ($hvalid['host'] == null)
			return;

		# wrong password

		$_POST = [
			'smtp_host' => $hvalid['host'],
			'smtp_port' => $hvalid['port'],
			'username' => $abogus['username'],
			'password' => $abogus['password'],
		];
		$smtp = $this->make_smtp(null, $store);
		$smtp->smtp_add_service($hvalid['host'], $hvalid['port'],
			$hvalid['ssl']);
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$core = $smtp->core;
		extract($core::$body);
		$this->assertEquals($errno, Err::AUTH_FAILED);
		$core::$head = [];

		# success

		$_POST['username'] = $avalid['username'];
		$_POST['password'] = $avalid['password'];
		$smtp = $this->make_smtp(null, $store);
		$smtp->smtp_add_service($hvalid['host'], $hvalid['port'],
			$hvalid['ssl']);
		$smtp->route('/smtp/auth', [$smtp, 'route_smtp_auth'], 'POST');
		$core = $smtp->core;
		extract($core::$body);
		$this->assertEquals($errno, 0);

		# test status

		$uname = sprintf('+%s:smtp[%s:%s]',
			rawurlencode($_POST['username']),
			$_POST['smtp_host'], $_POST['smtp_port']);
		$smtp->adm_set_user_token($data['token']);
		$rv = $smtp->adm_status();
		$this->assertEquals($rv['uname'], $uname);
	}


}


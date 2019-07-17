<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;


/**
 * Error class.
 */
class SMTPError extends \Exception {
	/** Service not found. */
	const SRV_NOT_FOUND = 0x0201;
	/** Connection failed. */
	const CONNECT_FAILED = 0x0202;
	/** Opening TLS connection failed. */
	const CONNECT_TLS_FAILED = 0x0203;

	/** Not connected to any service. */
	const NOT_CONNECTED = 0x0301;
	/** HELO/EHLO command failed. */
	const HELO_FAILED = 0x0302;
	/** Authenticaton failed. */
	const AUTH_FAILED = 0x0303;

	/** Incomplete authentication data. */
	const AUTH_INCOMPLETE_DATA = 0x0401;
}


/**
 * SMTPStore class.
 */
class SMTPAuthManage extends AuthManage {

	private $connection;
	private $service;
	private $services = [];

	/**
	 * Add authenticaton service.
	 *
	 * @param string $host Service host. Add explicit encryption type
	 *     prefix ssl://... etc. if necessary but WITHOUT the port.
	 * @param int $port Service port.
	 * @param bool $ssl Use TLS if true.
	 * @param int $timeout Connect timeout in seconds.
	 * @param dict $opts Socket options used by stream_context_create().
	 * @see https://archive.fo/K6wKE
	 * @see https://git.io/fjan7
	 */
	public function add_service(
		string $host, int $port,
		bool $ssl=true, int $timeout=4, array $opts=[]
	) {
		$key = $host . '-' . $port;
		$this->services[$key] = [
			'host' => $host,
			'port' => $port,
			'ssl' => $ssl,
			'timeout' => $timeout,
			'opts' => $opts,
		];
		self::$logger->debug("SMTP: add service ok: '$host:$port'.");
	}

	/**
	 * List available services.
	 *
	 * This strips keys except host and port, which is enough for
	 * a client to pick a desired service to authenticate against
	 * via SMTPStore::smtp_connect.
	 */
	public function list_services() {
		return array_values(array_map(function($ele){
			return [$ele['host'], $ele['port']];
		}, $this->services));
	}

	/**
	 * Map SMTP logging to Logger.
	 *
	 * @fixme Level is hardcoded. This should be derived from Logger
	 *     properties, which we don't currently have access to.
	 * @codeCoverageIgnore
	 */
	private function set_logger($smtp) {
		$log = self::$logger;
		$level = $log::ERROR;
		switch ($level) {
			case $log::DEBUG:
				$smtp->setDebugLevel(4);
				$smtp->setDebugOutput([$log, 'debug']);
				break;
			case $log::INFO:
				$smtp->setDebugLevel(1);
				$smtp->setDebugOutput([$log, 'info']);
				break;
			default:
				$smtp->setDebugLevel(0);
				break;
		}
	}

	/**
	 * Connect to a registered service.
	 *
	 * @param string $host Service host.
	 * @param int $port Service port.
	 */
	public function connect(string $host, int $port) {
		$log = self::$logger;
		$key = $host . '-' . $port;

		if ($this->connection) {
			$srv = $this->services[$key];
			$log->info(
				"SMTP: closing existing connection '$host:$port'.");
			$this->connection->close();
		}

		$this->connection = null;
		$this->service = null;

		if (!isset($this->services[$key])) {
			$log->warning("SMTP: service not found: '$host:$port'.");
			return SMTPError::SRV_NOT_FOUND;
		}
		$srv = $this->services[$key];

		$timout = $opts = $ssl = null;
		extract($srv);

		$smtp = new \SMTP();
		$this->set_logger($smtp);
		$ret = $smtp->connect($host, $port, $timeout, $opts);
		if (!$ret) {
			$log->warning("SMTP: connection failed: '$host:$port'.");
			return SMTPError::CONNECT_FAILED;
		}

		// @codeCoverageIgnoreStart
		if ($ssl && !$smtp->startTLS()) {
			$log->warning(
				"SMTP: TLS connection failed: '$host:$port'.");
			return SMTPError::CONNECT_TLS_FAILED;
		}
		// @codeCoverageIgnoreEnd

		$this->connection = $smtp;
		$this->service = $srv;

		return 0;
	}

	/**
	 * Authenticate.
	 *
	 * Parameters are exactly the same with that in
	 * PHPMailer::authenticate, although $realm and beyond are
	 * not used. Use this only after successful connection made by
	 * SMTPStore::smtp_connect.
	 *
	 * @param string $username The user name
	 * @param string $password The password
	 * @param string $authtype The auth type (PLAIN, LOGIN, NTLM,
	 *     CRAM-MD5, XOAUTH2)
	 * @param string $realm The auth realm for NTLM
	 * @param string $workstation The auth workstation for NTLM
	 * @param null|OAuth $OAuth An optional OAuth instance.
	 */
	public function authenticate(
		string $username, string $password, string $authtype=null,
		string $realm='', string $workstation='', $OAuth=null
	) {
		$log = self::$logger;

		if (!$this->connection) {
			$log->warning("SMTP: no connection opened.");
			return SMTPError::NOT_CONNECTED;
		}
		$smtp = $this->connection;

		# get EHLO and acquire AUTH announcement
		if (!$smtp->hello($this->service['host'])) {
			// @codeCoverageIgnoreStart
			$log->warning("SMTP: cannot send HELO/EHLO.");
			return SMTPError::HELO_FAILED;
			// @codeCoverageIgnoreEnd
		}

		# authenticate
		$authed = $smtp->authenticate($username, $password, $authtype,
			$realm, $workstation, $OAuth);
		if (!$authed) {
			$log->info("SMTP: auth failed for '$username'.");
			return SMTPError::AUTH_FAILED;
		}

		return 0;
	}

	/**
	 * Process authentication.
	 *
	 * @param string $smtp_host Service host.
	 * @param int $smtp_port Service port.
	 * @param string $username Service username.
	 * @param string $password Service password.
	 */
	public function add_user(
		string $host, int $port, string $username, string $password
	) {
		$ret = $this->connect($host, $port);
		if ($ret !== 0)
			return [$ret];

		$ret = $this->authenticate($username, $password);
		if ($ret !== 0)
			return [$ret];

		$username = rawurlencode($username);
		$uservice = sprintf('smtp[%s:%s]', $host, $port);

		$args = [
			'uname' => $username,
			'uservice' => $uservice,
		];

		return $this->self_add_passwordless($args);
	}

}

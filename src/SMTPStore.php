<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;


/**
 * Error class.
 */
class SMTPRouteError extends \Exception {

	/** Adding service failed. */
	const ADD_SRV_FAILED = 0x01;

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
class SMTPStore extends AdminRoute {

	private $smtp_connection;
	private $smtp_service;
	private $smtp_services = [];

	/**
	 * Add authenticaton service.
	 *
	 * @param string $host Service host.
	 * @param int $port Service port.
	 * @param bool $ssl Use TLS if true.
	 * @param int $timeout Connect timeout in seconds.
	 * @param dict $opts Socket options used by stream_context_create().
	 * @see https://archive.fo/K6wKE
	 */
	public function smtp_add_service(
		$host, $port, $ssl=true, $timeout=4, $opts=[]
	) {
		$key = $host . '-' . $port;
		if (isset($this->smtp_services[$key])) {
			$this->logger->warning(sprintf(
				"SMTP: Service '%s:%s' already added.", $host, $port));
			return SMTPRouteError::ADD_SRV_FAILED;
		}
		$this->smtp_services[$key] = [
			'host' => $host,
			'port' => $port,
			'ssl' => (bool)$ssl,
			'timeout' => (int)$timeout,
			'opts' => (array)$opts,
		];
		$this->logger->info(sprintf(
			"SMTP: Service '%s:%s' added.", $host, $port));
		return 0;
	}

	/**
	 * List available services.
	 *
	 * This strips keys except host and port, which is enough for
	 * a client to pick a desired service to authenticate against
	 * via SMTPStore::smtp_connect.
	 */
	public function smtp_list_services() {
		return array_values(array_map(function($ele){
			return [$ele['host'], $ele['port']];
		}, $this->smtp_services));
	}

	/**
	 * Connect to a registered service.
	 *
	 * @param string $host Service host.
	 * @param int $port Service port.
	 */
	public function smtp_connect($host, $port) {
		$Err = new SMTPRouteError;
		$logger = $this->logger;
		$key = $host . '-' . $port;

		if ($this->smtp_connection) {
			$srv = $this->smtp_services[$key];
			$logger->info(sprintf(
				"SMTP: Closing existing connection: %s:%s.",
				$srv['host'], $srv['port']));
			$this->smtp_connection->close();
		}

		$this->smtp_connection = null;
		$this->smtp_service = null;

		if (!isset($this->smtp_services[$key])) {
			$logger->warning("SMTP: Service '$host:$port' not found.");
			return $Err::SRV_NOT_FOUND;
		}
		$srv = $this->smtp_services[$key];

		$smtp = new \SMTP();
		$rv = $smtp->connect($srv['host'], $srv['port'], $srv['timeout'],
			$srv['opts']);
		if (!$rv) {
			$logger->warning("SMTP: Cannot connect to $host:$port.");
			return $Err::CONNECT_FAILED;
		}

		if ($srv['ssl'] && !$smtp->startTLS()) {
			$logger->warning(
				"SMTP: Cannot open TLS connection to $host:$port.");
			return $Err::CONNECT_TLS_FAILED;
		}

		$this->smtp_connection = $smtp;
		$this->smtp_service = $srv;

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
	public function smtp_authenticate(
		$username, $password, $authtype=null, $realm='',
		$workstation='', $OAuth=null
	) {
		$Err = new SMTPRouteError;
		$logger = $this->logger;

		if (!$this->smtp_connection) {
			$logger->warning("SMTP: No connection opened.");
			return $Err::NOT_CONNECTED;
		}
		$smtp = $this->smtp_connection;

		# get EHLO and acquire AUTH announcement
		if (!$smtp->hello($this->smtp_service['host'])) {
			// @codeCoverageIgnoreStart
			$logger->warning("SMTP: Cannot send HELO/EHLO.");
			return $Err::HELO_FAILED;
			// @codeCoverageIgnoreEnd
		}

		# authenticate
		$authed = $smtp->authenticate($username, $password, $authtype,
			$realm, $workstation, $OAuth);
		if (!$authed) {
			$logger->info(
				"SMTP: Authentication failed for '$username'.");
			return $Err::AUTH_FAILED;
		}

		return 0;
	}

	/**
	 * Process authentication.
	 *
	 * @param string $smtp_host Service host.
	 * @param port $smtp_port Service port.
	 * @param string $username Service username.
	 * @param string $password Service password.
	 */
	public function adm_smtp_add_user(
		$smtp_host, $smtp_port, $username, $password
	) {
		$rv = $this->smtp_connect($smtp_host, $smtp_port);
		if ($rv !== 0)
			return [$rv];

		$rv = $this->smtp_authenticate($username, $password);
		if ($rv !== 0)
			return [$rv];

		$username = rawurlencode($username);
		$uservice = sprintf('smtp[%s:%s]', $smtp_host, $smtp_port);

		$args['service'] = [
			'uname' => $username,
			'uservice' => $uservice,
		];

		return $this->adm_self_add_user_passwordless($args);
	}

}




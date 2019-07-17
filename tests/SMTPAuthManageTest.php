<?php

require_once(__DIR__ . '/SMTPCommon.php');


use BFITech\ZapAdmin\SMTPError;


class SMTPAuthManageTest extends SMTPCommon {

	public static $logger;
	public static $core;
	public static $cfile;

	public function test_connection() {
		extract(self::vars());

		$host = $port = null;
		extract(self::get_account('bogus'));

		$smtp = $this->make_smtp();

		# listing
		$sm($smtp->list_services()[0], [$host, $port]);

		# connect to wrong port
		$eq(SMTPError::SRV_NOT_FOUND,
			$smtp->connect($host, $port + 1));

		# fail opening connection
		$eq(SMTPError::CONNECT_FAILED,
			$smtp->connect($host, $port));
	}

	public function test_authenticate() {
		$eq = self::eq();

		$bogus = self::get_account('bogus');
		$valid = self::get_account('valid');

		if ($valid['username'] === null) {
			$this->markTestIncomplete('Valid host not provided.');
			return;
		}

		$smtp = $this->make_smtp();

		# not connected
		$eq(SMTPError::NOT_CONNECTED,
			$smtp->authenticate(
				$bogus['username'], $bogus['password']));

		# connect ok
		$eq(0, $smtp->connect($valid['host'], $valid['port']));

		# connect again, will internally reconnect
		$smtp->connect($valid['host'], $valid['port']);

		# failed authentication
		$eq(SMTPError::AUTH_FAILED,
			$smtp->authenticate(
				$bogus['username'], $bogus['password']));

		# ok
		$eq(0, $smtp->authenticate(
			$valid['username'], $valid['password']));
	}
}

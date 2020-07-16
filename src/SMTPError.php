<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


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

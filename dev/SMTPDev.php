<?php


namespace BFITech\ZapAdminDev;


use BFITech\ZapAdmin\SMTPRoute;
use BFITech\ZapAdmin\SMTPRouteError as Err;
use BFITech\ZapCore\Common;


/**
 * SMTP router for development.
 */
class SMTPRouteDev extends SMTPRoute {

	/**
	 * Fake authentication.
	 *
	 * Username is anything as long as it matches host. Password
	 * is simply MD5 of the username. `ZAPMIN_SMTP_DEV` must be
	 * defined.
	 *
	 * @param string $smtp_host Service host.
	 * @param port $smtp_port Service port.
	 * @param string $username Service username.
	 * @param string $password Service password.
	 */
	public function adm_smtp_add_user(
		$smtp_host, $smtp_port, $username, $password
	) {
		if (!defined('ZAPMIN_SMTP_DEV'))
			return [Err::CONNECT_FAILED];

		$srv = array_filter(
			$this->smtp_list_services(),
			function($ele) use($smtp_host, $smtp_port){
				return $ele[0] == $smtp_host && $ele[1] == $smtp_port;
			}
		);
		if (!$srv)
			return [Err::SRV_NOT_FOUND];

		if (
			strpos($username, $smtp_host) === false ||
			$password != md5($username)
		)
			return [Err::AUTH_FAILED];

		$username = rawurlencode($username);
		$uservice = sprintf('smtp[%s:%s]', $smtp_host, $smtp_port);

		$args['service'] = [
			'uname' => $username,
			'uservice' => $uservice,
		];

		return $this->adm_self_add_user_passwordless($args);
	}

	/**
	 * Fake status.
	 */
	public function route_fake_status() {
		return $this->core->pj($this->adm_get_safe_user_data());
	}

}

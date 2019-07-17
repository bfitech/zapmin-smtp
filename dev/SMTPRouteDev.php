<?php


namespace BFITech\ZapAdminDev;


use BFITech\ZapAdmin\SMTPRouteDefault;
use BFITech\ZapAdmin\SMTPError;
use BFITech\ZapCore\Common;


/**
 * SMTP router for development.
 */
class SMTPRouteDev extends SMTPRouteDefault {

	/**
	 * Fake authentication.
	 *
	 * Username is anything as long as it ends with @$host. Password
	 * is simply MD5 of the username. `ZAPMIN_SMTP_DEV` must be
	 * defined.
	 *
	 * @param string $host Service host.
	 * @param port $port Service port.
	 * @param string $username Service username.
	 * @param string $password Service password.
	 */
	public function route_smtp_auth(array $args) {

		$core = self::$core;

		if (!defined('ZAPMIN_SMTP_DEV'))
			return $core::pj([1], 401);

		$post = $args['post'];

		$manage = self::$manage;
		if ($manage->is_logged_in())
			return $core::pj([1], 401);

		$host = $port = $username = $password = null;
		if (!Common::check_idict($post, [
			'host', 'port', 'username', 'password'
		]))

			return $core::pj([SMTPError::AUTH_INCOMPLETE_DATA], 403);
		extract($post);

		$srv = array_filter(
			$manage->list_services(),
			function($ele) use($host, $port){
				return $ele[0] == $host && $ele[1] == $port;
			}
		);
		if (!$srv)
			return $core::pj([SMTPError::SRV_NOT_FOUND]);

		$shost = '@' . preg_replace('!^.+://(.+)$!', '$1', $host);
		if (
			substr_compare($username, $shost, -strlen($shost)) !== 0 ||
			$password != md5($username)
		)
			return $core::pj([SMTPError::AUTH_FAILED]);

		$username = rawurlencode($username);
		$uservice = sprintf('smtp[%s:%s]', $host, $port);
		$ret = $manage->self_add_passwordless([
			'uname' => $username,
			'uservice' => $uservice,
		]);
		if ($ret[0] !== 0)
			return $core::pj($ret, 403);
		$token = $ret[1]['token'];

		# alway autologin on success
		$admin = $manage::$admin;
		$manage->set_token_value($token);
		$expiration = $admin::$store->time() + $admin->get_expiration();
		$core::send_cookie(
			$admin->get_token_name(), $token, $expiration, '/');

		return $core::pj($ret);
	}

	/**
	 * Fake status.
	 */
	public function route_fake_status() {
		return self::$core->pj(self::$ctrl->get_safe_user_data());
	}

}

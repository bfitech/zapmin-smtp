<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;


/**
 * SMTPRoute class.
 *
 * Use route paths as you see fit.
 */
class SMTPRouteDefault extends Route {

	/**
	 * List available SMTP authentication services.
	 *
	 * `GET: /smtp/services`
	 */
	public function route_smtp_list() {
		self::$core->pj([0, self::$manage->list_services()]);
	}

	/**
	 * Default authentication via SMTP.
	 *
	 * `POST: /smtp/login`
	 */
	public function route_smtp_auth(array $args) {
		$core = self::$core;
		$post = $args['post'];

		$manage = self::$manage;
		if ($manage->is_logged_in())
			return $core->pj([1], 401);

		$host = $port = $username = $password = null;
		if (!Common::check_idict($post, [
			'host', 'port', 'username', 'password'
		]))
			return $core::pj([SMTPError::AUTH_INCOMPLETE_DATA], 403);
		extract($post);

		$ret = $manage->add_user($host, $port, $username, $password);
		if ($ret[0] !== 0)
			return $core::pj($ret, 403);
		$token = $ret[1]['token'];

		# always autologin on success
		$admin = $manage::$admin;
		$manage->set_token_value($token);
		$expiration = $admin::$store->time() + $admin->get_expiration();
		$core::send_cookie(
			$admin->get_token_name(), $token, $expiration, '/');

		return $core::pj($ret);
	}

}

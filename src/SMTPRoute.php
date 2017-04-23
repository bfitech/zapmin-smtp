<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;
#use SMTPRouteError as Err;


/**
 * SMTPRoute class.
 *
 * Use route paths as you see fit.
 */
class SMTPRoute extends SMTPStore {

	/**
	 * List available SMTP authentication services.
	 *
	 * `GET: /smtp/srv`
	 */
	public function route_smtp_list($args=null) {
		$this->core->pj([0, $this->smtp_list_services()]);
	}

	/**
	 * Default authentication via SMTP.
	 *
	 * `POST: /smtp/auth`
	 */
	public function route_smtp_auth($args) {
		$core = $this->core;
		$post = $args['post'];

		if (!Common::check_idict($post, [
			'smtp_host', 'smtp_port', 'username', 'password'
		]))
			return $core::pj([SMTPRouteError::AUTH_INCOMPLETE_DATA],
				403);
		extract($post);

		$rv = $this->adm_smtp_add_user($smtp_host, $smtp_port,
			$username, $password);

		if ($rv[0] !== 0)
			return $core::pj($rv, 403);

		if (!isset($rv[1]) || !isset($rv[1]['token']))
			return $core::pj($rv, 403);

		# alway autologin on success
		$token = $rv[1]['token'];
		$this->adm_set_user_token($token);
		$core::send_cookie(
			$this->adm_get_token_name(), $token,
			time() + $this->adm_get_byway_expiration(), '/'
		);

		return $core::pj($rv);
	}
}


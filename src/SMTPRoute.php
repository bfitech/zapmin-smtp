<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;


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
	public function route_smtp_list() {
		$this->core->pj([0, $this->smtp_list_services()]);
	}

	/**
	 * Default authentication via SMTP.
	 *
	 * This should be drop-in replacement for AdminStore::adm_login.
	 *
	 * `POST: /smtp/auth`
	 */
	public function route_smtp_auth($args) {
		$core = $this->core;
		$post = $args['post'];

		if ($this->store_is_logged_in())
			return $core->pj([AdminStoreError::USER_ALREADY_LOGGED_IN],
				401);

		$smtp_host = $smtp_port = $username = $password = null;
		$common = new Common;
		if (!$common::check_idict($post, [
			'smtp_host', 'smtp_port', 'username', 'password'
		]))
			return $core::pj([SMTPRouteError::AUTH_INCOMPLETE_DATA],
				403);
		extract($post);

		$rv = $this->adm_smtp_add_user($smtp_host, $smtp_port,
			$username, $password);

		if ($rv[0] !== 0)
			return $core::pj($rv, 403);

		$expiration = $this->store->unix_epoch() +
			$this->adm_get_byway_expiration();

		# alway autologin on success
		$token = $rv[1]['token'];
		$this->adm_set_user_token($token);
		$core::send_cookie($this->adm_get_token_name(), $token,
			$expiration, '/');

		return $core::pj($rv);
	}

}

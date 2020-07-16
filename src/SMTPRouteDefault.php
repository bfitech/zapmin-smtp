<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;


/**
 * Default router callbacks.
 */
class SMTPRouteDefault extends RouteAdmin {

	/**
	 * List available SMTP authentication services.
	 *
	 * @see apidoc
	 *
	 * @if TRUE
	 * @api {get} /smtp/list SMTPServiceList
	 * @apiDescription
	 *     Get list of registered services.
	 * @apiName SMTPServiceList
	 * @apiGroup SMTP
	 * @apiSuccess {Int=0} errno Success.
	 * @apiSuccess {List[]} data Service list, each containing
	 *     a tuple of host and port.
	 * @endif
	 */
	public function route_smtp_list() {
		self::$core::pj([0, self::$manage->list_services()]);
	}

	/**
	 * Default authentication via SMTP.
	 *
	 * @see apidoc
	 *
	 * @if TRUE
	 * @api {post} /smtp/auth SMTPAuth
	 * @apiDescription
	 *     Authenticate a user.
	 * @apiName SMTPAuth
	 * @apiGroup SMTP
	 * @apiParam (POST) {String} host SMTP host.
	 * @apiParam (POST) {Int} port SMTP port.
	 * @apiParam (POST) {String} username Username.
	 * @apiParam (POST) {String} password Password.
	 * @apiSuccess {Int=0} errno Success.
	 * @apiSuccess {Object} data User data.
	 * @apiSuccess {Int} data.uid User ID.
	 * @apiSuccess {String} data.uname Zapmin user identifier.
	 * @apiSuccess {String} data.token Session token.
	 * @apiError (401) {Int=
	 *     Error::USER_ALREADY_LOGGED_IN
	 * } errno User already signed in.
	 * @apiError (403) {Int=
	 *     SMTPError::*
	 * } errno Specific error number. See code documentation.
	 * @endif
	 */
	public function route_smtp_auth(array $args) {
		$core = self::$core;
		$manage = self::$manage;
		$log = $manage::$logger;

		if ($manage->is_logged_in()) {
			# already signed in
			$log->info(sprintf(
				"SMTP: Auth already signed in: '%s'.",
				$manage->get_user_data()['token']
			));
			return $core::pj([Error::USER_ALREADY_LOGGED_IN], 401);
		}

		$post = $args['post'];
		$host = $port = $username = $password = null;
		if (!Common::check_idict($post, [
			'host', 'port', 'username', 'password'
		]))
			return $core::pj([SMTPError::AUTH_INCOMPLETE_DATA], 403);
		extract($post);

		$ret = $manage->add_user($host, $port, $username, $password);
		if ($ret[0] !== 0)
			return $core::pj($ret, 403);

		$token_name = $this->token_name;
		$token_value = $ret[1]['token'];

		$admin = $manage::$admin;
		$expires = $admin::$store->time() + $admin->get_expiration();
		$core::send_cookie_with_opts($token_name, $token_value, [
			'path' => '/',
			'expires' => $expires,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		$log->debug(sprintf(
			"SMTP: Set cookie: [%s -> %s].",
			$token_name, $token_value));

		return $core::pj($ret);
	}

}

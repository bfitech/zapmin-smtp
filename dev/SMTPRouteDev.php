<?php


namespace BFITech\ZapAdminDev;


use BFITech\ZapAdmin\SMTPRouteDefault;
use BFITech\ZapAdmin\Error;
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
	 * @see apidoc
	 *
	 * @if TRUE
	 * @api {post} /smtp/fake-auth SMTPAuthFake
	 * @apiDescription
	 *     Authenticate a fake user. For development only.
	 * @apiName SMTPAuthFake
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
	 * @apiError (403) {Int=SMTPError::*} errno Specific error number.
	 *     See code documentation.
	 * @endif
	 */
	public function route_smtp_auth(array $args) {
		$core = self::$core;
		$manage = self::$manage;
		$log = $manage::$logger;

		if (!defined('ZAPMIN_SMTP_DEV'))
			return $core::pj([1], 401);

		if ($manage->is_logged_in())
			# already signed in
			return $core::pj([Error::USER_ALREADY_LOGGED_IN], 401);

		$post = $args['post'];
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

		$token_name = $this->token_name;
		$token_value = $ret[1]['token'];

		# alway autologin on success
		$admin = $manage::$admin;
		$expires = $admin::$store->time() + $admin->get_expiration();
		$core::send_cookie_with_opts($token_name, $token_value, [
			'path' => '/',
			'expires' => $expires,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		$log->debug(sprintf(
			"ZapSMTP: Set cookie: [%s -> %s].",
			$token_name, $token_value));

		return $core::pj($ret);
	}

	/**
	 * Fake status.
	 *
	 * This is identical to non-fake
	 * BFITech\\ZapAdmin\\RouteDefault::route_status. Included for
	 * completeness sake.
	 *
	 * @see apidoc
	 *
	 * @if TRUE
	 * @api {get} /smtp/fake-status SMTPStatusFake
	 * @apiDescription
	 *     Get user status. For development only.
	 * @apiName SMTPStatusFake
	 * @apiGroup SMTP
	 * @apiSuccess {Int=0} errno Success.
	 * @apiSuccess {Object} data User data.
	 * @apiSuccess {Int} data.uid User ID.
	 * @apiSuccess {String} data.uname Zapmin user identifier.
	 * @apiSuccess {String} data.email=null Email address.
	 * @apiSuccess {Int} data.email_verified=0 Whether email
	 *     address is verified.
	 * @apiSuccess {String} data.fname=null Full name.
	 * @apiSuccess {String} data.site=null User website.
	 * @apiSuccess {Date} data.since Registration date.
	 * @apiError (401) {Int=
	 *     Error::USER_NOT_LOGGED_IN
	 * } errno User not signed in.
	 * @endif
	 */
	public function route_fake_status() {
		return self::$core->pj(self::$ctrl->get_safe_user_data());
	}

}

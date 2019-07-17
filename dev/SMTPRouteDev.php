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
	 * @see apidoc
	 *
	 * @api {post} /smtp/fake-auth SMTPAuthFake
	 * @apiDescription
	 *     Authenticate a fake user. For development only.
	 *
	 * @cond
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
	 * @apiSuccess {String} data.email=null Email address.
	 * @apiSuccess {Int} data.email_verified=0 Whether email
	 *     address is verified.
	 * @apiSuccess {String} data.fname=null Full name.
	 * @apiSuccess {String} data.site=null User website.
	 * @apiSuccess {Date} data.since Registration date.
	 * @apiError (401) {Int=1} errno User already signed in.
	 * @apiError (403) {Int=SMTPError::*} errno Specific error number.
	 *     See code documentation.
	 * @endcond
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
	 *
	 * This is identical to non-fake
	 * BFITech\\ZapAdmin\\RouteDefault::route_status. Included for
	 * completeness sake.
	 *
	 * @see apidoc
	 *
	 * @api {get} /smtp/fake-status SMTPStatusFake
	 * @apiDescription
	 *     Get user status. For development only.
	 *
	 * @cond
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
	 * @apiError (401) {Int=1} errno User not signed in.
	 * @endcond
	 */
	public function route_fake_status() {
		return self::$core->pj(self::$ctrl->get_safe_user_data());
	}

}

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
	 * @see apidoc
	 *
	 * @api {get} /smtp/list SMTPServiceList
	 * @apiDescription
	 *     Get list of registered services.
	 *
	 * @cond
	 * @apiName SMTPServiceList
	 * @apiGroup SMTP
	 * @apiSuccess {Int=0} errno Success.
	 * @apiSuccess {List[]} data Service list, each containing
	 *     a tuple of host and port.
	 * @endcond
	 */
	public function route_smtp_list() {
		self::$core::pj([0, self::$manage->list_services()]);
	}

	/**
	 * Default authentication via SMTP.
	 *
	 * @see apidoc
	 *
	 * @api {post} /smtp/auth SMTPAuth
	 * @apiDescription
	 *     Authenticate a user.
	 *
	 * @cond
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
	 * @apiError (401) {Int=1} errno User already signed in.
	 * @apiError (403) {Int=SMTPError::*} errno Specific error number.
	 *     See code documentation.
	 * @endcond
	 */
	public function route_smtp_auth(array $args) {
		$core = self::$core;
		$post = $args['post'];

		$manage = self::$manage;

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

<?php namespace JFusion\Plugins\vbulletin;
/**
 * @category   Plugins
 * @package    JFusion\Plugins
 * @subpackage vbulletin
 * @author     JFusion Team <webmaster@jfusion.org>
 * @copyright  2008 JFusion. All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link       http://www.jfusion.org
 */

use JFusion\Application\Application;
use JFusion\Config;
use JFusion\Factory;
use JFusion\Framework;
use JFusion\User\Groups;
use JFusion\User\Userinfo;

use Joomla\Form\Html\Select;
use Joomla\Language\Text;

use Psr\Log\LogLevel;

use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;
use stdClass;

/**
 * JFusion User Class for vBulletin
 * For detailed descriptions on these functions please check User
 *
 * @category   Plugins
 * @package    JFusion\Plugins
 * @subpackage vbulletin
 * @author     JFusion Team <webmaster@jfusion.org>
 * @copyright  2008 JFusion. All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link       http://www.jfusion.org
 */
class User extends \JFusion\Plugin\User
{
	/**
	 * @var $helper Helper
	 */
	var $helper;

	/**
	 * @param Userinfo $userinfo
	 * @param string $identifier_type
	 * @param int $ignore_id
	 *
     * @return null|Userinfo
	 */
	function getUser(Userinfo $userinfo, $identifier_type = 'auto', $ignore_id = 0)
	{
		$user = null;
		try {
			// Get user info from database
			$db = Factory::getDatabase($this->getJname());

			if($identifier_type == 'auto') {
				//get the identifier
				list($identifier_type, $identifier) = $this->getUserIdentifier($userinfo, 'u.username', 'u.email', 'u.userid');
				if ($identifier_type == 'u.username') {
					//lower the username for case insensitivity purposes
					$identifier_type = 'LOWER(' . $db->quoteName('u.username') . ')';

					$identifier = strtolower($identifier);
				}
			} else {
				$identifier_type = $db->quoteName('u.' . $identifier_type);

				$identifier = $userinfo;
			}

			$name_field = $this->params->get('name_field');

			$query = $db->getQuery(true)
				->select('u.userid, u.username, u.email, u.usergroupid AS group_id, u.membergroupids, u.displaygroupid, u.password, u.salt as password_salt, u.usertitle, u.customtitle, u.posts, u.username as name')
				->from('#__user AS u')
				->where($identifier_type . ' = ' . $db->quote($identifier));

			if ($ignore_id) {
				$query->where('u.userid != ' . $ignore_id);
			}

			$db->setQuery($query);
			$result = $db->loadObject();

			if ($result) {
				$query = $db->getQuery(true)
					->select('title')
					->from('#__usergroup')
					->where('usergroupid = ' . (int)$result->group_id);

				$db->setQuery($query);
				$result->group_name = $db->loadResult();

				if (!empty($name_field)) {
					$query = $db->getQuery(true)
						->select($name_field)
						->from('#__userfield')
						->where('userid = ' . (int)$result->userid);

					$db->setQuery($query);
					$name = $db->loadResult();
					if (!empty($name)) {
						$result->name = $name;
					}
				}
				//Check to see if they are banned
				$query = $db->getQuery(true)
					->select('userid')
					->from('#__userban')
					->where('userid = ' . (int)$result->userid);

				$db->setQuery($query);
				if ($db->loadObject() || ($this->params->get('block_coppa_users', 1) && (int) $result->group_id == 4)) {
					$result->block = true;
				} else {
					$result->block = false;
				}

				//check to see if the user is awaiting activation
				$activationgroup = $this->params->get('activationgroup');

				if ($activationgroup == $result->group_id) {
					$result->activation = $this->genRandomPassword(32);
				} else {
					$result->activation = '';
				}
				$user = new Userinfo($this->getJname());
				$user->bind($result);
			}
		} catch (Exception $e) {
			Framework::raise(LogLevel::ERROR, $e, $this->getJname());
		}
		return $user;
	}

	/**
	 * @return string
	 */
	function getTablename()
	{
		return 'user';
	}

	/**
	 * @param Userinfo $userinfo
	 *
	 * @return boolean returns true on success and false on error
	 */
	function deleteUser(Userinfo $userinfo)
	{
		$result = false;
		$apidata = array('userinfo' => $userinfo);
		$response = $this->helper->apiCall('deleteUser', $apidata);

		if ($response['success']) {
			$this->debugger->addDebug(Text::_('USER_DELETION') . ' ' . $userinfo->userid);
			$result = true;
		}
		foreach ($response['errors'] as $error) {
			$this->debugger->addError($error);
		}
		foreach ($response[LogLevel::DEBUG] as $debug) {
			$this->debugger->addDebug($debug);
		}
		return $result;
	}

	/**
	 * @param Userinfo $userinfo
	 * @param array $options
	 *
	 * @return array
	 */
	function destroySession(Userinfo $userinfo, $options)
	{
		$status = array('error' => array(), 'debug' => array());
		try {
			$mainframe = Application::getInstance();
			$cookie_prefix = $this->params->get('cookie_prefix');
			$vbversion = $this->helper->getVersion();
			if ((int) substr($vbversion, 0, 1) > 3) {
				if (substr($cookie_prefix, -1) !== '_') {
					$cookie_prefix .= '_';
				}
			}
			$cookie_domain = $this->params->get('cookie_domain');
			$cookie_path = $this->params->get('cookie_path');
			$cookie_expires = $this->params->get('cookie_expires', '15') * 60;
			$secure = $this->params->get('secure', false);
			$httponly = $this->params->get('httponly', true);
			$timenow = time();

			$session_user = $mainframe->input->cookie->get($cookie_prefix . 'userid', '');
			if (empty($session_user)) {
				$status[LogLevel::DEBUG][] = Text::_('VB_COOKIE_USERID_NOT_FOUND');
			}

			$session_hash = $mainframe->input->cookie->get($cookie_prefix . 'sessionhash', '');
			if (empty($session_hash)) {
				$status[LogLevel::DEBUG][] = Text::_('VB_COOKIE_HASH_NOT_FOUND');
			}

			//If blocking a user in Joomla User Manager, Joomla will initiate a logout.
			//Thus, prevent a logout of the currently logged in user if a user has been blocked:
			if (!defined('VBULLETIN_BLOCKUSER_CALLED')) {
				$cookies = Factory::getCookies();
				//clear out all of vB's cookies
				foreach ($_COOKIE AS $key => $val) {
					if (strpos($key, $cookie_prefix) !== false) {
						$status[LogLevel::DEBUG][] = $cookies->addCookie($key , 0, -3600, $cookie_path, $cookie_domain, $secure, $httponly);
					}
				}

				$db = Factory::getDatabase($this->getJname());
				$queries = array();

				if ($session_user) {
					$queries[] = $db->getQuery(true)
						->update('#__user')
						->set('lastvisit = ' .  $db->quote($timenow))
						->set('lastactivity = ' .  $db->quote($timenow))
						->where('userid = ' . $db->quote($session_user));

					$queries[] = $db->getQuery(true)
						->delete('#__session')
						->where('userid = ' . $db->quote($session_user));
				}
				$queries[] = $db->getQuery(true)
					->delete('#__session')
					->where('sessionhash = ' . $db->quote($session_hash));

				foreach ($queries as $q) {
					$db->setQuery($q);
					try {
						$db->execute();
					} catch (Exception $e) {
						$status[LogLevel::DEBUG][] = $e->getMessage();
					}
				}
			} else {
				$status[LogLevel::DEBUG][] = 'Joomla initiated a logout of a blocked user thus skipped vBulletin destroySession() to prevent current user from getting logged out.';
			}
		} catch (Exception $e) {
			$status[LogLevel::ERROR][] = $e->getMessage();
		}
		return $status;
	}

	/**
	 * @param Userinfo $userinfo
	 * @param array    $options
	 *
	 * @throws \RuntimeException
	 * @return array
	 */
	function createSession(Userinfo $userinfo, $options)
	{
		$status = array('error' => array(), 'debug' => array());
		//first check to see if striking is enabled to prevent further strikes
		$db = Factory::getDatabase($this->getJname());

		$query = $db->getQuery(true)
			->select('value')
			->from('#__setting')
			->where('varname = ' . $db->quote('usestrikesystem'));

		$db->setQuery($query);
		$strikeEnabled = $db->loadResult();

		if ($strikeEnabled) {
			$ip = $_SERVER['REMOTE_ADDR'];
			$time = strtotime('-15 minutes');

			$query = $db->getQuery(true)
				->select('COUNT(*)')
				->from('#__strikes')
				->where('strikeip = ' . $db->quote($ip))
				->where('striketime >= ' . (int)$time);

			$db->setQuery($query);
			$strikes = $db->loadResult();

			if ($strikes >= 5) {
				throw new RuntimeException(Text::_('VB_TOO_MANY_STRIKES'));
			}
		}

		//make sure a session is not already active for this user
		$cookie_prefix = $this->params->get('cookie_prefix');
		$vbversion = $this->helper->getVersion();
		if ((int) substr($vbversion, 0, 1) > 3) {
			if (substr($cookie_prefix, -1) !== '_') {
				$cookie_prefix .= '_';
			}
		}
		$cookie_salt = $this->params->get('cookie_salt');
		$cookie_domain = $this->params->get('cookie_domain');
		$cookie_path = $this->params->get('cookie_path');
		$cookie_expires  = (!empty($options['remember'])) ? 0 : $this->params->get('cookie_expires');
		if ($cookie_expires == 0) {
			$expires_time = time() + (60 * 60 * 24 * 365);
		} else {
			$expires_time = time() + (60 * $cookie_expires);
		}
		$passwordhash = md5($userinfo->password . $cookie_salt);

		$query = $db->getQuery(true)
			->select('sessionhash')
			->from('#__session')
			->where('userid = ' . (int)$userinfo->userid);

		$db->setQuery($query);
		$sessionhash = $db->loadResult();

		$mainframe = Application::getInstance();
		$cookie_sessionhash = $mainframe->input->cookie->get($cookie_prefix . 'sessionhash', '');
		$cookie_userid = $mainframe->input->cookie->get($cookie_prefix . 'userid', '');
		$cookie_password = $mainframe->input->cookie->get($cookie_prefix . 'password', '');

		if (!empty($cookie_userid) && $cookie_userid == $userinfo->userid && !empty($cookie_password) && $cookie_password == $passwordhash) {
			$vbcookieuser = true;
		} else {
			$vbcookieuser = false;
		}

		if (!$vbcookieuser && (empty($cookie_sessionhash) || $sessionhash != $cookie_sessionhash)) {
			$secure = $this->params->get('secure', false);
			$httponly = $this->params->get('httponly', true);

			$cookies = Factory::getCookies();
			$status[LogLevel::DEBUG][] = $cookies->addCookie($cookie_prefix . 'userid', $userinfo->userid, $expires_time,  $cookie_path, $cookie_domain, $secure, $httponly);
			$status[LogLevel::DEBUG][] = $cookies->addCookie($cookie_prefix . 'password', $passwordhash, $expires_time, $cookie_path, $cookie_domain, $secure, $httponly, true);
		} else {
			$status[LogLevel::DEBUG][] = Text::_('VB_SESSION_ALREADY_ACTIVE');
			/**
			 * do not want to output as it indicate the cookies are set when they are not.
			$status[LogLevel::DEBUG][Text::_('COOKIES')][] = array(Text::_('NAME') => $cookie_prefix.'userid', Text::_('VALUE') => $cookie_userid, Text::_('EXPIRES') => $debug_expiration, Text::_('COOKIE_PATH') => $cookie_path, Text::_('COOKIE_DOMAIN') => $cookie_domain);
			$status[LogLevel::DEBUG][Text::_('COOKIES')][] = array(Text::_('NAME') => $cookie_prefix.'password', Text::_('VALUE') => substr($cookie_password, 0, 6) . '********, ', Text::_('EXPIRES') => $debug_expiration, Text::_('COOKIE_PATH') => $cookie_path, Text::_('COOKIE_DOMAIN') => $cookie_domain);
			$status[LogLevel::DEBUG][Text::_('COOKIES')][] = array(Text::_('NAME') => $cookie_prefix.'sessionhash', Text::_('VALUE') => $cookie_sessionhash, Text::_('EXPIRES') => $debug_expiration, Text::_('COOKIE_PATH') => $cookie_path, Text::_('COOKIE_DOMAIN') => $cookie_domain);
			 */
		}
		return $status;
	}

	/**
	 * @param Userinfo $userinfo
	 * @param Userinfo $existinguser
	 *
	 * @return void
	 */
	function updatePassword(Userinfo $userinfo, Userinfo &$existinguser)
	{
		$existinguser->password_salt = $this->genRandomPassword(3);
		$existinguser->password = md5(md5($userinfo->password_clear) . $existinguser->password_salt);

		$date = date('Y-m-d');

		$db = Factory::getDatabase($this->getJname());

		$query = $db->getQuery(true)
			->update('#__user')
			->set('passworddate = ' . $db->quote($date))
			->set('password = ' . $db->quote($existinguser->password))
			->set('salt = ' . $db->quote($existinguser->password_salt))
			->where('userid  = ' . (int)$existinguser->userid);

		$db->setQuery($query);
		$db->execute();

		$this->debugger->addDebug(Text::_('PASSWORD_UPDATE') . ' ' . substr($existinguser->password, 0, 6) . '********');
	}

	/**
	 * @param Userinfo $userinfo
	 * @param Userinfo $existinguser
	 *
	 * @return void
	 */
	function updateEmail(Userinfo $userinfo, Userinfo &$existinguser)
	{
		$apidata = array('userinfo' => $userinfo, 'existinguser' => $existinguser);
		$response = $this->helper->apiCall('updateEmail', $apidata);

		if($response['success']) {
			$this->debugger->addDebug(Text::_('EMAIL_UPDATE') . ': ' . $existinguser->email . ' -> ' . $userinfo->email);
		}
		foreach ($response['errors'] as $error) {
			$this->debugger->addError(Text::_('EMAIL_UPDATE_ERROR') . ' ' . $error);
		}
		foreach ($response['debug'] as $debug) {
			$this->debugger->addDebug($debug);
		}
	}

	/**
	 * @param Userinfo $userinfo
	 * @param Userinfo &$existinguser
	 *
	 * @return void
	 */
	function blockUser(Userinfo $userinfo, Userinfo &$existinguser)
	{
		$db = Factory::getDatabase($this->getJname());

		//get the id of the banned group
		$bannedgroup = $this->params->get('bannedgroup');

		//update the usergroup to banned
		$query = $db->getQuery(true)
			->update('#__user')
			->set('usergroupid = ' . $db->quote($bannedgroup))
			->where('userid  = ' . (int)$existinguser->userid);

		$db->setQuery($query);

		$db->execute();

		//add a banned user catch to vbulletin database
		$ban = new stdClass;
		$ban->userid = $existinguser->userid;
		$ban->usergroupid = $existinguser->group_id;
		$ban->displaygroupid = $existinguser->displaygroupid;
		$ban->customtitle = $existinguser->customtitle;
		$ban->usertitle = $existinguser->usertitle;
		$ban->adminid = 1;
		$ban->bandate = time();
		$ban->liftdate = 0;
		$ban->reason = (!empty($status['aec'])) ? $status['block_message'] : $this->params->get('blockmessage');

		//now append or update the new user data

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from('#__userban')
			->where('userid = ' . (int)$existinguser->userid);

		$db->setQuery($query);
		$banned = $db->loadResult();

		if ($banned) {
			$db->updateObject('#__userban', $ban, 'userid');
		} else {
			$db->insertObject('#__userban', $ban, 'userid');
		}

		$this->debugger->addDebug(Text::_('BLOCK_UPDATE') . ': ' . $existinguser->block . ' -> ' . $userinfo->block);

		//note that blockUser has been called
		if (empty($status['aec'])) {
			define('VBULLETIN_BLOCKUSER_CALLED', 1);
		}
	}

	/**
	 * @param Userinfo $userinfo
	 * @param Userinfo $existinguser
	 *
	 * @return void
	 */
	function unblockUser(Userinfo $userinfo, Userinfo &$existinguser)
	{
		$usergroups = $this->getCorrectUserGroups($existinguser);
		$usergroup = $usergroups[0];

		//found out what usergroup should be used
		$bannedgroup = $this->params->get('bannedgroup');

		//first check to see if user is banned and if so, retrieve the prebanned fields
		//must be something other than $db because it conflicts with vbulletin global variables
		$db = Factory::getDatabase($this->getJname());

		$query = $db->getQuery(true)
			->select('b.*, g.usertitle AS bantitle')
			->from('#__userban AS b')
			->innerJoin('#__user AS u ON b.userid = u.userid')
			->innerJoin('#__usergroup AS g ON u.usergroupid = g.usergroupid')
			->where('b.userid = ' . (int)$existinguser->userid);

		$db->setQuery($query);
		$result = $db->loadObject();

		$defaultgroup = $usergroup->defaultgroup;
		$displaygroup = $usergroup->displaygroup;

		$defaulttitle = $this->getDefaultUserTitle($defaultgroup, $existinguser->posts);

		$apidata = array(
			"userinfo" => $userinfo,
			"existinguser" => $existinguser,
			"usergroups" => $usergroup,
			"bannedgroup" => $bannedgroup,
			"defaultgroup" => $defaultgroup,
			"displaygroup" => $displaygroup,
			"defaulttitle" => $defaulttitle,
			"result" => $result
		);
		$response = $this->helper->apiCall('unblockUser', $apidata);

		if ($result) {
			//remove any banned user catches from vbulletin database
			$query = $db->getQuery(true)
				->delete('#__userban')
				->where('userid = ' . (int)$existinguser->userid);

			$db->setQuery($query);
			$db->execute();
		}

		if ($response['success']) {
			$this->debugger->addDebug(Text::_('BLOCK_UPDATE') . ': ' . $existinguser->block . ' -> ' . $userinfo->block);
		}
		foreach ($response['errors'] as $error) {
			$this->debugger->addError(Text::_('BLOCK_UPDATE_ERROR') . ': ' . $error);
		}
		foreach ($response['debug'] as $debug) {
			$this->debugger->addError($debug);
		}
	}

	/**
	 * @param Userinfo $userinfo
	 * @param Userinfo $existinguser
	 *
	 * @return void
	 */
	function activateUser(Userinfo $userinfo, Userinfo &$existinguser)
	{
		//found out what usergroup should be used
		$usergroups = $this->getCorrectUserGroups($existinguser);
		$usergroup = $usergroups[0];

		//update the usergroup to default group
		$db = Factory::getDatabase($this->getJname());

		$query = $db->getQuery(true)
			->update('#__user')
			->set('usergroupid = ' . $db->quote($usergroup->defaultgroup))
			->where('userid  = ' . (int)$existinguser->userid);

		$db->setQuery($query);
		$db->execute();

		//remove any activation catches from vbulletin database
		$query = $db->getQuery(true)
			->delete('#__useractivation')
			->where('userid = ' . (int)$existinguser->userid);

		$db->setQuery($query);
		$db->execute();

		$this->debugger->addDebug(Text::_('ACTIVATION_UPDATE') . ': ' . $existinguser->activation . ' -> ' . $userinfo->activation);
	}

	/**
	 * @param Userinfo $userinfo
	 * @param Userinfo $existinguser
	 *
	 * @return void
	 */
	function inactivateUser(Userinfo $userinfo, Userinfo &$existinguser)
	{
		//found out what usergroup should be used
		$activationgroup = $this->params->get('activationgroup');

		//update the usergroup to awaiting activation
		$db = Factory::getDatabase($this->getJname());

		$query = $db->getQuery(true)
			->update('#__user')
			->set('usergroupid = ' . $db->quote($activationgroup))
			->where('userid  = ' . (int)$existinguser->userid);

		$db->setQuery($query);
		$db->execute();

		//update the activation status
		//check to see if the user is already inactivated
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from('#__useractivation')
			->where('userid = ' . (int)$existinguser->userid);

		$db->setQuery($query);
		$count = $db->loadResult();
		if (empty($count)) {
			//if not, then add an activation catch to vbulletin database
			$useractivation = new stdClass;
			$useractivation->userid = $existinguser->userid;
			$useractivation->dateline = time();
			$useractivation->activationid = $this->genRandomPassword(40);

			$usergroups = $this->getCorrectUserGroups($existinguser);
			$usergroup = $usergroups[0];
			$useractivation->usergroupid = $usergroup->defaultgroup;

			$db->insertObject('#__useractivation', $useractivation, 'useractivationid' );

			$apidata = array('existinguser' => $existinguser);
			$response = $this->helper->apiCall('inactivateUser', $apidata);
			if ($response['success']) {
				$this->debugger->addDebug(Text::_('ACTIVATION_UPDATE') . ': ' . $existinguser->activation . ' -> ' . $userinfo->activation);
			}
			foreach ($response['errors'] as $error) {
				$this->debugger->addError(Text::_('ACTIVATION_UPDATE_ERROR') . ' ' . $error);
			}
			foreach ($response['debug'] as $debug) {
				$this->debugger->addDebug($debug);
			}
		} else {
			$this->debugger->addDebug(Text::_('ACTIVATION_UPDATE') . ': ' . $existinguser->activation . ' -> ' . $userinfo->activation);
		}
	}

	/**
	 * @param Userinfo $userinfo
	 *
	 * @throws \RuntimeException
	 *
	 * @return Userinfo
	 */
	function createUser(Userinfo $userinfo)
	{
		$newuser = null;
		//get the default user group and determine if we are using simple or advanced
		$usergroups = $this->getCorrectUserGroups($userinfo);

		//return if we are in advanced user group mode but the master did not pass in a group_id
		if (empty($usergroups)) {
			throw new RuntimeException(Text::_('ADVANCED_GROUPMODE_MASTER_NOT_HAVE_GROUPID'));
		} else {
			$usergroup = $usergroups[0];
			if (empty($userinfo->activation)) {
				$defaultgroup = $usergroup->defaultgroup;
				$setAsNeedsActivation = false;
			} else {
				$defaultgroup = $this->params->get('activationgroup');
				$setAsNeedsActivation = true;
			}

			$apidata = array();
			$apidata['usergroups'] = $usergroup;
			$apidata['defaultgroup'] = $defaultgroup;

			$usertitle = $this->getDefaultUserTitle($defaultgroup);
			$userinfo->usertitle = $usertitle;

			if (!isset($userinfo->password_clear)) {
				//clear password is not available, set a random password for now
				$random_password = Framework::getHash($this->genRandomPassword(10));
				$userinfo->password_clear = $random_password;
			}

			//set the timezone
			if (!isset($userinfo->timezone)) {
				$userinfo->timezone = Config::get()->get('offset', 'UTC');
			}

			$timezone = new DateTimeZone($userinfo->timezone);
			$offset = $timezone->getOffset(new DateTime('NOW'));
			$userinfo->timezone = $offset/3600;

			$apidata['userinfo'] = $userinfo;

			//performs some final VB checks before saving
			$response = $this->helper->apiCall('createUser', $apidata);
			foreach ($response['errors'] as $error) {
				throw new RuntimeException($error);
			}
			foreach ($response['debug'] as $debug) {
				$this->debugger->addDebug($debug);
			}

			if ($response['success']) {
				$userdmid = $response['new_id'];
				//if we set a temp password, we need to move the hashed password over
				if (!isset($userinfo->password_clear)) {
					try {
						$db = Factory::getDatabase($this->getJname());

						$query = $db->getQuery(true)
							->update('#__user')
							->set('password = ' . $db->quote($userinfo->password))
							->where('userid  = ' . (int)$userdmid);

						$db->setQuery($query);
						$db->execute();
					} catch (Exception $e) {
						$status[LogLevel::DEBUG][] = Text::_('USER_CREATION_ERROR') . '. '. Text::_('USERID') . ' ' . $userdmid . ': ' . Text::_('MASTER_PASSWORD_NOT_COPIED');
					}
				}

				$newuser = $this->getUser($userinfo);

				//does the user still need to be activated?
				if ($setAsNeedsActivation) {
					try {
						$this->inactivateUser($userinfo, $newuser);
					} catch (Exception $e) {
					}
				}
				$newuser = $this->getUser($userinfo);
			}
		}
		return $newuser;
	}

	/**
	 * @param Userinfo $userinfo
	 * @param Userinfo &$existinguser
	 *
	 * @return bool
	 */
	function executeUpdateUsergroup(Userinfo $userinfo, Userinfo &$existinguser)
	{
		$update_groups = false;
		$usergroups = $this->getCorrectUserGroups($userinfo);
		$usergroup = $usergroups[0];

		$membergroupids = (isset($usergroup->membergroups)) ? $usergroup->membergroups : array();

		//check to see if the default groups are different
		if ($usergroup->defaultgroup != $existinguser->group_id ) {
			$update_groups = true;
		} elseif ($this->params->get('compare_displaygroups', true) && $usergroup->displaygroup != $existinguser->displaygroupid ) {
			//check to see if the display groups are different
			$update_groups = true;
		} elseif ($this->params->get('compare_membergroups', true)) {
			//check to see if member groups are different
			$current_membergroups = explode(',', $existinguser->membergroupids);
			if (count($current_membergroups) != count($membergroupids)) {
				$update_groups = true;
			} else {
				foreach ($membergroupids as $gid) {
					if (!in_array($gid, $current_membergroups)) {
						$update_groups = true;
						break;
					}
				}
			}

		}

		if ($update_groups) {
			$this->updateUsergroup($userinfo, $existinguser);
		}
		return $update_groups;
	}

	/**
	 * @param Userinfo $userinfo
	 * @param Userinfo $existinguser
	 *
	 * @throws RuntimeException
	 * @return void
	 */
	public function updateUsergroup(Userinfo $userinfo, Userinfo &$existinguser)
	{
		//check to see if we have a group_id in the $userinfo, if not return
		$usergroups = $this->getCorrectUserGroups($userinfo);
		if (empty($usergroups)) {
			throw new RuntimeException(Text::_('ADVANCED_GROUPMODE_MASTER_NOT_HAVE_GROUPID'));
		} else {
			$usergroup = $usergroups[0];
			$defaultgroup = $usergroup->defaultgroup;
			$displaygroup = $usergroup->displaygroup;
			$titlegroupid = (!empty($displaygroup)) ? $displaygroup : $defaultgroup;
			$usertitle = $this->getDefaultUserTitle($titlegroupid);

			$apidata = array(
				'existinguser' => $existinguser,
				'userinfo' => $userinfo,
				'usergroups' => $usergroup,
				'usertitle' => $usertitle
			);
			$response = $this->helper->apiCall('updateUsergroup', $apidata);

			if ($response['success']) {
				$this->debugger->addDebug(Text::_('GROUP_UPDATE') . ': ' . $existinguser->group_id . ' -> ' . $usergroup->defaultgroup);
			}
			foreach ($response['errors'] AS $error) {
				$this->debugger->addError(Text::_('GROUP_UPDATE_ERROR') . ' ' . $error);
			}
			foreach ($response['debug'] as $debug) {
				$this->debugger->addDebug($debug);
			}
		}
	}

	/**
	 * the user's title based on number of posts
	 *
	 * @param $groupid
	 * @param int $posts
	 *
	 * @return mixed
	 */
	function getDefaultUserTitle($groupid, $posts = 0)
	{
		try {
			$db = Factory::getDatabase($this->getJname());

			$query = $db->getQuery(true)
				->select('usertitle')
				->from('#__usergroup')
				->where('usergroupid = ' . (int)$groupid);

			$db->setQuery($query);
			$title = $db->loadResult();

			if (empty($title)) {
				$query = $db->getQuery(true)
					->select('title')
					->from('#__usertitle')
					->where('minposts <= ' . (int)$posts)
					->order('minposts DESC');

				$db->setQuery($query, 0, 1);
				$title = $db->loadResult();
			}
		} catch (Exception $e) {
			$title = '';
		}
		return $title;
	}

	/**
	 * Function That find the correct user group index
	 *
	 * @param Userinfo $userinfo
	 *
	 * @return int
	 */
	function getUserGroupIndex(Userinfo $userinfo)
	{
		$index = 0;

		$master = Framework::getMaster();
		if ($master) {
			$mastergroups = Groups::get($master->name);

			foreach ($mastergroups as $key => $mastergroup) {
				if ($mastergroup) {
					$found = true;
					//check to see if the default groups are different
					if ($mastergroup->defaultgroup != $userinfo->group_id ) {
						$found = false;
					} else {
						if ($this->params->get('compare_displaygroups', true) && $mastergroup->displaygroup != $userinfo->displaygroupid ) {
							//check to see if the display groups are different
							$found = false;
						} else {
							if ($this->params->get('compare_membergroups', true) && isset($mastergroup->membergroups)) {
								//check to see if member groups are different
								$current_membergroups = explode(',', $userinfo->membergroupids);
								if (count($current_membergroups) != count($mastergroup->membergroups)) {
									$found = false;
									break;
								} else {
									foreach ($mastergroup->membergroups as $gid) {
										if (!in_array($gid, $current_membergroups)) {
											$found = false;
											break;
										}
									}
								}
							}
						}
					}
					if ($found) {
						$index = $key;
						break;
					}
				}
			}
		}

		return $index;
	}
}
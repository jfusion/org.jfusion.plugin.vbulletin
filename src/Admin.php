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
use JFusion\Factory;
use JFusion\Framework;

use JFusion\User\Groups;
use Joomla\Database\DatabaseFactory;
use Joomla\Form\Html\Select;
use Joomla\Language\Text;

use Psr\Log\LogLevel;

use Exception;
use RuntimeException;
use stdClass;

/**
 * JFusion Admin Class for vBulletin
 * For detailed descriptions on these functions please check Admin
 *
 * @category   Plugins
 * @package    JFusion\Plugins
 * @subpackage vbulletin
 * @author     JFusion Team <webmaster@jfusion.org>
 * @copyright  2008 JFusion. All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link       http://www.jfusion.org
 */
class Admin extends \JFusion\Plugin\Admin
{
	static private $mods = array('jfvbtask' => 'JFusion API Plugin - REQUIRED',
		'frameless' => 'JFusion Frameless Integration Plugin',
		'globalfix' => 'JFusion Global Fix Plugin');

	/**
	 * @var $helper Helper
	 */
	var $helper;

	/**
	 * @return string
	 */
	function getTablename()
	{
		return 'user';
	}

	/**
	 * @param string $softwarePath
	 *
	 * @return array
	 */
	function setupFromPath($softwarePath)
	{
		$myfile = $softwarePath . 'includes/config.php';
		$funcfile = $softwarePath . 'includes/functions.php';

		//try to open the file
		$params = array();
		$lines = $this->readFile($myfile);
		if ($lines === false) {
			Framework::raise(LogLevel::WARNING, Text::_('WIZARD_FAILURE') . ': ' . $myfile . ' ' . Text::_('WIZARD_MANUAL'), $this->getJname());
			return false;
		} else {
			//parse the file line by line to get only the config variables
			$config = array();

			foreach ($lines as $line) {
				if (strpos($line, '$config') === 0) {
					$vars = explode('\'', $line);
					if (isset($vars[5])) {
						$name1 = trim($vars[1], ' $=');
						$name2 = trim($vars[3], ' $=');
						$value = trim($vars[5], ' $=');
						$config[$name1][$name2] = $value;
					}
				}
			}

			//save the parameters into the standard JFusion params format
			$params = array();
			$params['database_host'] = $config['MasterServer']['servername'];
			$params['database_type'] = $config['Database']['dbtype'];
			$params['database_name'] = $config['Database']['dbname'];
			$params['database_user'] = $config['MasterServer']['username'];
			$params['database_password'] = $config['MasterServer']['password'];
			$params['database_prefix'] = $config['Database']['tableprefix'];
			$params['cookie_prefix'] = $config['Misc']['cookieprefix'];
			$params['source_path'] = $softwarePath;
			//find the path to vbulletin, for this we need a database connection
			$host = $config['MasterServer']['servername'];
			$user = $config['MasterServer']['username'];
			$password = $config['MasterServer']['password'];
			$database = $config['Database']['dbname'];
			$prefix = $config['Database']['tableprefix'];
			$driver = 'mysql';
			$options = array('driver' => $driver, 'host' => $host, 'user' => $user, 'password' => $password, 'database' => $database, 'prefix' => $prefix);

			$db = DatabaseFactory::getInstance($options)->getDriver($driver, $options);

			if (method_exists($db, 'setQuery')) {
				//Find the path to vbulletin
				$query = $db->getQuery(true)
					->select('value, varname')
					->from('#__setting')
					->where('varname IN (\'bburl\',\'cookietimeout\',\'cookiepath\',\'cookiedomain\')');

				$db->setQuery($query);
				$settings = $db->loadObjectList('varname');
				$params['source_url'] = $settings['bburl']->value;
				$params['cookie_expires'] = $settings['cookietimeout']->value;
				$params['cookie_path'] = $settings['cookiepath']->value;
				$params['cookie_domain'] = $settings['cookiedomain']->value;
			}

			$lines = $this->readFile($funcfile);
			if ($lines !== false) {
				$cookie_salt = '';
				foreach ($lines as $line) {
					if (strpos($line, 'COOKIE_SALT') !== false) {
						$vars = explode('\'', $line);
						if (isset($vars[3])) {
							$cookie_salt = $vars[3];
						}
						break;
					}
				}

				$params['cookie_salt'] = $cookie_salt;
			}
		}
		return $params;
	}

	/**
	 * @return string
	 */
	function getRegistrationURL()
	{
		return 'register.php';
	}

	/**
	 * @return string
	 */
	function getLostPasswordURL()
	{
		return 'login.php?do=lostpw';
	}

	/**
	 * @return string
	 */
	function getLostUsernameURL()
	{
		return 'login.php?do=lostpw';
	}

	/**
	 * Returns the a list of users of the integrated software
	 *
	 * @param int $limitstart start at
	 * @param int $limit number of results
	 *
	 * @return array
	 */
	function getUserList($limitstart = 0, $limit = 0)
	{
		try {
			// initialise some objects
			$db = Factory::getDatabase($this->getJname());

			$query = $db->getQuery(true)
				->select('username, email')
				->from('#__user');

			$db->setQuery($query, $limitstart, $limit);
			//getting the results
			$userlist = $db->loadObjectList();
		} catch (Exception $e) {
			Framework::raise(LogLevel::ERROR, $e, $this->getJname());
			$userlist = array();
		}
		return $userlist;
	}

	/**
	 * @return int
	 */
	function getUserCount()
	{
		try {
			//getting the connection to the db
			$db = Factory::getDatabase($this->getJname());

			$query = $db->getQuery(true)
				->select('count(*)')
				->from('#__user');

			$db->setQuery($query);
			//getting the results
			$no_users = $db->loadResult();
		} catch (Exception $e) {
			Framework::raise(LogLevel::ERROR, $e, $this->getJname());
			$no_users = 0;
		}
		return $no_users;
	}

	/**
	 * @return array
	 */
	function getUsergroupList()
	{
		//get the connection to the db
		$db = Factory::getDatabase($this->getJname());

		$query = $db->getQuery(true)
			->select('usergroupid as id, title as name')
			->from('#__usergroup');

		$db->setQuery($query);
		//getting the results
		return $db->loadObjectList();
	}

	/**
	 * @return array
	 */
	function getDefaultUsergroup()
	{
		$usergroup = Groups::get($this->getJname(), true);

		$group = array();
		if ($usergroup !== null) {
			//we want to output the usergroup name
			$db = Factory::getDatabase($this->getJname());

			if (!isset($usergroup->membergroups)) {
				$usergroup->membergroups = array($usergroup->defaultgroup);
			} else if (!in_array($usergroup->defaultgroup, $usergroup->membergroups)) {
				$usergroup->membergroups[] = $usergroup->defaultgroup;
			}
			foreach ($usergroup->membergroups as $g) {
				$query = $db->getQuery(true)
					->select('title')
					->from('#__usergroup')
					->where('usergroupid = ' . $db->quote($g));

				$db->setQuery($query);
				$group[] = $db->loadResult();
			}
		}
		return $group;
	}

	/**
	 * @return bool
	 */
	function allowRegistration()
	{
		$result = false;
		try {
			$db = Factory::getDatabase($this->getJname());

			$query = $db->getQuery(true)
				->select('value')
				->from('#__setting')
				->where('varname = ' . $db->quote('allowregistration'));

			$db->setQuery($query);
			//getting the results
			$new_registration = $db->loadResult();
			if ($new_registration == 1) {
				$result = true;
			}
		} catch (Exception $e) {
			Framework::raise(LogLevel::ERROR, $e, $this->getJname());
		}
		return $result;
	}

	/**
	 * @param string $name         name of element
	 * @param string $value        value of element
	 * @param string $node         node
	 * @param string $control_name name of controller
	 *
	 * @return string html
	 */
	function jfvbtask($name, $value, $node, $control_name)
	{
		return $this->renderHook($name);
	}

	/**
	 * @param string $name         name of element
	 * @param string $value        value of element
	 * @param string $node         node
	 * @param string $control_name name of controller
	 *
	 * @return string html
	 */
	function frameless($name, $value, $node, $control_name)
	{
		return $this->renderHook($name);
	}

	/**
	 * @param string $name         name of element
	 * @param string $value        value of element
	 * @param string $node         node
	 * @param string $control_name name of controller
	 *
	 * @return string html
	 */
	function globalfix($name, $value, $node, $control_name)
	{
		return $this->renderHook($name);
	}

	/**
	 * @param string $name         name of element
	 *
	 * @return string html
	 */
	function renderHook($name)
	{
		/**
		 * TODO: REMOVE/MOVE ? or fix image path?
		 */
		try {
			try {
				$db = Factory::getDatabase($this->getJname());
			} catch (Exception $e) {
				throw new RuntimeException(Text::_('VB_CONFIG_FIRST'));
			}
			$secret = $this->params->get('vb_secret', null);
			if (empty($secret)) {
				throw new RuntimeException(Text::_('VB_SECRET_EMPTY'));
			}

			$query = $db->getQuery(true)
				->select('COUNT(*)')
				->from('#__plugin')
				->where('hookname = ' . $db->quote('init_startup'))
				->where('title = ' . $db->quote(static::$mods[$name]))
				->where('active = 1');

			$db->setQuery($query);
			$check = ($db->loadResult() > 0) ? true : false;

			if ($check) {
				//return success
				$enabled = Text::_('ENABLED');
				$disable = Text::_('DISABLE_THIS_PLUGIN');
				$reenable = Text::_('REENABLE_THIS_PLUGIN');
				$output = <<<HTML
					<span class="check" style="color: green;">&#x2714;</span>
                    <span style="float: left; margin-left: 5px;">{$enabled}</span>
                    <a style="margin-left:5px; float: left;" href="javascript:void(0);" onclick="return JFusion.Plugin.module('toggleHook', '{$name}', 'disable');">{$disable}</a>
                    <a style="margin-left:5px; float: left;" href="javascript:void(0);" onclick="return JFusion.Plugin.module('toggleHook', '{$name}', 'reenable');">{$reenable}</a>
HTML;
			} else {
				$disabled = Text::_('DISABLED');
				$enable = Text::_('ENABLE_THIS_PLUGIN');
				$output = <<<HTML
					<span class="check" style="color: red;">&#x2716;</span>
                    <span style="float: left; margin-left: 5px;">{$disabled}</span>
                    <a style="margin-left:5px; float: left;" href="javascript:void(0);" onclick="return JFusion.Plugin.module('toggleHook', '{$name}', 'enable');">{$enable}</a>
HTML;
			}
		} catch (Exception $e) {
			$output = $e->getMessage();
		}
		return $output;
	}

	/**
	 * @param string $name         name of element
	 * @param string $value        value of element
	 * @param string $node         node
	 * @param string $control_name name of controller
	 *
	 * @return string html
	 */
	function framelessoptimization($name, $value, $node, $control_name)
	{
		try {
			try {
				$db = Factory::getDatabase($this->getJname());
			} catch (Exception $e) {
				throw new RuntimeException(Text::_('VB_CONFIG_FIRST'));
			}

			//let's first check the default icon
			$query = $db->getQuery(true)
				->select('value')
				->from('#__setting')
				->where('varname = ' . $db->quote('showdeficon'));

			$db->setQuery($query);
			$deficon = $db->loadResult();
			$check = (!empty($deficon) && strpos($deficon, 'http') === false) ? false : true;
			if ($check) {
				//this will perform functions like rewriting image paths to include the full URL to images to save processing time
				$tables = array('smilie' => 'smiliepath', 'avatar' => 'avatarpath', 'icon' => 'iconpath');
				foreach ($tables as $tbl => $col) {
					$query = $db->getQuery(true)
						->select($db->quoteName($col))
						->from($db->quoteName('#__' . $tbl));

					$db->setQuery($query);
					$images = $db->loadRowList();
					if ($images) {
						foreach ($images as $image) {
							$check = (strpos($image[0], 'http') !== false) ? true : false;
							if (!$check) break;
						}
					}
					if (!$check) break;
				}
			}
			if ($check) {
				//return success
				$complete = Text::_('COMPLETE');
				$undo = Text::_('VB_UNDO_OPTIMIZATION');
				$output = <<<HTML
							<span class="check" style="color: green;">&#x2714;</span>
		                    <span style="float: left; margin-left: 5px;">{$complete}</span>
		                    <a style="margin-left:5px; float: left;" href="javascript:void(0);" onclick="return JFusion.Plugin.module('toggleHook', 'framelessoptimization', 'disable');">{$undo}</a>
HTML;
				return $output;
			} else {
				$incomplete = Text::_('INCOMPLETE');
				$do = Text::_('VB_DO_OPTIMIZATION');
				$output = <<<HTML
							<span class="check" style="color: red;">&#x2716;</span>
		                    <span style="float: left; margin-left: 5px;">{$incomplete}</span>
		                    <a style="margin-left:5px; float: left;" href="javascript:void(0);" onclick="return JFusion.Plugin.module('toggleHook', 'framelessoptimization', 'enable');">{$do}</a>
HTML;
				return $output;
			}


		} catch (Exception $e) {
			$output = $e->getMessage();
		}
		return $output;
	}

	/**
	 * @param string $hook
	 * @param string $action
	 *
	 * @return void
	 */
	function toggleHook($hook, $action)
	{
		try {
			$db = Factory::getDatabase($this->getJname());
			if ($hook != 'framelessoptimization') {
				$params = Application::getInstance()->input->get('params', array(), 'array');
				$itemid = $params['plugin_itemid'];

				$hookName = static::$mods[$hook];

				if ($hookName) {
					//all three cases, we want to remove the old hook
					$query = $db->getQuery(true)
						->delete('#__plugin')
						->where('hookname = ' . $db->quote('init_startup'))
						->where('title = ' . $db->quote($hookName));

					$db->setQuery($query);
					$db->execute();

					//enable or re-enable the plugin
					if ($action != 'disable') {
						$secret = $this->params->get('vb_secret', null);
						if (empty($secret)) {
							Framework::raise(LogLevel::WARNING, Text::_('VB_SECRET_EMPTY'));
						} else {
							//install the hook
							$php = $this->getHookPHP($hook, $itemid);

							//add the post to the approval queue
							$plugin = new stdClass;
							$plugin->title = $hookName;
							$plugin->hookname = 'init_startup';
							$plugin->phpcode = $php;
							$plugin->product = 'vbulletin';
							$plugin->active = 1;
							$plugin->executionorder = 1;

							$db->insertObject('#__plugin', $plugin);
						}
					}
				}
			} else {
				//this will perform functions like rewriting image paths to include the full URL to images to save processing time
				$source_url = $this->params->get('source_url');
				if (substr($source_url, -1) != '/') {
					$source_url.= '/';
				}
				//let's first update all the image paths for database stored images
				$tables = array('smilie' => 'smiliepath', 'avatar' => 'avatarpath', 'icon' => 'iconpath');
				foreach ($tables as $tbl => $col) {
					$criteria = ($action == 'enable') ? 'NOT LIKE \'http%\'' : 'LIKE \'%http%\'';

					$query = $db->getQuery(true)
						->select($db->quoteName($tbl . 'id, ' . $col))
						->from($db->quoteName('#__' . $tbl))
						->where($db->quoteName($col) . ' ' . $criteria);

					$db->setQuery($query);
					$images = $db->loadRowList();
					foreach ($images as $i) {
						$q = $db->getQuery(true)
							->update($db->quoteName('#__' . $tbl));

						if ($action == 'enable') {
							$q->set($db->quoteName($col) . ' = ' . $q->quote($source_url . $i[1]));
						} else {
							$i[1] = str_replace($source_url, '', $i[1]);
							$q->set($db->quoteName($col) . ' = ' . $q->quote($i[1]));
						}

						$q->where($db->quoteName($tbl . 'id') . ' = ' . $q->quote($i[0]));

						$db->setQuery($q);
						$db->execute();
					}
				}
				//let's update the default icon
				$query = $db->getQuery(true)
					->select('value')
					->from('#__setting')
					->where('varname = ' . $db->quote('showdeficon'));

				$db->setQuery($query);
				$deficon = $db->loadResult();
				if (!empty($deficon)) {
					$query = $db->getQuery(true)
						->update('#__setting');

					if ($action == 'enable' && strpos($deficon, 'http') === false) {
						$query->set('value = ' . $db->quote($source_url . $deficon));
					} elseif ($action == 'disable') {
						$deficon = str_replace($source_url, '', $deficon);
						$query->set('value = ' . $db->quote($deficon));
					}
					$query->where('varname = ' . $db->quote('showdeficon'));

					$db->setQuery($query);
					$db->execute();
				}
			}
		} catch (Exception $e) {
			Framework::raise(LogLevel::ERROR, $e, $this->getJname());
		}
	}

	/**
	 * @param $plugin
	 *
	 * @return string
	 */
	function getHookPHP($plugin)
	{
		$hookFile = __DIR__ . '/hooks.php';
		$php = "defined('_VBJNAME') or define('_VBJNAME', '{$this->getJname()}');\n";
		$php .= "defined('JPATH_PATH') or define('JPATH_BASE', '" . (str_replace(DIRECTORY_SEPARATOR . 'administrator', '', JPATH_BASE)) . "');\n";
		$php .= "defined('JFUSION_VB_HOOK_FILE') or define('JFUSION_VB_HOOK_FILE', '$hookFile');\n";
		if ($plugin == 'globalfix') {
			$php .= "if (defined('_JEXEC') && empty(\$GLOBALS['vbulletin']) && !empty(\$vbulletin)) {\n";
			$php .= "\$GLOBALS['vbulletin'] = \$vbulletin;\n";
			$php .= "\$GLOBALS['db'] = \$vbulletin->db;\n";
			$php .= '}';
			return $php;
		} elseif ($plugin == 'frameless') {
			//we only want to initiate the frameless if we are inside Joomla or using AJAX
			$php .= "if (defined('_JEXEC') || isset(\$_GET['jfusion'])){\n";
		}

		$php .= "if (file_exists(JFUSION_VB_HOOK_FILE)) {\n";
		$php .= "include_once(JFUSION_VB_HOOK_FILE);\n";
		$php .= "\$val = '$plugin';\n";
		$secret = $this->params->get('vb_secret', Factory::getConfig()->get('secret'));
		$php .= "\$JFusionHook = new executeJFusionHook('init_startup', \$val, '$secret');\n";

		$version = $this->helper->getVersion();
		if (substr($version, 0, 1) > 3) {
			$php .= "vBulletinHook::set_pluginlist(\$vbulletin->pluginlist);\n";
		}
		$php .= "}\n";
		if ($plugin != 'jfvbtask') {
			$php .= "}\n";
		}
		return $php;
	}

	function debugConfigExtra()
	{
		$db = Factory::getDatabase($this->getJname());

		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from('#__plugin')
			->where('hookname = ' . $db->quote('init_startup'))
			->where('title = ' . $db->quote(static::$mods['jfvbtask']))
			->where('active = 1');

		$db->setQuery($query);
		if ($db->loadResult() == 0) {
			Framework::raise(LogLevel::WARNING, Text::_('VB_API_HOOK_NOT_INSTALLED'), $this->getJname());
		} else {
			$response = $this->helper->apiCall('ping', array('ping' => 1));
			if (!$response['success']) {
				Framework::raise(LogLevel::WARNING, Text::_('VB_API_HOOK_NOT_INSTALLED'), $this->getJname());
			}
		}
	}

	/**
	 * @param $name
	 * @param $value
	 * @param $node
	 * @param $control_name
	 *
	 * @return mixed|string
	 */
	function name_field($name, $value, $node, $control_name)
	{
		try {
			if ($this->isConfigured()) {
				try {
					$db = Factory::getDatabase($this->getJname());
				} catch (Exception $e) {
					throw new RuntimeException(Text::_('SAVE_CONFIG_FIRST'));
				}

				//get a list of field names for custom profile fields
				$custom_fields = $db->getTableColumns('#__userfield');

				$vb_options = array(Select::option('', '', 'id', 'name'));
				if ($custom_fields) {
					unset($custom_fields['userid']);
					unset($custom_fields['temp']);

					foreach($custom_fields as $field  => $type) {
						$query = $db->getQuery(true)
							->select('text')
							->from('#__phrase')
							->where('varname = ' . $db->quote($field . '_title'))
							->where('fieldname = ' . $db->quote('cprofilefield'));

						$db->setQuery($query, 0, 1);
						$title = $db->loadResult();
						$vb_options[] = Select::option($field, $title, 'id', 'name');
					}
				}

				$value = (empty($value)) ? '' : $value;

				return Select::genericlist( $vb_options, $control_name . '[' . $name . ']', 'class="inputbox"', 'id', 'name', $value);
			} else {
				throw new RuntimeException(Text::_('SAVE_CONFIG_FIRST'));
			}
		} catch (Exception $e) {
			return $e->getMessage();
		}
	}

	/**
	 * @return array
	 */
	function uninstall()
	{
		$return = false;
		$reasons = array();
		try {
			$db = Factory::getDatabase($this->getJname());
			$hookNames = array();

			foreach (static::$mods as $mod) {
				$hookNames[] = $db->quote($mod);
			}

			$query = $db->getQuery(true)
				->delete('#__plugin')
				->where('hookname = ' . $db->quote('init_startup'))
				->where('title IN (' . $db->quote(implode(', ', $hookNames)) . ')');

			$db->setQuery($query);
			$db->execute();

			$return = true;
		} catch (Exception $e) {
			$reasons[] = $e->getMessage();
		}

		return array($return, $reasons);
	}

	/*
	 * do plugin support multi usergroups
	 * return UNKNOWN for unknown
	 * return JNO for NO
	 * return JYES for YES
	 * return ... ??
	 */
	/**
	 * @return string
	 */
	function requireFileAccess()
	{
		return 'JYES';
	}

	/**
	 * create the render group function
	 *
	 * @return string
	 */
	function getRenderGroup()
	{
		$jname = $this->getJname();

		Application::getInstance()->loadScriptLanguage(array('MAIN_USERGROUP', 'DISPLAYGROUP', 'DEFAULT', 'MEMBERGROUPS'));

		$js = <<<JS
		JFusion.renderPlugin['{$jname}'] = function(index, plugin, pair, usergroups) {
			return (function( $ ) {
				var defaultgroup = $(pair).prop('defaultgroup');
				var displaygroup = $(pair).prop('displaygroup');
				var membergroups = $(pair).prop('membergroups');

				var root = $('<div></div>');

				// render default group
				root.append($('<div>' + JFusion.Text._('MAIN_USERGROUP') + '</div>'));

				var defaultselect = $('<select></select>');
				defaultselect.attr('name', 'usergroups['+plugin.name+']['+index+'][defaultgroup]');
				defaultselect.attr('id', 'usergroups_'+plugin.name+index+'defaultgroup');

				defaultselect.change(function() {
	                var value = $(this).val();

					$('#'+'usergroups_'+plugin.name+index+'groups'+' option').each(function() {
						if ($(this).val() == value) {
							$(this).prop('selected', false);
							$(this).prop('disabled', true);

							$(this).trigger('chosen:updated').trigger('liszt:updated');
		                } else if ($(this).prop('disabled') === true) {
							$(this).prop('disabled', false);
							$(this).trigger('chosen:updated').trigger('liszt:updated');
						}
					});
				});

	            $.each(usergroups, function( key, group ) {
	                var option = $('<option></option>');
					option.val(group.id);
	                option.html(group.name);

			        if (pair && defaultgroup && defaultgroup == group.id) {
						option.attr('selected','selected');
			        }

					defaultselect.append(option);
	            });

			    root.append(defaultselect);

				// render display group
				root.append($('<div>' + JFusion.Text._('DISPLAYGROUP') + '</div>'));

				var displayselect = $('<select></select>');
				displayselect.attr('name', 'usergroups['+plugin.name+']['+index+'][displayselect]');
				displayselect.attr('id', 'usergroups_'+plugin.name+index+'displayselect');

				root.append($('<div>' + JFusion.Text._('DISPLAYGROUP') + '</div>'));

				var defaultoption = $('<option></option>');
					defaultoption.val(0);
	                defaultoption.html(JFusion.Text._('DEFAULT'));

				displayselect.append(defaultoption);

				$.each(usergroups, function( key, group ) {
				    if (group.id != 1 && group.id != 3 && group.id != 4) {
				        var option = $('<option></option>');
						option.val(group.id);
	                    option.html(group.name);

					    if (pair && displaygroup !== null && displaygroup == group.id) {
					        option.attr('selected', 'selected');
					    }
				        displayselect.append(option);
				    }
	            });
				root.append(displayselect);


				// render default member groups
				root.append($('<div>' + JFusion.Text._('MEMBERGROUPS') + '</div>'));

				var membergroupsselect = $('<select></select>');
				membergroupsselect.attr('name', 'usergroups['+plugin.name+']['+index+'][membergroups][]');
				membergroupsselect.attr('id', 'usergroups_'+plugin.name+index+'membergroups');
				membergroupsselect.attr('multiple', 'multiple');

			    Array.each(usergroups, function (group, i) {
					var option = $('<option></option>');
					displayselect.attr('id', 'usergroups_'+plugin.name+index+'membergroups'+group.id);
					option.val(group.id);
	                option.html(group.name);

			        if (pair && defaultgroup == group.id) {
						option.attr('disabled', 'disabled');
			        } else if (!pair && i === 0) {
			            option.attr('disabled', 'disabled');
			        } else {
			            if (pair && membergroups && membergroups.contains(group.id)) {
			                option.attr('selected', 'selected');
				        }
			        }

					membergroupsselect.append(option);
			    });
				root.append(membergroupsselect);
			    return root;
			})(jQuery);
		};
JS;
		return $js;
	}

	/**
	 * do plugin support multi usergroups
	 *
	 * @return bool
	 */
	function isMultiGroup()
	{
		return false;
	}
}
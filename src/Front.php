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

global $baseURL, $fullURL, $integratedURL, $vbsefmode;
/**
 * JFusion Public Class for vBulletin
 * For detailed descriptions on these functions please check Front
 *
 * @category   Plugins
 * @package    JFusion\Plugins
 * @subpackage vbulletin
 * @author     JFusion Team <webmaster@jfusion.org>
 * @copyright  2008 JFusion. All rights reserved.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link       http://www.jfusion.org
 */
class Front extends \JFusion\Plugin\Front
{
	/**
	 * @var Helper
	 */
	var $helper;

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
}
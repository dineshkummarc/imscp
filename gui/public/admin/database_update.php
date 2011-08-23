<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 *
 * @copyright	2001-2006 by moleSoftware GmbH
 * @copyright	2006-2010 by ispCP | http://isp-control.net
 * @copyright	2010-2011 by i-MSCP | http://i-mscp.net
 * @version		SVN: $Id$
 * @link		http://i-mscp.net
 * @author		ispCP Team
 * @author		i-MSCP Team
 *
 * @license
 * The contents of this file are subject to the Mozilla Public License
 * Version 1.1 (the "License"); you may not use this file except in
 * compliance with the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS"
 * basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 * License for the specific language governing rights and limitations
 * under the License.
 *
 * The Original Code is "VHCS - Virtual Hosting Control System".
 *
 * The Initial Developer of the Original Code is moleSoftware GmbH.
 * Portions created by Initial Developer are Copyright (C) 2001-2006
 * by moleSoftware GmbH. All Rights Reserved.
 *
 * Portions created by the ispCP Team are Copyright (C) 2006-2010 by
 * isp Control Panel. All Rights Reserved.
 *
 * Portions created by the i-MSCP Team are Copyright (C) 2010-2011 by
 * i-MSCP a internet Multi Server Control Panel. All Rights Reserved.
 */

/************************************************************************************
 * Script functions
 */

/**
 * Generate database update details.
 *
 * @param $tpl iMSCP_pTemplate
 * return void
 */
function admin_generateDatabaseUpdateDetail($tpl)
{
	$dbUpdatesDetail = iMSCP_Update_Database::getInstance()->getDatabaseUpdateDetail();

	foreach ($dbUpdatesDetail as $revision => $detail) {
		$tpl->assign(array(
						  'DB_UPDATE_REVISION' => (int)$revision,
						  'DB_UPDATE_DETAIL' => _admin_generateIssueTrackerLink(tohtml($detail))));

		$tpl->parse('DATABASE_UPDATE', '.database_update');
	}
}

/**
 * Generate issue tracker link for tickets references in database update detail.
 *
 * @access private
 * @param $detail database update detail
 * @return string
 */
function _admin_generateIssueTrackerLink($detail)
{
	return preg_replace(
		'/^(#[0-9]+)/',
		'<a href="http://sourceforge.net/apps/trac/i-mscp/ticket/\1" target="_blank" title="' .
			tr('More Details') .'">\1</a>',
		$detail);
}

/************************************************************************************
 * Main script
 */

// Include core library
require 'imscp-lib.php';

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onAdminScriptStart);

// Check for login
check_login(__FILE__);

/** @var $cfg iMSCP_Config_Handler_File */
$cfg = iMSCP_Registry::get('config');

/** @var $dbUpdate iMSCP_Update_Database */
$dbUpdate = iMSCP_Update_Database::getInstance();

if (isset($_POST['uaction']) && $_POST['uaction'] == 'update') {
	// Execute all available db updates
	if (!$dbUpdate->applyUpdates()) {
		throw new iMSCP_Exception($dbUpdate->getError());
	}

	// Set success page message
	set_page_message('All database update were successfully applied.', 'success');
	redirectTo('system_info.php');
}

$tpl = new iMSCP_pTemplate();
$tpl->define_dynamic(array('page' => $cfg->ADMIN_TEMPLATE_PATH . '/database_update.tpl',
						  'page_message' => 'page',
						  'database_updates' => 'page',
						  'database_update' => 'database_updates'));

$tpl->assign(array(
				  'THEME_CHARSET' => tr('encoding'),
				  'TR_PAGE_TITLE' => tr('i-MSCP - Admin / System tools / Database Update'),
				  'THEME_COLOR_PATH' => "../themes/{$cfg->USER_INITIAL_THEME}",
				  'ISP_LOGO' => layout_getUserLogo(),
				  'TR_SECTION_TITLE' => tr('Database updates')));

gen_admin_mainmenu($tpl, $cfg->ADMIN_TEMPLATE_PATH . '/main_menu_system_tools.tpl');
gen_admin_menu($tpl, $cfg->ADMIN_TEMPLATE_PATH . '/menu_system_tools.tpl');


if ($dbUpdate->isAvailableUpdate()) {
	set_page_message(tr('One or more database updates are now available. See the details below.'), 'info');
	admin_generateDatabaseUpdateDetail($tpl);

	$tpl->assign(array(
					  'TR_DATABASE_UPDATES' => tr('Database Updates Revision'),
					  'TR_DATABASE_UPDATE_DETAIL' => 'Database Update details',
					  'TR_PROCESS_UPDATES' => tr('Process updates')));
} else {
	$tpl->assign('DATABASE_UPDATES', '');
	set_page_message(tr('No database updates available.'), 'info');
}

generatePageMessage($tpl);

$tpl->parse('PAGE', 'page');

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onAdminScriptEnd,
											  new iMSCP_Events_Response($tpl));

$tpl->prnt();

unsetMessages();
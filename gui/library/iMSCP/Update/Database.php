<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2011 by i-MSCP team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @category	iMSCP
 * @package		iMSCP_Update
 * @subpackage	Database
 * @copyright	2010-2011 by i-MSCP team
 * @author		Daniel Andreca <sci2tech@gmail.com>
 * @author		Laurent Declercq <l.declercq@nuxwin.com>
 * @version		SVN: $Id$
 * @link		http://www.i-mscp.net i-MSCP Home Site
 * @license		http://www.gnu.org/licenses/gpl-2.0.txt GPL v2
 */

/** @see iMSCP_Update */
require_once 'iMSCP/Update.php';

/**
 * Update version class.
 *
 * Checks if an update is available for i-MSCP.
 *
 * @category	iMSCP
 * @package		iMSCP_Update
 * @subpackage	Database
 * @author		Daniel Andreca <sci2tech@gmail.com>
 * @author		Laurent Declercq <l.declercq@nuxwin.com>
 * @version		0.0.2
 */
class iMSCP_Update_Database extends iMSCP_Update
{
	/**
	 * @var iMSCP_Update
	 */
	protected static $_instance;

	/**
	 * Tells whether or not a request must be send to the i-MSCP daemon after that
	 * all database updates were applied.
	 *
	 * @var bool
	 */
	protected $_daemonRequest = false;

	/**
	 * Singleton - Make new unavailable.
	 */
	protected function __construct()
	{

	}

	/**
	 * Singleton - Make clone unavailable.
	 *
	 * @return void
	 */
	protected function __clone()
	{

	}

	/**
	 * Implements Singleton design pattern.
	 *
	 * @return iMSCP_Update_Database
	 */
	public static function getInstance()
	{
		if (null === self::$_instance) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Checks for available database update.
	 *
	 * @return bool TRUE if an update is available, FALSE otherwise
	 */
	public function isAvailableUpdate()
	{
		if ($this->getLastAppliedUpdate() < $this->getNextUpdate()) {
			return true;
		}

		return false;
	}

	/**
	 * Apply all available database updates.
	 *
	 * @return bool TRUE on success, FALSE othewise
	 */
	public function applyUpdates()
	{
		/** @var $dbConfig iMSCP_Config_Handler_Db */
		$dbConfig = iMSCP_Registry::get('dbConfig');

		/** @var $pdo PDO */
		$pdo = iMSCP_Database::getRawInstance();

		while ($this->isAvailableUpdate()) {
			$databaseUpdateRevision = $this->getNextUpdate();

			// Get the database update method name
			$databaseUpdateMethod = '_databaseUpdate_' . $databaseUpdateRevision;

			// Gets the querie(s) from the database update method
			// A database update method can return void, an array (stack of SQL statements)
			// or a string (SQL statement)
			$queryStack = $this->$databaseUpdateMethod();

			if (!empty($queryStack)) {
				try {
					$pdo->beginTransaction();

					foreach ((array)$queryStack as $query) {
						$pdo->query($query);
					}

					$dbConfig->set('DATABASE_REVISION', $databaseUpdateRevision);

					$pdo->commit();
				} catch (Exception $e) {
					$pdo->rollBack();

					// Prepare error message
					$errorMessage = sprintf(
						'Database update %s failed.', $databaseUpdateRevision);

					// Extended error message
					$errorMessage .=
						'<br /><br /><strong>Exception message was:</strong><br />' .
						$e->getMessage() . (isset($query)
							? "<br /><strong>Query was:</strong><br />$query" : '');

					if (PHP_SAPI == 'cli') {
						$errorMessage = str_replace(
							array('<br />', '<strong>', '</strong>'),
							array("\n", '', ''), $errorMessage);
					}

					$this->_lastError = $errorMessage;

					return false;
				}
			} else {
				$dbConfig->set('DATABASE_REVISION', $databaseUpdateRevision);
			}
		}

		// We must never run the backend scripts from the CLI update script
		if (PHP_SAPI != 'cli' && $this->_daemonRequest) {
			send_request();
		}

		return true;
	}

	/**
	 * Returns database update(s) details.
	 * 
	 * @return array
	 */
	public function getDatabaseUpdateDetail()
	{
		$reflectionStart = $this->getNextUpdate();

		$reflection = new ReflectionClass(__CLASS__);
		$databaseUpdateDetail = array();

		/** @var $method ReflectionMethod */
		foreach ($reflection->getMethods() as $method) {
			$methodName = $method->name;

			if (strpos($methodName, '_databaseUpdate_') !== false) {
				$revision = (int)substr($methodName, strrpos($methodName, '_') + 1);

				if($revision >= $reflectionStart) {
					$detail = explode("\n", $method->getDocComment());
					$databaseUpdateDetail[$revision] = str_replace("\t * ", '', $detail[1]);
				}
			}
		}

		return $databaseUpdateDetail;
	}

	/**
	 * Return next database update revision.
	 *
	 * @return int 0 if no update available
	 */
	protected function getNextUpdate()
	{
		$lastAvailableUpdateRevision = $this->getLastAvailableUpdateRevision();
		$nextUpdateRevision = $this->getLastAppliedUpdate();

		if ($nextUpdateRevision < $lastAvailableUpdateRevision) {
			return $nextUpdateRevision + 1;
		}

		return 0;
	}

	/**
	 * Returns the revision of the last available datababse update.
	 *
	 * Note: For performances reasons, the revision is retrieved once.
	 *
	 * @return int The revision of the last available database update
	 */
	protected function getLastAvailableUpdateRevision()
	{
		static $lastAvailableUpdateRevision = null;

		if (null === $lastAvailableUpdateRevision) {
			$reflection = new ReflectionClass(__CLASS__);
			$databaseUpdateMethods = array();

			foreach ($reflection->getMethods() as $method)
			{
				if (strpos($method->name, '_databaseUpdate_') !== false) {
					$databaseUpdateMethods[] = $method->name;
				}
			}

			$databaseUpdateMethod = (string)end($databaseUpdateMethods);
			$lastAvailableUpdateRevision = (int)substr(
				$databaseUpdateMethod, strrpos($databaseUpdateMethod, '_') + 1);
		}

		return $lastAvailableUpdateRevision;
	}

	/**
	 * Returns revision of the last applied database update.
	 *
	 * @return int Revision of the last applied database update
	 */
	protected function getLastAppliedUpdate()
	{
		/** @var $dbConfig iMSCP_Config_Handler_Db */
		$dbConfig = iMSCP_Registry::get('dbConfig');

		if (!isset($dbConfig->DATABASE_REVISION)) {
			$dbConfig->DATABASE_REVISION = 1;
		}

		return (int)$dbConfig->DATABASE_REVISION;
	}

	/**
	 * Checks if a column exists in a database table and if not, execute a query to
	 * add that column.
	 *
	 * @author Daniel Andreca <sci2tech@gmail.com>
	 * @since r4509
	 * @param string $table Database table name
	 * @param string $column Column to be added in the database table
	 * @param string $query Query to create column
	 * @return string Query to be executed
	 */
	protected function secureAddColumnTable($table, $column, $query)
	{
		$dbName = iMSCP_Registry::get('config')->DATABASE_NAME;

		return "
			DROP PROCEDURE IF EXISTS test;
			CREATE PROCEDURE test()
			BEGIN
				if not exists(
					SELECT
						*
					FROM
						information_schema.COLUMNS
					WHERE
						column_name='$column'
					AND
						table_name='$table'
					AND
						table_schema='$dbName'
				) THEN
					$query;
				END IF;
			END;
			CALL test();
			DROP PROCEDURE IF EXISTS test;
		";
	}

	/**
	 * Catch any database update that were removed.
	 *
	 * @param  string $updateMethod Database method name
	 * @param  array $param $parameter
	 * @return void
	 */
	public function __call($updateMethod, $param)
	{
	}

	/**
	 * Fixes some CSRF issues in admin log.
	 *
	 * @author Thomas Wacker <thomas.wacker@ispcp.net>
	 * @since r3695
	 * @return array SQL Statement
	 */
	protected function _databaseUpdate_46()
	{
		return 'TRUNCATE TABLE `log`;';
	}

	/**
	 * Removes useless 'suexec_props' table.
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r3709
	 * @return array SQL Statement
	 */
	protected function _databaseUpdate_47()
	{
		return 'DROP TABLE IF EXISTS `suexec_props`';
	}

	/**
	 * #14: Adds table for software installer.
	 *
	 * @author Sascha Bay <worst.case@gmx.de>
	 * @since  r3695
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_48()
	{
		$sqlUpd = array();
		$sqlUpd[] = "
	 		CREATE TABLE IF NOT EXISTS
	 			`web_software` (
					`software_id` int(10) unsigned NOT NULL auto_increment,
					`software_master_id` int(10) unsigned NOT NULL default '0',
					`reseller_id` int(10) unsigned NOT NULL default '0',
					`software_name` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_version` varchar(20) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_language` varchar(15) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_type` varchar(20) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_db` tinyint(1) NOT NULL,
					`software_archive` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_installfile` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_prefix` varchar(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_link` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_desc` mediumtext CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_active` int(1) NOT NULL,
					`software_status` varchar(15) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`rights_add_by` int(10) unsigned NOT NULL default '0',
					`software_depot` varchar(15) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL NOT NULL DEFAULT 'no',
	  				PRIMARY KEY  (`software_id`)
				) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
		";

		$sqlUpd[] = "
			CREATE TABLE IF NOT EXISTS
				`web_software_inst` (
					`domain_id` int(10) unsigned NOT NULL,
					`alias_id` int(10) unsigned NOT NULL default '0',
					`subdomain_id` int(10) unsigned NOT NULL default '0',
					`subdomain_alias_id` int(10) unsigned NOT NULL default '0',
					`software_id` int(10) NOT NULL,
					`software_master_id` int(10) unsigned NOT NULL default '0',
					`software_res_del` int(1) NOT NULL default '0',
					`software_name` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_version` varchar(20) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_language` varchar(15) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`path` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL default '0',
					`software_prefix` varchar(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL default '0',
					`db` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL default '0',
					`database_user` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL default '0',
					`database_tmp_pwd` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL default '0',
					`install_username` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL default '0',
					`install_password` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL default '0',
					`install_email` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL default '0',
					`software_status` varchar(15) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					`software_depot` varchar(15) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL NOT NULL DEFAULT 'no',
  					KEY `software_id` (`software_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
			;
		";

		$sqlUpd[] = self::secureAddColumnTable(
			'domain', 'domain_software_allowed',
			"
				ALTER TABLE
					`domain`
				ADD
					`domain_software_allowed` VARCHAR( 15 ) COLLATE utf8_unicode_ci NOT NULL default 'no'
			"
		);

		$sqlUpd[] = self::secureAddColumnTable(
			'reseller_props', 'software_allowed',
			"
				ALTER TABLE
					`reseller_props`
				ADD
					`software_allowed` VARCHAR( 15 ) COLLATE utf8_unicode_ci NOT NULL default 'no'
			"
		);

		$sqlUpd[] = self::secureAddColumnTable(
			'reseller_props', 'softwaredepot_allowed',
			"
				ALTER TABLE
					`reseller_props`
				ADD
					`softwaredepot_allowed` VARCHAR( 15 ) COLLATE utf8_unicode_ci NOT NULL default 'yes'
			"
		);

		$sqlUpd[] = "UPDATE `hosting_plans` SET `props` = CONCAT(`props`,';_no_');";

		return $sqlUpd;
	}

	/**
	 * Adds i-MSCP daemon service properties in config table.
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r4004
	 * @return void
	 */
	protected function _databaseUpdate_50()
	{
		/** @var $dbConfig iMSCP_Config_Handler_Db */
		$dbConfig = iMSCP_Registry::get('dbConfig');
		$dbConfig->PORT_IMSCP_DAEMON = "9876;tcp;i-MSCP-Daemon;1;0;127.0.0.1";
	}

	/**
	 * Adds required field for on-click-logon from the ftp-user site.
	 *
	 * @author William Lightning <kassah@gmail.com>
	 * @return string SQL Statement
	 */
	protected function _databaseUpdate_51()
	{
		$query = "
			ALTER IGNORE TABLE
				`ftp_users`
			ADD
				`rawpasswd` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL
			AFTER
				`passwd`
		";

		return self::secureAddColumnTable('ftp_users', 'rawpasswd', $query);
	}

	/**
	 * Adds new options for applications installer.
	 *
	 * @author Sascha Bay <worst.case@gmx.de>
	 * @since  r4036
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_52()
	{
		$sqlUpd = array();

		$sqlUpd[] = "
			CREATE TABLE IF NOT EXISTS
				`web_software_depot` (
					`package_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
					`package_install_type` varchar(15) COLLATE utf8_unicode_ci NOT NULL,
					`package_title` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
					`package_version` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
					`package_language` varchar(15) COLLATE utf8_unicode_ci NOT NULL,
					`package_type` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
					`package_description` mediumtext character set utf8 collate utf8_unicode_ci NOT NULL,
					`package_vendor_hp` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
					`package_download_link` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
					`package_signature_link` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
					PRIMARY KEY (`package_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
			;
		";

		$sqlUpd[] = "
			CREATE TABLE IF NOT EXISTS
				`web_software_options` (
					`use_webdepot` tinyint(1) unsigned NOT NULL DEFAULT '1',
					`webdepot_xml_url` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
					`webdepot_last_update` datetime NOT NULL
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
			;
		";

		$sqlUpd[] = "
			REPLACE INTO
				`web_software_options` (`use_webdepot`, `webdepot_xml_url`, `webdepot_last_update`)
			VALUES
				('1', 'http://app-pkg.i-mscp.net/imscp_webdepot_list.xml', '0000-00-00 00:00:00')
			;
		";

		$sqlUpd[] = self::secureAddColumnTable(
			'web_software',
			'software_installtype',
			"
				ALTER IGNORE TABLE
					`web_software`
				ADD
					`software_installtype` varchar(15) COLLATE utf8_unicode_ci DEFAULT NULL
				AFTER
					`reseller_id`
			"
		);

		$sqlUpd[] = " UPDATE `web_software` SET `software_installtype` = 'install'";

		$sqlUpd[] = self::secureAddColumnTable(
			'reseller_props',
			'websoftwaredepot_allowed',
			"
				ALTER IGNORE TABLE
					`reseller_props`
				ADD
					`websoftwaredepot_allowed` varchar(15) COLLATE utf8_unicode_ci DEFAULT NULL DEFAULT 'yes'
			"
		);

		return $sqlUpd;
	}

	/**
	 * Decrypts email, ftp and SQL users passwords in database.
	 *
	 * @author Daniel Andreca <sci2tech@gmail.com>
	 * @since r4509
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_53()
	{
		$sqlUpd = array();

		$status = iMSCP_Registry::get('config')->ITEM_CHANGE_STATUS;

		/** @var $db iMSCP_Database */
		$db = iMSCP_Registry::get('db');

		// Mail accounts passwords

		$query = "
			SELECT
				`mail_id`, `mail_pass`
			FROM
				`mail_users`
			WHERE
				`mail_type` RLIKE '^normal_mail'
			OR
				`mail_type` RLIKE '^alias_mail'
			OR
				`mail_type` RLIKE '^subdom_mail'
		";

		$stmt = execute_query($query);

		if ($stmt->rowCount() != 0) {
			while (!$stmt->EOF) {
				$sqlUpd[] = "
					UPDATE
						`mail_users`
					SET
						`mail_pass`= " . $db->quote(decrypt_db_password($stmt->fields['mail_pass'])) . ",
						`status` = '$status' WHERE `mail_id` = '" . $stmt->fields['mail_id'] . "'
				";

				$stmt->moveNext();
			}
		}

		// SQL users passwords

		$stmt = exec_query("SELECT `sqlu_id`, `sqlu_pass` FROM `sql_user`");

		if ($stmt->rowCount() != 0) {
			while (!$stmt->EOF) {
				$sqlUpd[] = "
					UPDATE
						`sql_user`
					SET
						`sqlu_pass` = " . $db->quote(decrypt_db_password($stmt->fields['sqlu_pass'])) . "
					WHERE
						`sqlu_id` = '" . $stmt->fields['sqlu_id'] . "'
				";

				$stmt->moveNext();
			}
		}

		// Ftp users passwords

		$stmt = exec_query("SELECT `userid`, `rawpasswd` FROM `ftp_users`");

		if ($stmt->rowCount() != 0) {
			while (!$stmt->EOF) {
				$sqlUpd[] = "
					UPDATE
						`ftp_users`
					SET
						`rawpasswd` = " . $db->quote(decrypt_db_password($stmt->fields['rawpasswd'])) . "
					WHERE
						`userid` = '" . $stmt->fields['userid'] . "'
				";

				$stmt->moveNext();
			}
		}

		return $sqlUpd;
	}

	/**
	 * Converts all tables to InnoDB engine.
	 *
	 * @author Daniel Andreca <sci2tech@gmail.com>
	 * @since r4509
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_54()
	{
		$sqlUpd = array();

		/** @var $db iMSCP_Database */
		$db = iMSCP_Registry::get('db');

		$tables = $db->metaTables();

		foreach ($tables as $table) {
			$sqlUpd[] = "ALTER TABLE `$table` ENGINE=InnoDB";
		}

		return $sqlUpd;
	}

	/**
	 * Adds unique index on user_gui_props.user_id column.
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r4592
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_56()
	{
		$sqlUpd = array();

		$sqlUpd[] = "
			DROP PROCEDURE IF EXISTS schema_change;
				CREATE PROCEDURE schema_change()
				BEGIN
					IF EXISTS (
						SELECT
							CONSTRAINT_NAME
						FROM
							`information_schema`.`KEY_COLUMN_USAGE`
						WHERE
							TABLE_NAME = 'user_gui_props'
						AND
							CONSTRAINT_NAME = 'user_id'
					) THEN
						ALTER IGNORE TABLE `user_gui_props` DROP INDEX `user_id`;
					END IF;
				END;
				CALL schema_change();
			DROP PROCEDURE IF EXITST schema_change;
		";

		$sqlUpd[] = 'ALTER TABLE `user_gui_props` ADD UNIQUE (`user_id`)';

		return $sqlUpd;
	}

	/**
	 * Drops useless column in user_gui_props table.
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r4644
	 * @return string SQL Statement
	 */
	protected function _databaseUpdate_59()
	{
		return "
			DROP PROCEDURE IF EXISTS schema_change;
				CREATE PROCEDURE schema_change()
				BEGIN
					IF EXISTS (
						SELECT
							COLUMN_NAME
						FROM
							information_schema.COLUMNS
						WHERE
							TABLE_NAME = 'user_gui_props'
						AND
							COLUMN_NAME = 'id'
					) THEN
						ALTER TABLE `user_gui_props` DROP column `id`;
					END IF;
				END;
				CALL schema_change();
			DROP PROCEDURE IF EXITST schema_change;
		";
	}

	/**
	 * Converts the autoreplies_log table to InnoDB engine.
	 *
	 * @author Daniel Andreca <sci2tech@gmail.com>
	 * @since r4650
	 * @return string SQL Statement
	 */
	protected function _databaseUpdate_60()
	{
		return 'ALTER TABLE `autoreplies_log` ENGINE=InnoDB';
	}

	/**
	 * Deletes old DUMP_GUI_DEBUG parameter from the config table.
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r4779
	 * @return void
	 */
	protected function _databaseUpdate_66()
	{
		/** @var $dbConfig iMSCP_Config_Handler_Db */
		$dbConfig = iMSCP_Registry::get('dbConfig');

		if (isset($dbConfig->DUMP_GUI_DEBUG)) {
			$dbConfig->del('DUMP_GUI_DEBUG');
		}
	}


	/**
	 * #124: Enhancement - Switch to gettext (Machine Object Files)
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r4792
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_67()
	{
		$sqlUpd = array();

		// First step: Update default language (new naming convention)

		$dbConfig = iMSCP_Registry::get('dbConfig');
		if (isset($dbConfig->USER_INITIAL_LANG)) {
			$dbConfig->USER_INITIAL_LANG = str_replace(
				'lang_', '', $dbConfig->USER_INITIAL_LANG);
		}

		// second step: Removing all database languages tables

		/** @var $db iMSCP_Database */
		$db = iMSCP_Registry::get('db');

		foreach ($db->metaTables() as $tableName) {
			if (strpos($tableName, 'lang_') !== false) {
				$sqlUpd[] = "DROP TABLE `$tableName`";
			}
		}

		// third step: Update users language property

		$languagesMap = array(
			'Arabic' => 'ar_AE', 'Azerbaijani' => 'az_AZ', 'BasqueSpain' => 'eu_ES',
			'Bulgarian' => 'bg_BG', 'Catalan' => 'ca_ES', 'ChineseChina' => 'zh_CN',
			'ChineseHongKong' => 'zh_HK', 'ChineseTaiwan' => 'zh_TW', 'Czech' => 'cs_CZ',
			'Danish' => 'da_DK', 'Dutch' => 'nl_NL', 'EnglishBritain' => 'en_GB',
			'FarsiIran' => 'fa_IR', 'Finnish' => 'fi_FI', 'FrenchFrance' => 'fr_FR',
			'Galego' => 'gl_ES', 'GermanGermany' => 'de_DE', 'GreekGreece' => 'el_GR',
			'Hungarian' => 'hu_HU', 'ItalianItaly' => 'it_IT', 'Japanese' => 'ja_JP',
			'Lithuanian' => 'lt_LT', 'NorwegianNorway' => 'nb_NO', 'Polish' => 'pl_PL',
			'PortugueseBrazil' => 'pt_BR', 'Portuguese' => 'pt_PT', 'Romanian' => 'ro_RO',
			'Russian' => 'ru_RU', 'Slovak' => 'sk_SK', 'SpanishArgentina' => 'es_AR',
			'SpanishSpain' => 'es_ES', 'Swedish' => 'sv_SE', 'Thai' => 'th_TH',
			'Turkish' => 'tr_TR', 'Ukrainian' => 'uk_UA');

		// Updates language property of each users by using new naming convention
		// Thanks to Marc Pujol for idea
		foreach ($languagesMap as $language => $locale) {
			$sqlUpd[] = "
				UPDATE
					`user_gui_props`
				SET
					`lang` = '$locale'
				WHERE
					`lang` = 'lang_{$language}'";
		}

		return $sqlUpd;
	}

	/**
	 * #119: Defect - Error when adding IP's
	 *
	 * @author Daniel Andreca <sci2tech@gmail.com>
	 * @since r4844
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_68()
	{
		$sqlUpd = array();

		/** @var $db iMSCP_Database */
		$db = iMSCP_Registry::get('db');

		$stmt = exec_query("SELECT `ip_id`, `ip_card` FROM `server_ips`");

		if ($stmt->rowCount() != 0) {
			while (!$stmt->EOF) {
				$cardname = explode(':', $stmt->fields['ip_card']);
				$cardname = $cardname[0];
				$sqlUpd[] = "
					UPDATE
						`server_ips`
					SET
						`ip_card` = " . $db->quote($cardname) . "
					WHERE
						`ip_id` = '" . $stmt->fields['ip_id'] . "'
				";

				$stmt->moveNext();
			}
		}

		return $sqlUpd;
	}

	/**
	 * Some fixes for the user_gui_props table.
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r4961
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_69()
	{
		return array(
			"ALTER TABLE `user_gui_props` CHANGE `user_id` `user_id` INT( 10 ) UNSIGNED NOT NULL",
			"ALTER TABLE `user_gui_props` CHANGE `layout` `layout`
				VARCHAR( 100 ) CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL DEFAULT NULL",
			"ALTER TABLE `user_gui_props` CHANGE `logo` `logo`
				VARCHAR( 255 ) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT ''",
			"ALTER TABLE `user_gui_props` CHANGE `lang` `lang`
				VARCHAR( 5 ) CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL DEFAULT NULL",
			"UPDATE `user_gui_props` SET `logo` = '' WHERE `logo` = 0");
	}

	/**
	 * Deletes possible orphan items in many tables.
	 *
	 * See #145 on i-MSCP issue tracker for more information.
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r4961
	 * @return array Stack of SQL statements to be executed
	 */
	protected function _databaseUpdate_70()
	{
		$sqlUpd = array();

		$tablesToForeignKey = array(
			'email_tpls' => 'owner_id', 'hosting_plans' => 'reseller_id',
			'orders' => 'user_id', 'orders_settings' => 'user_id',
			'reseller_props' => 'reseller_id', 'tickets' => 'ticket_to',
			'tickets' => 'ticket_from', 'user_gui_props' => 'user_id',
			'web_software' => 'reseller_id');

		$stmt = execute_query('SELECT `admin_id` FROM `admin`');
		$usersIds = implode(',', $stmt->fetchall(PDO::FETCH_COLUMN));

		foreach ($tablesToForeignKey as $table => $foreignKey) {
			$sqlUpd[] = "DELETE FROM `$table` WHERE `$foreignKey` NOT IN ($usersIds)";
		}

		return $sqlUpd;
	}

	/**
	 * Changes the log table schema to allow storage of large messages.
	 *
	 * @author Laurent Declercq <l.declercq@nuxwin.com>
	 * @since r5002
	 * @return string SQL statement to be executed
	 */
	protected function _databaseUpdate_71()
	{
		return 'ALTER TABLE `log` CHANGE `log_message` `log_message`
			TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL DEFAULT NULL';
	}

	/**
	 * Adds unique index on the web_software_options.use_webdepot column.
	 *
	 * @author Daniel Andreca <sci2tech@gmail.com>
	 * @return string SQL statement to be executed
	 */
	protected function _databaseUpdate_72()
	{
		return 'ALTER IGNORE TABLE `web_software_options` ADD UNIQUE (`use_webdepot`)';
	}

	/**
	 * #166: Adds dovecot quota table.
	 *
	 * @author Daniel Andreca <sci2tech@gmail.com>
	 * @return string SQL statement to be executed
	 */
	protected function _databaseUpdate_73()
	{
		return "
			CREATE TABLE IF NOT EXISTS `quota_dovecot` (
			`username` varchar(200) COLLATE utf8_unicode_ci NOT NULL,
			`bytes` bigint(20) NOT NULL DEFAULT '0',
			`messages` int(11) NOT NULL DEFAULT '0',
			PRIMARY KEY (`username`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		";
	}

	/**
	 * #58: Increases mail quota value from 10 Mio to 100 Mio.
	 *
	 * @author Daniel Andreca <sci2tech@gmail.com>
	 * @return string SQL statement to be executed
	 */
	protected function _databaseUpdate_75()
	{
		return "
			UPDATE `mail_users` SET `quota` = '104857600' WHERE `quota` = '10485760';
		";
	}
}
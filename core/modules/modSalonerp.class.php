<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		Solauv
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Oeuvre derivee de htdocs/modulebuilder/template/core/modules/modMyModule.class.php
 * de Dolibarr ERP/CRM (version 24.0.1).
 */

/**
 * 	\defgroup   salonerp     Module Salonerp
 *  \brief      Salonerp module descriptor.
 *
 *  \file       salonerp/core/modules/modSalonerp.class.php
 *  \ingroup    salonerp
 *  \brief      Description and activation file for module Salonerp
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module Salonerp
 */
class modSalonerp extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		// Id for module (must be unique).
		// See https://wiki.dolibarr.org/index.php/List_of_modules_id
		$this->numero = 152249;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'salonerp';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		$this->family = "crm";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Module label (no space allowed), used if translation string 'ModuleSalonerpName' not found (Salonerp is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleSalonerpDesc' not found (Salonerp is name of module).
		$this->description = "SalonerpDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "SalonerpDescription";

		// Author
		$this->editor_name = 'Solauv';
		$this->editor_url = '';

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0.0';

		// Key used in llx_const table to save module status enabled/disabled (where SALONERP is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'fa-vote-yea';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0
		);

		// No data directory needed in this lot: no document generation, no
		// mass-action file output.
		$this->dirs = array();

		// Config pages. No setup constant in this lot: the "About" page is enough.
		$this->config_page_url = array("about.php@salonerp");

		// Dependencies
		$this->hidden = getDolGlobalInt('MODULE_SALONERP_DISABLED'); // A condition to disable module;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("salonerp@salonerp");

		// Prerequisites
		$this->phpmin = array(8, 1); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(21, 0); // Minimum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// No module constant in this lot.
		$this->const = array();

		if (!isModEnabled("salonerp")) {
			global $conf;
			$conf->salonerp = new stdClass();
			$conf->salonerp->enabled = 0;
		}

		// No extra tab in this lot.
		$this->tabs = array();

		// No dictionary in this lot.
		$this->dictionaries = array();

		// No widget in this lot.
		$this->boxes = array();

		// No cron job in this lot.
		$this->cronjobs = array();

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero.sprintf("%02d", ($r + 1)); // Permission id
		$this->rights[$r][1] = 'PermissionReadCampaign'; // Permission label, a translation key
		$this->rights[$r][4] = 'campaign';
		$this->rights[$r][5] = 'read'; // $user->hasRight('salonerp', 'campaign', 'read')
		$r++;

		$this->rights[$r][0] = $this->numero.sprintf("%02d", ($r + 1));
		$this->rights[$r][1] = 'PermissionWriteCampaign';
		$this->rights[$r][4] = 'campaign';
		$this->rights[$r][5] = 'write'; // $user->hasRight('salonerp', 'campaign', 'write')
		$r++;

		$this->rights[$r][0] = $this->numero.sprintf("%02d", ($r + 1));
		$this->rights[$r][1] = 'PermissionDeleteCampaign';
		$this->rights[$r][4] = 'campaign';
		$this->rights[$r][5] = 'delete'; // $user->hasRight('salonerp', 'campaign', 'delete')
		$r++;

		// Main menu entries to add
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => '', // '' = top menu
			'type' => 'top',
			'titre' => 'ModuleSalonerpName',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'salonerp',
			'leftmenu' => '',
			'url' => '/salonerp/salonerpindex.php',
			'langs' => 'salonerp@salonerp',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('salonerp')",
			'perms' => '$user->hasRight("salonerp", "campaign", "read")',
			'target' => '',
			'user' => 0, // 0=internal only: votes are an internal process
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=salonerp',
			'type' => 'left',
			'titre' => 'Campaign',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'salonerp',
			'leftmenu' => 'campaign',
			'url' => '/salonerp/campaign_list.php',
			'langs' => 'salonerp@salonerp',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('salonerp')",
			'perms' => '$user->hasRight("salonerp", "campaign", "read")',
			'target' => '',
			'user' => 0,
			'object' => 'Campaign'
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=salonerp,fk_leftmenu=campaign',
			'type' => 'left',
			'titre' => 'NewCampaign',
			'mainmenu' => 'salonerp',
			'leftmenu' => 'salonerp_campaign_new',
			'url' => '/salonerp/campaign_card.php?action=create',
			'langs' => 'salonerp@salonerp',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('salonerp')",
			'perms' => '$user->hasRight("salonerp", "campaign", "write")',
			'target' => '',
			'user' => 0,
			'object' => 'Campaign'
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=salonerp,fk_leftmenu=campaign',
			'type' => 'left',
			'titre' => 'ListCampaign',
			'mainmenu' => 'salonerp',
			'leftmenu' => 'salonerp_campaign_list',
			'url' => '/salonerp/campaign_list.php',
			'langs' => 'salonerp@salonerp',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('salonerp')",
			'perms' => '$user->hasRight("salonerp", "campaign", "read")',
			'target' => '',
			'user' => 0,
			'object' => 'Campaign'
		);

		// Decrypt a vote file outside the campaign it comes from: file + the
		// revealed private key, no database lookup (works on any instance).
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=salonerp,fk_leftmenu=campaign',
			'type' => 'left',
			'titre' => 'ExternalDecryption',
			'mainmenu' => 'salonerp',
			'leftmenu' => 'salonerp_external_decryption',
			'url' => '/salonerp/decrypt_external.php',
			'langs' => 'salonerp@salonerp',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('salonerp')",
			'perms' => '$user->hasRight("salonerp", "campaign", "read")',
			'target' => '',
			'user' => 0,
		);

		// No export/import profile.
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $langs;

		// The whole module is built around sodium's sealed-box keypair
		// (Campaign::validate()): refuse activation with a clear message
		// rather than fail obscurely the first time a campaign is validated.
		if (!function_exists('sodium_crypto_box_keypair')) {
			$langs->load('salonerp@salonerp');
			$this->error = $langs->trans('ErrorSalonerpSodiumExtensionMissing');
			return -1;
		}

		// Create tables of module at module activation
		$result = $this->_load_tables('/salonerp/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries
		}

		// Permissions
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}

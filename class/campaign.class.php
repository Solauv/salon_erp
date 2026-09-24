<?php
/* Copyright (C) 2017       Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2025  Frédéric France          <frederic.france@free.fr>
 * Copyright (C) 2026		EBI
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
 * Oeuvre derivee de htdocs/modulebuilder/template/class/myobject.class.php
 * de Dolibarr ERP/CRM (version 24.0.1).
 */

/**
 * \file        class/campaign.class.php
 * \ingroup     salonerp
 * \brief       This file is a CRUD class file for Campaign (Create/Read/Update/Delete)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for Campaign
 *
 * A campaign is a hidden-auction vote: a group of users shares out an integer
 * point budget across the thirdparties tagged with a given customer category.
 * This lot only covers the draft/validated lifecycle and the generation of the
 * X25519 keypair used from lot 2 onward to encrypt individual votes.
 *
 * Locking rule, enforced everywhere in this class: the draft/validated status
 * is a security boundary, so it is NEVER trusted from the in-memory object.
 * Every method that must refuse once the campaign is no longer a draft rereads
 * the status straight from the database first.
 */
class Campaign extends CommonObject
{
	/**
	 * @var string 		ID of module.
	 */
	public $module = 'salonerp';

	/**
	 * @var string 		ID to identify managed object.
	 */
	public $element = 'campaign';

	/**
	 * @var string		Prefix to check for any trigger code of any business class to prevent bad value for trigger code.
	 * @see CommonTrigger::call_trigger()
	 */
	public $TRIGGER_PREFIX = 'SALONERP_CAMPAIGN';

	/**
	 * @var string 		Name of table without prefix where object is stored.
	 */
	public $table_element = 'salonerp_campaign';

	/**
	 * @var string 		String with name of icon for campaign. Must be a 'fa-xxx' fontawesome code.
	 */
	public $picto = 'fa-vote-yea';

	/**
	 * @var int<0,1>	Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var int<0,1>|string		Does this object support multicompany module ?
	 */
	public $ismultientitymanaged = 1;

	/**
	 * Draft: everything is editable (voters, points, dates, category, group...).
	 */
	const STATUS_DRAFT = 0;
	/**
	 * Validated: the keypair and the genesis hash are set, nothing else is editable.
	 * Statuses 2 (VOTE_OPEN), 3 (ENDED), 4 (REVEALED), 8 (EXTINCT) belong to lot 2/3
	 * and are only reserved here so the constant values never collide.
	 */
	const STATUS_VALIDATED = 1;
	const STATUS_VOTE_OPEN = 2;
	const STATUS_ENDED = 3;
	const STATUS_REVEALED = 4;
	const STATUS_EXTINCT = 8;
	const STATUS_CANCELED = 9;

	/**
	 * Fixed at validation time, part of the genesis hash. Only one rule exists in
	 * this lot: highest share of own envelope first, then a verifiable draw.
	 */
	const TIE_RULE_DEFAULT = 'B_E';

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @inheritdoc
	 * Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "TechnicalID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "noteditable" => 1, "index" => 1, "css" => "left", "comment" => "Id"),
		"entity" => array("type" => "integer", "label" => "Entity", "enabled" => "1", 'position' => 5, 'notnull' => 1, "visible" => "0", "default" => "1", "index" => 1),
		"ref" => array("type" => "varchar(128)", "label" => "Ref", "enabled" => "1", 'position' => 10, 'notnull' => 1, "visible" => "1", "index" => 1, "searchall" => 1, "showoncombobox" => "1", "comment" => "Reference of object"),
		"label" => array("type" => "varchar(255)", "label" => "Label", "enabled" => "1", 'position' => 20, 'notnull' => 1, "visible" => "1", "alwayseditable" => "0", "searchall" => 1, "css" => "minwidth300", "cssview" => "wordbreak", "showoncombobox" => "2"),
		"date_start" => array("type" => "datetime", "label" => "DateStart", "enabled" => "1", 'position' => 30, 'notnull' => 1, "visible" => "1", "help" => "CampaignDateStartHelp"),
		"date_end" => array("type" => "datetime", "label" => "DateEnd", "enabled" => "1", 'position' => 31, 'notnull' => 1, "visible" => "1", "help" => "CampaignDateEndHelp"),
		"fk_category" => array("type" => "integer:Categorie:categories/class/categorie.class.php:0", "label" => "CustomerCategory", "picto" => "category", "enabled" => "1", 'position' => 40, 'notnull' => 1, "visible" => "1", "index" => 1, "help" => "CampaignCategoryHelp"),
		"fk_usergroup" => array("type" => "integer:UserGroup:user/class/usergroup.class.php:0", "label" => "VotersGroup", "picto" => "group", "enabled" => "1", 'position' => 41, 'notnull' => 1, "visible" => "1", "index" => 1, "help" => "CampaignGroupHelp"),
		"default_points" => array("type" => "integer", "label" => "DefaultPoints", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-3", "help" => "CampaignDefaultPointsHelp"),
		"description" => array("type" => "text", "label" => "Description", "enabled" => "1", 'position' => 60, 'notnull' => 0, "visible" => "-3"),
		"note_public" => array("type" => "html", "label" => "NotePublic", "enabled" => "1", 'position' => 61, 'notnull' => 0, "visible" => "0", "cssview" => "wordbreak"),
		"note_private" => array("type" => "html", "label" => "NotePrivate", "enabled" => "1", 'position' => 62, 'notnull' => 0, "visible" => "0", "cssview" => "wordbreak"),
		"public_key" => array("type" => "varchar(64)", "label" => "PublicKey", "enabled" => "1", 'position' => 70, 'notnull' => 0, "visible" => "-3", "noteditable" => 1),
		"tie_rule" => array("type" => "varchar(16)", "label" => "TieRule", "enabled" => "1", 'position' => 71, 'notnull' => 1, "visible" => "-3", "noteditable" => 1, "default" => self::TIE_RULE_DEFAULT),
		"genesis_hash" => array("type" => "varchar(64)", "label" => "GenesisHash", "enabled" => "1", 'position' => 72, 'notnull' => 0, "visible" => "-3", "noteditable" => 1, "cssview" => "wordbreak"),
		"genesis_payload" => array("type" => "text", "label" => "GenesisPayload", "enabled" => "1", 'position' => 73, 'notnull' => 0, "visible" => "0", "noteditable" => 1, "cssview" => "wordbreak"),
		"date_validation" => array("type" => "datetime", "label" => "DateValidation", "enabled" => "1", 'position' => 80, 'notnull' => 0, "visible" => "-3", "noteditable" => 1),
		"fk_user_valid" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserValidation", "picto" => "user", "enabled" => "1", 'position' => 81, 'notnull' => 0, "visible" => "-3", "noteditable" => 1),
		"status" => array("type" => "integer", "label" => "Status", "enabled" => "1", 'position' => 90, 'notnull' => 1, "visible" => "1", "index" => 1, "arrayofkeyval" => array("0" => "Draft", "1" => "Validated"), "default" => "0"),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "-2"),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2"),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "Manager", "picto" => "user", "enabled" => "1", 'position' => 510, 'notnull' => 1, "visible" => "-2", "csslist" => "tdoverflowmax150", "help" => "CampaignManagerHelp"),
		"fk_user_modif" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserModif", "picto" => "user", "enabled" => "1", 'position' => 511, 'notnull' => -1, "visible" => "-2", "csslist" => "tdoverflowmax150"),
		"import_key" => array("type" => "varchar(14)", "label" => "ImportId", "enabled" => "1", 'position' => 1000, 'notnull' => -1, "visible" => "-2"),
	);
	/** @var int */
	public $rowid;
	/** @var int Entity id, fixed at creation time and never left empty. */
	public $entity;
	public $ref;
	/** @var string */
	public $label;
	/** @var int Unix timestamp */
	public $date_start;
	/** @var int Unix timestamp */
	public $date_end;
	/** @var int Id of the customer category (tag) */
	public $fk_category;
	/** @var int Id of the voters group */
	public $fk_usergroup;
	/** @var int */
	public $default_points;
	/** @var string */
	public $description;
	public $note_public;
	public $note_private;
	/** @var ?string Base64 of the public X25519 key, set at validation */
	public $public_key;
	/** @var string */
	public $tie_rule;
	/** @var ?string SHA-256 hex of genesis_payload, set at validation */
	public $genesis_hash;
	/** @var ?string Exact canonical JSON hashed into genesis_hash: the source of truth for later verification. */
	public $genesis_payload;
	public $date_validation;
	/** @var ?int */
	public $fk_user_valid;
	/** @var int */
	public $status;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $import_key;
	// END MODULEBUILDER PROPERTIES

	/**
	 * Constructor
	 *
	 * @param	DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $langs;

		$this->db = $db;

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		// Note: entity stays tracked even without multicompany: it is part of
		// the genesis payload and must never be silently dropped from ->fields
		// (a disabled field is never read back by fetchCommon()).

		// Unset fields that are disabled
		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		// Translate some data of arrayofkeyval
		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}
	}

	/**
	 * Create object into database
	 *
	 * @param	User		$user		User that creates
	 * @param	int<0,1> 	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,max>				Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $conf;

		if (empty($this->tie_rule)) {
			$this->tie_rule = self::TIE_RULE_DEFAULT;
		}
		if (empty($this->status)) {
			$this->status = self::STATUS_DRAFT;
		}
		// Entity is fixed once, at creation, and read from $conf: never left to
		// chance so the genesis payload can always report a real entity id.
		if (empty($this->entity)) {
			$this->entity = (int) $conf->entity;
		}

		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param	int    		$id   			Id object
	 * @param	string 		$ref  			Ref
	 * @param	int<0,1>	$noextrafields	0=Default to load extrafields, 1=No extrafields
	 * @param	int<0,1>	$nolines		Unused, kept for CommonObject::fetch() signature compatibility
	 * @return	int<-1,1>					Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null, $noextrafields = 0, $nolines = 0)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	/**
	 * Load list of objects in memory from the database.
	 *
	 * @param	string		$sortorder	Sort Order
	 * @param	string		$sortfield	Sort field
	 * @param	int<0,max>	$limit		Limit the number of lines returned
	 * @param	int<0,max>	$offset		Offset
	 * @param	string		$filter		Filter as an Universal Search string.
	 * @param	string		$filtermode	No longer used
	 * @return	array<int,self>|int<-1,-1>	 <0 if KO, array of pages if OK
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 1000, $offset = 0, string $filter = '', $filtermode = 'AND')
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$records = array();

		$sql = "SELECT ";
		$sql .= $this->getFieldList('t');
		$sql .= " FROM ".$this->db->prefix().$this->table_element." as t";
		$sql .= " WHERE t.entity IN (".getEntity($this->element).")";

		$errormessage = '';
		$sql .= forgeSQLFromUniversalSearchCriteria($filter, $errormessage);
		if ($errormessage) {
			$this->errors[] = $errormessage;
			dol_syslog(__METHOD__.' '.implode(',', $this->errors), LOG_ERR);
			return -1;
		}

		if (!empty($sortfield)) {
			$sql .= $this->db->order($sortfield, $sortorder);
		}
		if (!empty($limit)) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < ($limit ? min($limit, $num) : $num)) {
				$obj = $this->db->fetch_object($resql);

				$record = new self($this->db);
				$record->setVarsFromFetchObj($obj);

				$records[$record->id] = $record;

				$i++;
			}
			$this->db->free($resql);

			return $records;
		} else {
			$this->errors[] = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.implode(',', $this->errors), LOG_ERR);

			return -1;
		}
	}

	/**
	 * Read the current status straight from the database: the in-memory
	 * ->status is never trusted for a lock/permission decision, since the
	 * caller may hold a stale (or deliberately tampered) copy of the object.
	 *
	 * @return	?int	Status in database, or null if the row no longer exists
	 */
	protected function fetchStatusFromDb()
	{
		if (empty($this->id)) {
			return null;
		}

		$sql = "SELECT status FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? (int) $obj->status : null;
	}

	/**
	 * Update object into database. Refused once the campaign is no longer a draft:
	 * nothing (fields, voters, points, thirdparties) can change after validate().
	 * Changing the tag empties the thirdparty list. The status is
	 * reread from the database: an in-memory ->status = STATUS_DRAFT on a
	 * validated object must not grant anything.
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 0)
	{
		if ($this->fetchStatusFromDb() !== self::STATUS_DRAFT) {
			$this->error = 'ErrorCampaignNotDraftCannotBeModified';
			return -1;
		}

		$this->db->begin();

		// A thirdparty list synced from another tag no longer means anything:
		// empty it, the user syncs again from the new tag.
		$sql = "SELECT fk_category FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$resql = $this->db->query($sql);
		$previous = $resql ? $this->db->fetch_object($resql) : null;
		if (!$previous) {
			$this->error = 'Error '.$this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ((int) $previous->fk_category !== (int) $this->fk_category && $this->deleteThirdparties() < 0) {
			$this->db->rollback();
			return -1;
		}

		$result = $this->updateCommon($user, $notrigger);
		if ($result <= 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return $result;
	}

	/**
	 * Delete object in database. Allowed only in draft status (reread from the
	 * database): a validated campaign carries a genesis hash and a public key
	 * that must not be lost silently. All-or-nothing: voters, thirdparties and
	 * the campaign row are deleted in a single transaction.
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1> 	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 0)
	{
		if ($this->fetchStatusFromDb() !== self::STATUS_DRAFT) {
			$this->error = 'ErrorCampaignNotDraftCannotBeDeleted';
			return -1;
		}

		dol_include_once('/salonerp/class/campaignvoter.class.php');
		$voter = new CampaignVoter($this->db);

		$this->db->begin();

		$resultVoters = $voter->deleteByCampaign($this->id);
		if ($resultVoters < 0) {
			$this->error = $voter->error;
			$this->db->rollback();
			return -1;
		}
		if ($this->deleteThirdparties() < 0) {
			$this->db->rollback();
			return -1;
		}

		$result = $this->deleteCommon($user, $notrigger);
		if ($result <= 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Validate the vote window held by ->date_start and ->date_end: both set,
	 * end strictly after start, end strictly in the future. Reused at input
	 * time (campaign_card.php) and by validate().
	 *
	 * @return	string[]	Translated error messages, empty if valid
	 */
	public function checkDates()
	{
		global $langs;

		$errors = array();
		if (empty($this->date_start) || empty($this->date_end) || $this->date_end <= $this->date_start) {
			$errors[] = $langs->trans('ErrorCampaignDateEndBeforeStart');
		}
		if (empty($this->date_end) || $this->date_end <= dol_now()) {
			$errors[] = $langs->trans('ErrorCampaignDateEndInPast');
		}

		return $errors;
	}

	/**
	 * Validate that a customer category id is usable by this module: it must
	 * exist, belong to an entity shared with the current one, and be of type
	 * "customer". Reused at input time (campaign_card.php) and by validate().
	 *
	 * @param	int	$fkCategory	Id of the category to check
	 * @return	string			Translated error message, or '' if valid
	 */
	public function checkCategoryReference($fkCategory)
	{
		global $langs;

		if (empty($fkCategory)) {
			return $langs->trans('ErrorCampaignCategoryMandatory');
		}

		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$categorie = new Categorie($this->db);
		if ($categorie->fetch($fkCategory) <= 0) {
			return $langs->trans('ErrorCampaignCategoryNotFound');
		}
		// Categorie::fetch() sets ->type to the raw numeric DB code (see
		// Categorie::MAP_ID), never back to the 'customer' string constant:
		// comparing against the mapped id is the only way that works once the
		// object has been fetched.
		if ((int) $categorie->type !== (int) $categorie->MAP_ID[Categorie::TYPE_CUSTOMER]) {
			return $langs->trans('ErrorCampaignCategoryWrongType');
		}
		$allowedEntities = array_map('intval', explode(',', (string) getEntity('category')));
		if (!in_array((int) $categorie->entity, $allowedEntities, true)) {
			return $langs->trans('ErrorCampaignCategoryWrongEntity');
		}

		return '';
	}

	/**
	 * Validate that a voters group id is usable by this module: it must exist
	 * and belong to an entity shared with the current one. Reused at input
	 * time (campaign_card.php) and by validate()/syncVotersFromGroup().
	 *
	 * @param	int	$fkGroup	Id of the group to check
	 * @return	string			Translated error message, or '' if valid
	 */
	public function checkGroupReference($fkGroup)
	{
		global $langs;

		if (empty($fkGroup)) {
			return $langs->trans('ErrorCampaignGroupMandatory');
		}

		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
		$group = new UserGroup($this->db);
		if ($group->fetch($fkGroup) <= 0) {
			return $langs->trans('ErrorCampaignGroupNotFound');
		}
		$allowedEntities = array_map('intval', explode(',', (string) getEntity('usergroup')));
		if (!in_array((int) $group->entity, $allowedEntities, true)) {
			return $langs->trans('ErrorCampaignGroupWrongEntity');
		}

		return '';
	}

	/**
	 * Add active members of the campaign's group as voters (with default_points
	 * points), remove voters who are no longer in the group, and always keep the
	 * campaign creator as a voter (they vote first). Draft only, status reread
	 * from the database.
	 *
	 * @param	User	$user	Acting user (required by CampaignVoter::create()/delete(), never stored)
	 * @return	int<-1,1>	Return integer <0 if KO, >0 if OK
	 */
	public function syncVotersFromGroup(User $user)
	{
		if ($this->fetchStatusFromDb() !== self::STATUS_DRAFT) {
			$this->error = 'ErrorCampaignNotDraftCannotBeModified';
			return -1;
		}

		$groupError = $this->checkGroupReference($this->fk_usergroup);
		if ($groupError !== '') {
			$this->error = $groupError;
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
		dol_include_once('/salonerp/class/campaignvoter.class.php');

		$group = new UserGroup($this->db);
		if ($group->fetch($this->fk_usergroup) <= 0) {
			$this->error = 'ErrorCampaignGroupNotFound';
			return -1;
		}
		$members = $group->listUsersForGroup('u.statut = 1');
		if (!is_array($members)) {
			$this->error = 'ErrorCampaignGroupNotFound';
			return -1;
		}

		$activeUserIds = array();
		foreach ($members as $member) {
			$activeUserIds[(int) $member->id] = 1;
		}
		// The creator always votes, whether or not they belong to the group.
		$activeUserIds[(int) $this->fk_user_creat] = 1;

		$voter = new CampaignVoter($this->db);
		$existing = $voter->fetchByCampaign($this->id);
		if (!is_array($existing)) {
			$this->error = $voter->error;
			return -1;
		}

		$defaultPoints = (int) $this->default_points;
		if ($defaultPoints < 1) {
			$defaultPoints = 1;
		}

		$this->db->begin();

		$error = 0;

		// Add missing voters
		foreach (array_keys($activeUserIds) as $userId) {
			if (!isset($existing[$userId])) {
				$newVoter = new CampaignVoter($this->db);
				$newVoter->fk_campaign = $this->id;
				$newVoter->fk_user = $userId;
				$newVoter->points = $defaultPoints;
				if ($newVoter->create($user) <= 0) {
					$this->error = $newVoter->error;
					$error++;
					break;
				}
			}
		}

		// Remove voters no longer in the group (creator is always kept)
		if (!$error) {
			foreach ($existing as $userId => $existingVoter) {
				if (!isset($activeUserIds[$userId])) {
					if ($existingVoter->delete($user) <= 0) {
						$this->error = $existingVoter->error;
						$error++;
						break;
					}
				}
			}
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * The campaign's thirdparty list, with the snapshot taken at validation
	 * (name, code_client, zip, town: all null while the campaign is a draft).
	 *
	 * @return	array<int,array{id:int,name:?string,code_client:?string,zip:?string,town:?string}>|int<-1,-1>	Keyed and sorted by thirdparty id, or -1 if KO
	 */
	public function fetchThirdparties()
	{
		$sql = "SELECT fk_soc, name, code_client, zip, town FROM ".$this->db->prefix()."salonerp_campaign_thirdparty";
		$sql .= " WHERE fk_campaign = ".((int) $this->id);
		$sql .= " ORDER BY fk_soc ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[(int) $obj->fk_soc] = array(
				'id' => (int) $obj->fk_soc,
				'name' => $obj->name,
				'code_client' => $obj->code_client,
				'zip' => $obj->zip,
				'town' => $obj->town,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Ids of the campaign's thirdparties, as stored in its frozen list.
	 *
	 * @return	int[]|int<-1,-1>	Thirdparty ids sorted ascending, or -1 if KO
	 */
	public function fetchThirdpartyIds()
	{
		$rows = $this->fetchThirdparties();

		return is_array($rows) ? array_keys($rows) : -1;
	}

	/**
	 * Align the campaign's thirdparty list with the thirdparties currently
	 * tagged with its customer category: add the newly tagged ones, remove the
	 * ones no longer tagged. Draft only.
	 *
	 * The campaign row is locked (SELECT ... FOR UPDATE) and its status reread
	 * under that lock, exactly like validate() does: a sync running while the
	 * campaign is being validated either completes before the genesis is
	 * computed, or sees the validated status and refuses. It can never slip a
	 * thirdparty in after the list was frozen into the genesis hash.
	 *
	 * @param	User		$user	User that synchronizes
	 * @return	int<-1,1>			Return integer <0 if KO, >0 if OK
	 */
	public function syncThirdpartiesFromCategory(User $user)
	{
		$this->db->begin();

		$sql = "SELECT status FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id)." FOR UPDATE";
		$resql = $this->db->query($sql);
		$statusRow = $resql ? $this->db->fetch_object($resql) : null;
		if (!$statusRow || (int) $statusRow->status !== self::STATUS_DRAFT) {
			$this->error = 'ErrorCampaignNotDraftCannotBeModified';
			$this->db->rollback();
			return -1;
		}

		$categoryError = $this->checkCategoryReference($this->fk_category);
		if ($categoryError !== '') {
			$this->error = $categoryError;
			$this->db->rollback();
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$categorie = new Categorie($this->db);
		$categorie->fetch($this->fk_category);
		$tagged = $categorie->getObjectsInCateg(Categorie::TYPE_CUSTOMER, 1);
		if (!is_array($tagged)) {
			$this->error = $categorie->error;
			$this->db->rollback();
			return -1;
		}
		$taggedIds = array_map('intval', $tagged);

		$existingIds = $this->fetchThirdpartyIds();
		if (!is_array($existingIds)) {
			$this->db->rollback();
			return -1;
		}

		foreach (array_diff($taggedIds, $existingIds) as $socId) {
			$sql = "INSERT INTO ".$this->db->prefix()."salonerp_campaign_thirdparty (fk_campaign, fk_soc)";
			$sql .= " VALUES (".((int) $this->id).", ".((int) $socId).")";
			if (!$this->db->query($sql)) {
				$this->error = 'Error '.$this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$removedIds = array_diff($existingIds, $taggedIds);
		if (!empty($removedIds)) {
			$sql = "DELETE FROM ".$this->db->prefix()."salonerp_campaign_thirdparty";
			$sql .= " WHERE fk_campaign = ".((int) $this->id);
			$sql .= " AND fk_soc IN (".implode(',', array_map('intval', $removedIds)).")";
			if (!$this->db->query($sql)) {
				$this->error = 'Error '.$this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Empty the campaign's thirdparty list. No status check: callers (update()
	 * on a tag change, delete()) have already checked the campaign is a draft.
	 *
	 * @return	int<-1,1>	Return integer <0 if KO, >0 if OK
	 */
	protected function deleteThirdparties()
	{
		$sql = "DELETE FROM ".$this->db->prefix()."salonerp_campaign_thirdparty";
		$sql .= " WHERE fk_campaign = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		return 1;
	}

	/**
	 * Build the canonical genesis payload: sorted JSON keys, UTC ISO 8601 dates
	 * formatted with gmdate() (so the string never depends on PHP's current
	 * default timezone), voters sorted by fk_user, thirdparties sorted by id
	 * with their snapshot (name, code_client, zip, town as at validation): the
	 * hash thus covers the frozen list AND what each thirdparty was. Adding,
	 * removing or altering a single thirdparty changes it, and the original
	 * version stays readable in clear in the payload if one is later modified
	 * or deleted in Dolibarr. Reused by validate() to
	 * compute the genesis hash, and will be reused by lot 2 (chain) and the
	 * decryption tab. The result is what actually gets persisted into
	 * genesis_payload: lots 2/3 verify hash('sha256', genesis_payload) ==
	 * genesis_hash without ever reconstructing this string again.
	 *
	 * @param	array<int,array{fk_user:int,points:int}>	$voters			Voters as [fk_user, points] pairs, any order
	 * @param	array<int,array{id:int,name:?string,code_client:?string,zip:?string,town:?string}>	$thirdparties	Snapshot of the frozen thirdparty list, any order
	 * @return	string	JSON-encoded canonical payload
	 * @throws	\JsonException	If the payload cannot be JSON-encoded
	 */
	public function getGenesisPayload(array $voters, array $thirdparties)
	{
		global $conf;

		$sortedVoters = array();
		foreach ($voters as $v) {
			$sortedVoters[] = array((int) $v['fk_user'], (int) $v['points']);
		}
		usort($sortedVoters, function ($a, $b) {
			return $a[0] <=> $b[0];
		});

		// gmdate() ignores date_default_timezone_set(): for a fixed Unix
		// timestamp the formatted string is always the same, whatever the
		// server's configured timezone happens to be when this runs.
		// Each thirdparty is an object with sorted keys, and every value has a
		// fixed type (int id, strings elsewhere, never null): the same snapshot
		// always encodes to the same bytes.
		$sortedThirdparties = array();
		foreach ($thirdparties as $t) {
			$sortedThirdparties[] = array(
				'code_client' => (string) $t['code_client'],
				'id' => (int) $t['id'],
				'name' => (string) $t['name'],
				'town' => (string) $t['town'],
				'zip' => (string) $t['zip'],
			);
		}
		usort($sortedThirdparties, function ($a, $b) {
			return $a['id'] <=> $b['id'];
		});

		$payload = array(
			'date_end' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->date_end),
			'date_start' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->date_start),
			'entity' => (int) ($this->entity ?: $conf->entity),
			'fk_category' => (int) $this->fk_category,
			'fk_user_creat' => (int) $this->fk_user_creat,
			'fk_usergroup' => (int) $this->fk_usergroup,
			'public_key' => (string) $this->public_key,
			'ref' => (string) $this->ref,
			'thirdparties' => $sortedThirdparties,
			'tie_rule' => (string) $this->tie_rule,
			'voters' => $sortedVoters,
		);
		ksort($payload);

		return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * Validate the campaign: freeze it, generate the X25519 keypair (sodium sealed
	 * box) and the genesis hash. The private key is returned to the caller ONLY:
	 * it is never written to the database, to a log, to the session or to a file.
	 *
	 * The whole method runs in one transaction. The row is locked (SELECT ...
	 * FOR UPDATE) and the status is reread from the database as the very first
	 * thing: this is what makes a concurrent double-validate impossible, not
	 * the in-memory ->status. Voters and thirdparties are also read inside this
	 * same transaction.
	 *
	 * Refuses, each with a translated error message added to $this->errors, if:
	 *  - the campaign is not a draft (reread from database)
	 *  - the acting user is not the campaign's creator
	 *  - date_end <= date_start
	 *  - date_end <= now
	 *  - the category or the group is missing, not found, wrong entity, or
	 *    (category) not of type customer
	 *  - there is no voter
	 *  - a voter has <=0 points
	 *  - the creator is not among the voters
	 *  - the campaign's thirdparty list is empty (see syncThirdpartiesFromCategory())
	 *  - a thirdparty of the list no longer exists in Dolibarr (sync again)
	 *
	 * @param	User		$user		User validating
	 * @param	int<0,1>	$notrigger	1=Does not execute triggers, 0=execute triggers
	 * @return	string|int<-1,-1>		Base64-encoded private key (32 bytes) on success, -1 on error
	 */
	public function validate($user, $notrigger = 0)
	{
		global $langs;

		$langs->load('salonerp@salonerp');

		$this->errors = array();

		$this->db->begin();

		// Lock the row and reread its status: the in-memory ->status of the
		// object passed by the caller is never trusted for this decision.
		$sql = "SELECT status FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id)." FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$statusRow = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$statusRow || (int) $statusRow->status !== self::STATUS_DRAFT) {
			$this->errors[] = $langs->trans('ErrorCampaignNotDraft');
			$this->db->rollback();
			return -1;
		}

		if ((int) $user->id !== (int) $this->fk_user_creat) {
			$this->errors[] = $langs->trans('ErrorCampaignOnlyCreatorCanValidate');
		}

		$this->errors = array_merge($this->errors, $this->checkDates());

		$categoryError = $this->checkCategoryReference($this->fk_category);
		if ($categoryError !== '') {
			$this->errors[] = $categoryError;
		}
		$groupError = $this->checkGroupReference($this->fk_usergroup);
		if ($groupError !== '') {
			$this->errors[] = $groupError;
		}

		// Voters are read INSIDE the locked transaction: nothing can change
		// them concurrently between this read and the UPDATE below.
		dol_include_once('/salonerp/class/campaignvoter.class.php');
		$voterObj = new CampaignVoter($this->db);
		$voters = $voterObj->fetchByCampaign($this->id);
		if (!is_array($voters)) {
			$this->errors[] = $voterObj->error;
			$voters = array();
		}

		if (empty($voters)) {
			// "No voter" already covers the creator being absent: reporting
			// "creator not voter" on top would be a redundant, confusing
			// second message for the exact same underlying cause.
			$this->errors[] = $langs->trans('ErrorCampaignNoVoter');
		} else {
			$creatorFound = false;
			foreach ($voters as $userId => $v) {
				// The points column is a SQL integer and CampaignVoter::create()/
				// update() already refuse anything else: a "not an integer" branch
				// here would be unreachable and is intentionally not implemented.
				if ((int) $v->points <= 0) {
					$voterUser = new User($this->db);
					$voterUser->fetch((int) $userId);
					$this->errors[] = $langs->trans('ErrorCampaignVoterPointsNotPositive', $voterUser->getFullName($langs));
				}
				if ($userId == (int) $this->fk_user_creat) {
					$creatorFound = true;
				}
			}
			if (!$creatorFound) {
				$this->errors[] = $langs->trans('ErrorCampaignCreatorNotVoter');
			}
		}

		// The frozen list, read under the same lock: never the tag itself,
		// whose content may change at any time during the campaign. An empty
		// list is only reported with a valid tag: with a wrong tag, "sync with
		// the tag" would be misleading advice.
		$thirdpartyIds = $this->fetchThirdpartyIds();
		if (!is_array($thirdpartyIds)) {
			$this->errors[] = $this->error;
			$thirdpartyIds = array();
		} elseif (empty($thirdpartyIds) && $categoryError === '') {
			$this->errors[] = $langs->trans('ErrorCampaignNoThirdparty');
		}

		// Snapshot of each thirdparty as it is right now: this is the
		// "original version" kept in the genesis and in the list.
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$snapshot = array();
		foreach ($thirdpartyIds as $socId) {
			$soc = new Societe($this->db);
			if ($soc->fetch($socId) <= 0) {
				$this->errors[] = $langs->trans('ErrorCampaignThirdpartyNotFound', $socId);
				continue;
			}
			$snapshot[$socId] = array(
				'id' => (int) $socId,
				'name' => (string) $soc->name,
				'code_client' => (string) $soc->code_client,
				'zip' => (string) $soc->zip,
				'town' => (string) $soc->town,
			);
		}

		if (count($this->errors)) {
			$this->db->rollback();
			return -1;
		}

		$error = 0;

		// sodium_crypto_box_keypair() returns a 64 bytes concatenation of the
		// secret key (32 bytes) and the public key (32 bytes). Only the public
		// part is ever persisted; the private part lives in PHP variables that
		// are wiped as soon as they are no longer needed.
		$keypair = sodium_crypto_box_keypair();
		$secretKey = sodium_crypto_box_secretkey($keypair);
		$publicKey = sodium_crypto_box_publickey($keypair);
		sodium_memzero($keypair);

		$publicKeyB64 = base64_encode($publicKey);
		$privateKeyB64 = base64_encode($secretKey);
		sodium_memzero($publicKey);
		sodium_memzero($secretKey);

		$this->public_key = $publicKeyB64;

		$voterPairs = array();
		foreach ($voters as $userId => $v) {
			$voterPairs[] = array('fk_user' => $userId, 'points' => (int) $v->points);
		}

		try {
			$this->genesis_payload = $this->getGenesisPayload($voterPairs, $snapshot);
		} catch (\JsonException $e) {
			$this->errors[] = $langs->trans('ErrorCampaignGenesisPayloadEncoding');
			sodium_memzero($privateKeyB64);
			$this->db->rollback();
			return -1;
		}
		$this->genesis_hash = hash('sha256', $this->genesis_payload);

		$now = dol_now();

		$sql = "UPDATE ".$this->db->prefix().$this->table_element;
		$sql .= " SET status = ".self::STATUS_VALIDATED;
		$sql .= ", public_key = '".$this->db->escape($this->public_key)."'";
		$sql .= ", genesis_hash = '".$this->db->escape($this->genesis_hash)."'";
		$sql .= ", genesis_payload = '".$this->db->escape($this->genesis_payload)."'";
		$sql .= ", date_validation = '".$this->db->idate($now)."'";
		$sql .= ", fk_user_valid = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND status = ".self::STATUS_DRAFT;

		$resql = $this->db->query($sql);
		// Read now: affected_rows() reports the LAST query of the connection,
		// and the snapshot UPDATEs below would overwrite it.
		$written = ($resql && $this->db->affected_rows($resql) == 1);
		if ($written) {
			foreach ($snapshot as $socId => $t) {
				$sqlSnap = "UPDATE ".$this->db->prefix()."salonerp_campaign_thirdparty SET";
				$sqlSnap .= " name = '".$this->db->escape($t['name'])."'";
				$sqlSnap .= ", code_client = '".$this->db->escape($t['code_client'])."'";
				$sqlSnap .= ", zip = '".$this->db->escape($t['zip'])."'";
				$sqlSnap .= ", town = '".$this->db->escape($t['town'])."'";
				$sqlSnap .= " WHERE fk_campaign = ".((int) $this->id)." AND fk_soc = ".((int) $socId);
				if (!$this->db->query($sqlSnap)) {
					$written = false;
					break;
				}
			}
		}
		if (!$written) {
			$this->errors[] = $langs->trans('ErrorCampaignValidationWriteFailed');
			// Nothing was actually persisted: do not let the in-memory object
			// claim otherwise.
			$this->public_key = null;
			$this->genesis_hash = null;
			$this->genesis_payload = null;
			$error++;
		}

		if (!$error && !$notrigger) {
			$result = $this->call_trigger('SALONERP_CAMPAIGN_VALIDATE', $user);
			if ($result < 0) {
				$error++;
			}
		}

		// Note: $publicKeyB64 is never wiped, it is also referenced by
		// $this->public_key and is not sensitive. Only the private key is
		// wiped, and only on the path where it is not returned to the caller.
		if ($error) {
			sodium_memzero($privateKeyB64);
			$this->db->rollback();
			return -1;
		}

		$this->status = self::STATUS_VALIDATED;
		$this->date_validation = $now;
		$this->fk_user_valid = $user->id;

		$this->db->commit();

		// Returned once: the caller must display it and MUST NOT persist it.
		return $privateKeyB64;
	}

	/**
	 * Derive the public key from a candidate private key and compare it, in
	 * constant time, to the campaign's stored public key. Used from lot 2 on to
	 * check the private key re-entered by the campaign creator (first vote,
	 * final reveal), but already unit-tested in this lot.
	 *
	 * @param	string	$privateKeyB64	Base64-encoded candidate private key (32 bytes)
	 * @param	string	$publicKeyB64	Base64-encoded reference public key (32 bytes)
	 * @return	bool					True if the private key matches the public key
	 */
	public static function checkPrivateKey(string $privateKeyB64, string $publicKeyB64): bool
	{
		if ($privateKeyB64 === '' || $publicKeyB64 === '') {
			return false;
		}

		$privateKey = base64_decode($privateKeyB64, true);
		if ($privateKey === false || strlen($privateKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
			return false;
		}

		$referencePublicKey = base64_decode($publicKeyB64, true);
		if ($referencePublicKey === false || strlen($referencePublicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			sodium_memzero($privateKey);
			return false;
		}

		$derivedPublicKey = sodium_crypto_box_publickey_from_secretkey($privateKey);
		sodium_memzero($privateKey);

		$result = hash_equals($referencePublicKey, $derivedPublicKey);
		sodium_memzero($derivedPublicKey);

		return $result;
	}

	/**
	 * Output a field value for display. Overrides the fields the generic
	 * renderer shows badly:
	 *  - fk_category: Categorie::getNomUrl() forces a white text, meant to sit
	 *    on a coloured pill; rendered alone it is white on white. Shown here as
	 *    the core does in Form::showCategories().
	 *  - public_key, genesis_hash: long strings, allowed to break anywhere.
	 *  - tie_rule: a code, shown as its translated label.
	 *
	 * @param	array<string,mixed>	$val		Array of properties for field to show
	 * @param	string				$key		Key of attribute
	 * @param	string|int|float	$value		Value to show
	 * @param	string				$moreparam	To add more parameters on html tag
	 * @param	string				$keysuffix	Suffix string to add into name and id of field (can be used to avoid duplicate names)
	 * @param	string				$keyprefix	Prefix string to add into name and id of field (can be used to avoid duplicate names)
	 * @param	mixed				$morecss	Value for CSS to use (Old usage: May also be a numeric to define a size).
	 * @return	string
	 */
	public function showOutputField($val, $key, $value, $moreparam = '', $keysuffix = '', $keyprefix = '', $morecss = '')
	{
		global $langs;

		if ($key === 'fk_category') {
			if (empty($value)) {
				return '';
			}
			require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
			$categorie = new Categorie($this->db);
			if ($categorie->fetch((int) $value) <= 0) {
				return '';
			}
			$textClass = ($categorie->color && colorIsLight($categorie->color)) ? 'categtextblack' : 'categtextwhite';
			$background = $categorie->color ? '#'.$categorie->color : '#bbb';

			return '<div class="select2-container-multi-dolibarr"><ul class="select2-choices-dolibarr"><li class="select2-search-choice-dolibarr noborderoncategories nomarginleft '.$textClass.'" style="background: '.dol_escape_htmltag($background).';">'
				.$categorie->getNomUrl(1)
				.'</li></ul></div>';
		}
		if ($key === 'public_key' || $key === 'genesis_hash') {
			// Long unbroken strings: without a forced break they squeeze the
			// label column of the card.
			return '<span class="small" style="word-break: break-all;">'.dol_escape_htmltag((string) $value).'</span>';
		}
		if ($key === 'tie_rule') {
			$langs->load('salonerp@salonerp');
			return dol_escape_htmltag($langs->trans('CampaignTieRule_'.$value));
		}

		return parent::showOutputField($val, $key, $value, $moreparam, $keysuffix, $keyprefix, $morecss);
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param	int<0,6>	$mode         0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLabelStatus($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param	int<0,6>	$mode	0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string				Label of status
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return the label of a given status
	 *
	 *  @param	int			$status		Id status
	 *  @param	int<0,6>	$mode		0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string					Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		if (is_null($status)) {
			return '';
		}

		$paramsBadge = array('badgeParams' => array('attr' => array(
			'data-status-element' => $this->element,
			'data-status' => (int) $status
		)));

		if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
			global $langs;
			$langs->load('salonerp@salonerp');
			$this->labelStatus[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			$this->labelStatus[self::STATUS_VALIDATED] = $langs->transnoentitiesnoconv('Validated');
			$this->labelStatusShort[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			$this->labelStatusShort[self::STATUS_VALIDATED] = $langs->transnoentitiesnoconv('Validated');
		}

		$statusType = 'status'.$status;
		if ($status == self::STATUS_DRAFT) {
			$statusType = 'status0';
		} elseif ($status == self::STATUS_VALIDATED) {
			$statusType = 'status4';
		}

		return dolGetStatus($this->labelStatus[$status], $this->labelStatusShort[$status], '', $statusType, $mode, '', $paramsBadge);
	}

	/**
	 *	Return a link to the object card (with optionally the picto)
	 *
	 *  @param	int     $withpicto                  Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
	 *  @param	string  $option                     On what the link point to ('nolink', ...)
	 *  @param	int     $notooltip                  1=Disable tooltip
	 *  @param	string  $morecss                    Add more css on link
	 *  @param	int     $save_lastsearch_value      -1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values whenclicking
	 *  @return	string                              String with URL
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $langs;

		$result = '';

		$label = img_picto('', $this->picto).' <u>'.$langs->trans('Campaign').'</u>';
		$label .= ' '.$this->getLibStatut(5);
		$label .= '<br><b>'.$langs->trans('Ref').':</b> '.$this->ref;
		if (!empty($this->label)) {
			$label .= '<br>'.$this->label;
		}

		$url = dol_buildpath('/salonerp/campaign_card.php', 1).'?id='.$this->id;

		$linkclose = '';
		if (empty($notooltip)) {
			$linkclose .= ' title="'.dolPrintHTMLForAttribute($label).'"';
			$linkclose .= ' class="classfortooltip'.($morecss ? ' '.$morecss : '').'"';
		} else {
			$linkclose = ($morecss ? ' class="'.$morecss.'"' : '');
		}

		$linkstart = ($option == 'nolink') ? '<span' : '<a href="'.$url.'"';
		$linkstart .= $linkclose.'>';
		$linkend = ($option == 'nolink') ? '</span>' : '</a>';

		$result .= $linkstart;
		if ($withpicto) {
			$result .= img_object(($notooltip ? '' : $label), $this->picto, (($withpicto != 2) ? 'class="paddingright"' : ''), 0, 0, $notooltip ? 0 : 1);
		}
		if ($withpicto != 2) {
			$result .= $this->ref;
		}
		$result .= $linkend;

		return $result;
	}

	/**
	 *	Return a thumb for kanban views
	 *
	 *	@param	string	    			$option		Where point the link ('nolink'=>No link)
	 *  @param	?array<string,mixed>	$arraydata	Array of data
	 *  @return	string								HTML Code for Kanban thumb.
	 */
	public function getKanbanView($option = '', $arraydata = null)
	{
		$selected = (empty($arraydata['selected']) ? 0 : $arraydata['selected']);

		$return = '<div class="box-flex-item box-flex-grow-zero">';
		$return .= '<div class="info-box info-box-sm">';
		$return .= '<span class="info-box-icon bg-infobox-action">';
		$return .= img_picto('', $this->picto);
		$return .= '</span>';
		$return .= '<div class="info-box-content">';
		$return .= '<span class="info-box-ref inline-block tdoverflowmax150 valignmiddle">'.$this->getNomUrl().'</span>';
		if ($selected >= 0) {
			$return .= '<input id="cb'.$this->id.'" class="flat checkforselect fright" type="checkbox" name="toselect[]" value="'.$this->id.'"'.($selected ? ' checked="checked"' : '').'>';
		}
		if (!empty($this->label)) {
			$return .= ' <div class="inline-block opacitymedium valignmiddle tdoverflowmax100">'.$this->label.'</div>';
		}
		$return .= '<br><div class="info-box-status">'.$this->getLibStatut(3).'</div>';
		$return .= '</div>';
		$return .= '</div>';
		$return .= '</div>';

		return $return;
	}

	/**
	 * Initialize object with example values.
	 *
	 * @return	int
	 */
	public function initAsSpecimen()
	{
		return $this->initAsSpecimenCommon();
	}
}

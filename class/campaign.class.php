<?php
/* Copyright (C) 2017       Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2025  Frédéric France          <frederic.france@free.fr>
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
	 * Only the creator can vote, with the private key: that opens the vote.
	 */
	const STATUS_VALIDATED = 1;
	/** Vote open to every voter: the creator has voted. */
	const STATUS_VOTE_OPEN = 2;
	/** date_end reached with the vote open: waits for the reveal (lot 3). */
	const STATUS_ENDED = 3;
	/** Votes decrypted and counted, private key stored and public. */
	const STATUS_REVEALED = 4;
	/** date_end reached before the creator voted: no vote at all, deletable. */
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
		"private_key" => array("type" => "varchar(64)", "label" => "PrivateKeyLabel", "enabled" => "1", 'position' => 82, 'notnull' => 0, "visible" => "0", "noteditable" => 1),
		"date_reveal" => array("type" => "datetime", "label" => "CampaignDateReveal", "enabled" => "1", 'position' => 83, 'notnull' => 0, "visible" => "-3", "noteditable" => 1),
		"date_attribution" => array("type" => "datetime", "label" => "CampaignDateAttribution", "enabled" => "1", 'position' => 84, 'notnull' => 0, "visible" => "-3", "noteditable" => 1),
		"fk_user_attribution" => array("type" => "integer:User:user/class/user.class.php", "label" => "CampaignUserAttribution", "picto" => "user", "enabled" => "1", 'position' => 85, 'notnull' => 0, "visible" => "0", "noteditable" => 1),
		"status" => array("type" => "integer", "label" => "Status", "enabled" => "1", 'position' => 90, 'notnull' => 1, "visible" => "1", "index" => 1, "arrayofkeyval" => array("0" => "Draft", "1" => "Validated", "2" => "CampaignStatusVoteOpen", "3" => "CampaignStatusEnded", "4" => "CampaignStatusRevealed", "8" => "CampaignStatusExtinct"), "default" => "0"),
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
	/** @var ?string Base64 private key, stored at the reveal (then public, shown to all), null before */
	public $private_key;
	/** @var ?int Unix timestamp of the reveal */
	public $date_reveal;
	/** @var ?int Unix timestamp of the sales reps attribution */
	public $date_attribution;
	/** @var ?int */
	public $fk_user_attribution;
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
	 * Create object into database, then run the same two synchronisations the
	 * "Synchroniser" buttons trigger by hand (syncVotersFromGroup(),
	 * syncThirdpartiesFromCategory()): the draft shows its voters and
	 * thirdparties immediately, with no click needed. Each sync opens its own
	 * transaction, after createCommon()'s has already committed: a sync
	 * failure never undoes the draft that was just created. On failure,
	 * ->errors carries translated messages for the caller to display; the
	 * buttons stay available on the draft to retry by hand.
	 *
	 * @param	User		$user		User that creates
	 * @param	int<0,1> 	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,max>				Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $conf, $langs;

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

		$result = $this->createCommon($user, $notrigger);
		if ($result <= 0) {
			return $result;
		}

		$langs->load('salonerp@salonerp');
		$this->errors = array();
		// No group/category chosen (should not happen, both are mandatory
		// fields, but the page must never be trusted blindly): simply skip
		// the corresponding sync, no error.
		if (!empty($this->fk_usergroup) && $this->syncVotersFromGroup($user) <= 0) {
			$this->errors[] = $langs->trans($this->error);
		}
		if (!empty($this->fk_category) && $this->syncThirdpartiesFromCategory($user) <= 0) {
			$this->errors[] = $langs->trans($this->error);
		}

		return $result;
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
	 * Lock the campaign row until the end of the current transaction (SELECT
	 * ... FOR UPDATE) and reread its status under that lock. Must be called
	 * after $this->db->begin(). Every method that changes what validate()
	 * freezes, or that appends to the vote chain, takes this lock first: two
	 * such operations on one campaign are then strictly sequential, and none
	 * can slip in between validate()'s read and its write.
	 *
	 * @return	?int	Status read under the lock, null if the row is missing or on SQL error
	 */
	protected function lockAndFetchStatus()
	{
		$sql = "SELECT status FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id)." FOR UPDATE";
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
	 * The status is reread from the database: an in-memory ->status =
	 * STATUS_DRAFT on a validated object must not grant anything.
	 *
	 * If the tag or the group changed, the corresponding list is
	 * resynchronised automatically (syncThirdpartiesFromCategory() /
	 * syncVotersFromGroup(), the same the "Synchroniser" buttons call by
	 * hand), once the field update itself is safely committed: a sync
	 * failure never undoes the fields that were just saved. On failure,
	 * ->errors carries translated messages for the caller to display; the
	 * buttons stay available on the draft to retry by hand. Any other field
	 * (dates, label, default points, description...) never touches the
	 * voters or the thirdparty list.
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 0)
	{
		global $langs;

		if ($this->fetchStatusFromDb() !== self::STATUS_DRAFT) {
			$this->error = 'ErrorCampaignNotDraftCannotBeModified';
			return -1;
		}

		$sql = "SELECT fk_category, fk_usergroup FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$resql = $this->db->query($sql);
		$previous = $resql ? $this->db->fetch_object($resql) : null;
		if (!$previous) {
			$this->error = 'Error '.$this->db->lasterror();
			return -1;
		}
		$categoryChanged = (int) $previous->fk_category !== (int) $this->fk_category;
		$groupChanged = (int) $previous->fk_usergroup !== (int) $this->fk_usergroup;

		$this->db->begin();

		$result = $this->updateCommon($user, $notrigger);
		if ($result <= 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		$langs->load('salonerp@salonerp');
		$this->errors = array();
		if ($groupChanged && !empty($this->fk_usergroup) && $this->syncVotersFromGroup($user) <= 0) {
			$this->errors[] = $langs->trans($this->error);
		}
		if ($categoryChanged && !empty($this->fk_category) && $this->syncThirdpartiesFromCategory($user) <= 0) {
			$this->errors[] = $langs->trans($this->error);
		}

		return $result;
	}

	/**
	 * Delete object in database. Allowed only in draft or extinct status (reread
	 * from the database): a validated campaign carries a genesis hash and a public key
	 * that must not be lost silently. All-or-nothing: voters, thirdparties and
	 * the campaign row are deleted in a single transaction.
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1> 	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 0)
	{
		// An extinct campaign never had a single vote (its creator never
		// opened it); the vote table's foreign key refuses the delete anyway
		// should a vote exist.
		if (!in_array($this->fetchStatusFromDb(), array(self::STATUS_DRAFT, self::STATUS_EXTINCT), true)) {
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
		$this->db->begin();
		$result = $this->syncVotersFromGroupLocked($user);
		if ($result < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * Save the points of the campaign's voters. Draft only, all-or-nothing,
	 * under the campaign lock (see lockAndFetchStatus()). Users absent from
	 * $pointsByUser keep their points; ids that are not voters are ignored.
	 *
	 * @param	User				$user			User that saves
	 * @param	array<int|string,int|string>	$pointsByUser	New points, keyed by voter user id
	 * @return	int<-1,1>							Return integer <0 if KO (translated message in ->error), >0 if OK
	 */
	public function saveVoterPoints(User $user, array $pointsByUser)
	{
		global $langs;

		$langs->load('salonerp@salonerp');
		dol_include_once('/salonerp/class/campaignvoter.class.php');

		$this->db->begin();

		if ($this->lockAndFetchStatus() !== self::STATUS_DRAFT) {
			$this->error = $langs->trans('ErrorCampaignNotDraftCannotBeModified');
			$this->db->rollback();
			return -1;
		}

		$voter = new CampaignVoter($this->db);
		$existingVoters = $voter->fetchByCampaign($this->id);
		if (!is_array($existingVoters)) {
			$this->error = $voter->error;
			$this->db->rollback();
			return -1;
		}

		foreach ($existingVoters as $fkUser => $existingVoter) {
			if (!array_key_exists((string) $fkUser, $pointsByUser) && !array_key_exists((int) $fkUser, $pointsByUser)) {
				continue;
			}
			$existingVoter->points = (int) $pointsByUser[$fkUser];
			if ($existingVoter->update($user) <= 0) {
				$voterUser = new User($this->db);
				$voterUser->fetch((int) $fkUser);
				$this->error = $langs->trans($existingVoter->error, $voterUser->getFullName($langs));
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Body of syncVotersFromGroup(), run with the campaign row locked (see
	 * lockAndFetchStatus()). The caller owns the transaction.
	 *
	 * @param	User		$user	User that synchronizes
	 * @return	int<-1,1>			Return integer <0 if KO, >0 if OK
	 */
	private function syncVotersFromGroupLocked(User $user)
	{
		if ($this->lockAndFetchStatus() !== self::STATUS_DRAFT) {
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

		return $error ? -1 : 1;
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

		if ($this->lockAndFetchStatus() !== self::STATUS_DRAFT) {
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
	 * Empty the campaign's thirdparty list. No status check: the only caller
	 * (delete()) has already checked the campaign is a draft. A tag change on
	 * a draft no longer goes through here: update() resynchronises the list
	 * from the new tag instead of emptying it (syncThirdpartiesFromCategory()).
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
	 * The names that go into the genesis next to their ids, read now from
	 * Dolibarr: tag label, group name, creator name, and each voter's name.
	 * validate() freezes them into the genesis payload; later renamings do
	 * not reach it.
	 *
	 * @param	array<int,int>	$pointsByUser	Voters' envelopes, keyed by user id
	 * @return	array{0:array<int,array{id:int,name:string,points:int}>,1:array{category:string,group:string,creator:string}}	Named voters, then names
	 */
	public function getGenesisNames(array $pointsByUser)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';

		$voters = array();
		foreach ($pointsByUser as $userId => $points) {
			$voterUser = new User($this->db);
			$voterUser->fetch((int) $userId);
			$voters[] = array('id' => (int) $userId, 'name' => (string) $voterUser->getFullName($langs), 'points' => (int) $points);
		}

		$categorie = new Categorie($this->db);
		$categorie->fetch($this->fk_category);
		$group = new UserGroup($this->db);
		$group->fetch($this->fk_usergroup);
		$creator = new User($this->db);
		$creator->fetch($this->fk_user_creat);

		return array($voters, array(
			'category' => (string) $categorie->label,
			'group' => (string) $group->name,
			'creator' => (string) $creator->getFullName($langs),
		));
	}

	/**
	 * Build the canonical genesis payload: sorted JSON keys, UTC ISO 8601 dates
	 * formatted with gmdate() (so the string never depends on PHP's current
	 * default timezone), voters sorted by id, thirdparties sorted by id
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
	 * Every public fact is spelled out, never left as a bare id: voters,
	 * creator, tag and group carry their name as at validation. Only the
	 * votes themselves are secret (see castVote()).
	 *
	 * @param	array<int,array{id:int,name:string,points:int}>	$voters			Voters with their name and envelope, any order
	 * @param	array<int,array{id:int,name:?string,code_client:?string,zip:?string,town:?string}>	$thirdparties	Snapshot of the frozen thirdparty list, any order
	 * @param	array{category:string,group:string,creator:string}	$names	Tag label, group name and creator name as at validation
	 * @param	int		$ballotSize		Worst-case ballot plaintext size, in bytes, computed by computeBallotSize(): every ballot of this campaign is padded (sodium_pad()) to exactly this many bytes before sealing, so a ciphertext's length never leaks how a vote was cast
	 * @return	string	JSON-encoded canonical payload
	 * @throws	\JsonException	If the payload cannot be JSON-encoded
	 */
	public function getGenesisPayload(array $voters, array $thirdparties, array $names, int $ballotSize)
	{
		global $conf;

		$sortedVoters = array();
		foreach ($voters as $v) {
			$sortedVoters[] = array('id' => (int) $v['id'], 'name' => (string) $v['name'], 'points' => (int) $v['points']);
		}
		usort($sortedVoters, function ($a, $b) {
			return $a['id'] <=> $b['id'];
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
			'ballot_size' => $ballotSize,
			'category' => array('id' => (int) $this->fk_category, 'label' => (string) $names['category']),
			'creator' => array('id' => (int) $this->fk_user_creat, 'name' => (string) $names['creator']),
			'date_end' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->date_end),
			'date_start' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->date_start),
			'entity' => (int) ($this->entity ?: $conf->entity),
			'group' => array('id' => (int) $this->fk_usergroup, 'name' => (string) $names['group']),
			'label' => (string) $this->label,
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
	 * Canonical JSON of one ballot's plaintext: {"campaign","date","fk_user",
	 * "points","seq"} with sorted keys, unescaped slashes/unicode. This is the
	 * SOLE place that builds this string: castVote() calls it to seal the real
	 * ballot, computeBallotSize() calls it to measure the campaign's worst
	 * case. They must never diverge, or a ciphertext's padded length would
	 * stop matching the genesis's ballot_size.
	 *
	 * @param	string							$ref		Campaign ref
	 * @param	int								$timestamp	Unix timestamp of the vote; gmdate() always encodes 'date' on the same 20 bytes, whatever this value is
	 * @param	int								$fkUser		Voting user id
	 * @param	array<int,array{0:int,1:int}>	$pairs		[thirdparty id, points] pairs, zero allocations already dropped, any order
	 * @param	int								$seq		Rank of the vote in the chain
	 * @return	string							JSON-encoded ballot plaintext
	 * @throws	\JsonException					If the payload cannot be JSON-encoded
	 */
	public static function buildBallotPlaintext(string $ref, int $timestamp, int $fkUser, array $pairs, int $seq): string
	{
		return json_encode(array(
			'campaign' => $ref,
			'date' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
			'fk_user' => $fkUser,
			'points' => array_values($pairs),
			'seq' => $seq,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * The public meaning of a decoded ballot (its 'fk_user' and 'points' pairs
	 * resolved to names), for display only: never part of anything hashed or
	 * compared. Reused by buildReceipt() (the voter's own ballot, right after
	 * casting it) and by SalonerpVoteFile's receipt check (a reader verifying
	 * someone else's receipt against a vote file, from that file's own genesis).
	 *
	 * @param	array{fk_user?:int,date?:string,points?:array<int,array{0:int,1:int}>}	$ballot		Decoded ballot plaintext (json_decode of buildBallotPlaintext())
	 * @param	?array{voters?:array<int,mixed>,thirdparties?:array<int,mixed>}		$genesis	Decoded genesis payload names are read from, or null
	 * @return	array{date:string,fk_user:int,voter:string,thirdparties:array<int,array{id:int,name:string,points:int}>}
	 */
	public static function describeBallot(array $ballot, ?array $genesis): array
	{
		$thirdpartyNames = array();
		$voterNames = array();
		if (is_array($genesis)) {
			foreach ((isset($genesis['thirdparties']) && is_array($genesis['thirdparties'])) ? $genesis['thirdparties'] : array() as $t) {
				if (is_array($t) && isset($t['id'])) {
					$thirdpartyNames[(int) $t['id']] = isset($t['name']) ? (string) $t['name'] : '';
				}
			}
			foreach ((isset($genesis['voters']) && is_array($genesis['voters'])) ? $genesis['voters'] : array() as $v) {
				if (is_array($v) && isset($v['id'])) {
					$voterNames[(int) $v['id']] = isset($v['name']) ? (string) $v['name'] : '';
				}
			}
		}

		$fkUser = isset($ballot['fk_user']) ? (int) $ballot['fk_user'] : 0;
		$result = array(
			'date' => isset($ballot['date']) ? (string) $ballot['date'] : '',
			'fk_user' => $fkUser,
			'voter' => (isset($voterNames[$fkUser]) && $voterNames[$fkUser] !== '') ? $voterNames[$fkUser] : ('#'.$fkUser),
			'thirdparties' => array(),
		);
		foreach ((isset($ballot['points']) && is_array($ballot['points'])) ? $ballot['points'] : array() as $pair) {
			$socId = (int) $pair[0];
			$result['thirdparties'][] = array(
				'id' => $socId,
				'name' => (isset($thirdpartyNames[$socId]) && $thirdpartyNames[$socId] !== '') ? $thirdpartyNames[$socId] : ('#'.$socId),
				'points' => (int) $pair[1],
			);
		}

		return $result;
	}

	/**
	 * Worst-case size, in bytes, of this campaign's ballot plaintext: a true
	 * majorant, computed once at validation and frozen into the genesis
	 * ('ballot_size'). It assumes, all at once:
	 *  - every tier of the frozen list is bid on (a real ballot only lists the
	 *    ones it bids on, zero allocations dropped: never more than all of them);
	 *  - every one of those bids equals the largest envelope of the campaign
	 *    (a real bid is at most its own voter's envelope, itself at most the
	 *    largest one, whatever tier it lands on);
	 *  - the voter is the one with the largest id (a real fk_user is one of
	 *    the campaign's voters, at most the largest of their ids);
	 *  - seq is the number of voters (each voter votes at most once, so no
	 *    real seq ever exceeds that count);
	 *  - 'date' is always 20 bytes, whatever the instant: any value does.
	 * castVote() then pads every real plaintext up to this exact size with
	 * sodium_pad(), so a ciphertext's length no longer depends on how many
	 * tiers were bid on, how large the bids were, or who voted.
	 *
	 * @param	int[]			$thirdpartyIds	Frozen thirdparty ids
	 * @param	array<int,int>	$pointsByUser	Voters' envelopes, keyed by user id (never empty: validate() already refuses an empty voter list before this is called)
	 * @return	int				Ballot size, in bytes: strictly greater than the worst-case plaintext computed here
	 * @throws	\JsonException
	 */
	public function computeBallotSize(array $thirdpartyIds, array $pointsByUser): int
	{
		$maxEnvelope = empty($pointsByUser) ? 0 : max($pointsByUser);
		$maxUserId = empty($pointsByUser) ? 0 : max(array_keys($pointsByUser));

		$ids = array_map('intval', $thirdpartyIds);
		sort($ids);
		$pairs = array();
		foreach ($ids as $socId) {
			$pairs[] = array($socId, $maxEnvelope);
		}

		// The exact instant does not matter (gmdate() output is fixed-length),
		// but a real one keeps this reproducible and avoids relying on 0.
		$worstCase = self::buildBallotPlaintext((string) $this->ref, (int) $this->date_end, $maxUserId, $pairs, count($pointsByUser));

		// sodium_pad($plaintext, $ballotSize) pads up to the smallest multiple
		// of $ballotSize strictly greater than strlen($plaintext): with the
		// worst case one byte under $ballotSize, that multiple is always
		// $ballotSize itself, for every real (smaller) ballot too.
		return strlen($worstCase) + 1;
	}

	/**
	 * The ballot_size fixed by this campaign's stored genesis. Never
	 * recomputed: it is read back exactly as validate() froze it, the sole
	 * size to which castVote()/openBallots() pad or unpad every ballot.
	 *
	 * @return	?int	Ballot size, or null if the stored genesis has none (a genesis frozen before this size existed: refused everywhere, see README)
	 */
	protected function genesisBallotSize(): ?int
	{
		$genesis = json_decode((string) $this->genesis_payload, true);
		if (!is_array($genesis) || !isset($genesis['ballot_size']) || !is_int($genesis['ballot_size']) || $genesis['ballot_size'] <= 0) {
			return null;
		}

		return $genesis['ballot_size'];
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
		if ($this->lockAndFetchStatus() !== self::STATUS_DRAFT) {
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

		$pointsByUser = array();
		foreach ($voters as $userId => $v) {
			$pointsByUser[(int) $userId] = (int) $v->points;
		}
		list($namedVoters, $names) = $this->getGenesisNames($pointsByUser);

		try {
			$ballotSize = $this->computeBallotSize(array_keys($snapshot), $pointsByUser);
			$this->genesis_payload = $this->getGenesisPayload($namedVoters, $snapshot, $names, $ballotSize);
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
	 * Apply the transitions driven by the clock alone, once date_end is
	 * reached: a validated campaign whose creator never voted becomes
	 * EXTINCT, a campaign whose vote was open becomes ENDED. Idempotent; the
	 * UPDATE only matches the expected current status, so two concurrent
	 * calls cannot fight. castVote() does not rely on it: it checks the time
	 * window itself, under the campaign lock.
	 *
	 * @return	int<-1,1>	Return integer <0 if KO, >0 if OK (->status refreshed)
	 */
	public function refreshTimeStatus()
	{
		if (empty($this->id) || empty($this->date_end) || $this->date_end > dol_now()) {
			return 1;
		}

		foreach (array(self::STATUS_VALIDATED => self::STATUS_EXTINCT, self::STATUS_VOTE_OPEN => self::STATUS_ENDED) as $from => $to) {
			$sql = "UPDATE ".$this->db->prefix().$this->table_element;
			$sql .= " SET status = ".((int) $to);
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".((int) $from);
			if (!$this->db->query($sql)) {
				$this->error = 'Error '.$this->db->lasterror();
				return -1;
			}
		}

		$status = $this->fetchStatusFromDb();
		if ($status !== null) {
			$this->status = $status;
		}

		return 1;
	}

	/**
	 * Hash of one vote of the chain: SHA-256 of the canonical JSON
	 * {"ciphertext","prev_hash","seq"} (sorted keys, unescaped slashes).
	 * Anyone can recompute it from the vote file, without any key.
	 *
	 * @param	int		$seq		Rank of the vote in the chain, from 1
	 * @param	string	$prevHash	Hash of the previous vote, or the genesis hash for seq 1
	 * @param	string	$ciphertext	Base64 sealed box of the vote
	 * @return	string				Lowercase hex SHA-256
	 */
	public static function computeVoteHash(int $seq, string $prevHash, string $ciphertext): string
	{
		$json = json_encode(array('ciphertext' => $ciphertext, 'prev_hash' => $prevHash, 'seq' => $seq), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		return hash('sha256', $json);
	}

	/**
	 * Check a whole chain, the way anyone holding the vote file can: the
	 * genesis payload hashes to the genesis hash, seq runs 1..n without gap,
	 * each vote points to the previous hash (the genesis hash for the first)
	 * and hashes to its own recorded hash.
	 *
	 * @param	string								$genesisPayload	Genesis payload, as stored
	 * @param	string								$genesisHash	Genesis hash, as stored
	 * @param	array<array{seq:int,prev_hash:string,ciphertext:string,hash:string}>	$votes	Votes in chain order
	 * @return	string								'' if the chain is intact, else the translated reason
	 */
	public static function verifyChain(string $genesisPayload, string $genesisHash, array $votes): string
	{
		global $langs;

		$langs->load('salonerp@salonerp');

		if (!hash_equals($genesisHash, hash('sha256', $genesisPayload))) {
			return $langs->trans('VoteChainGenesisMismatch');
		}

		$prev = $genesisHash;
		$expectedSeq = 1;
		foreach ($votes as $vote) {
			if ((int) $vote['seq'] !== $expectedSeq) {
				return $langs->trans('VoteChainSeqBroken', $expectedSeq);
			}
			if (!hash_equals($prev, (string) $vote['prev_hash'])) {
				return $langs->trans('VoteChainPrevHashMismatch', $expectedSeq);
			}
			if (!hash_equals(self::computeVoteHash($expectedSeq, $prev, (string) $vote['ciphertext']), (string) $vote['hash'])) {
				return $langs->trans('VoteChainHashMismatch', $expectedSeq);
			}
			$prev = (string) $vote['hash'];
			$expectedSeq++;
		}

		return '';
	}

	/**
	 * The campaign's vote chain, in order, WITHOUT the voters: only what goes
	 * into the vote file.
	 *
	 * @return	array<array{seq:int,prev_hash:string,ciphertext:string,hash:string}>|int<-1,-1>	Votes by seq, or -1 if KO
	 */
	public function fetchVotes()
	{
		$sql = "SELECT seq, prev_hash, ciphertext, hash FROM ".$this->db->prefix()."salonerp_campaign_vote";
		$sql .= " WHERE fk_campaign = ".((int) $this->id);
		$sql .= " ORDER BY seq ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Error '.$this->db->lasterror();
			return -1;
		}

		$votes = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$votes[] = array(
				'seq' => (int) $obj->seq,
				'prev_hash' => (string) $obj->prev_hash,
				'ciphertext' => (string) $obj->ciphertext,
				'hash' => (string) $obj->hash,
			);
		}
		$this->db->free($resql);

		return $votes;
	}

	/**
	 * When a given user voted on this campaign, if they did.
	 *
	 * @param	int		$userId	User id
	 * @return	int|null|false	Timestamp of the vote, null if not voted, false on SQL error
	 */
	public function fetchUserVoteDate($userId)
	{
		$sql = "SELECT date_creation FROM ".$this->db->prefix()."salonerp_campaign_vote";
		$sql .= " WHERE fk_campaign = ".((int) $this->id)." AND fk_user = ".((int) $userId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? (int) $this->db->jdate($obj->date_creation) : null;
	}

	/**
	 * Build the downloadable vote file: the genesis in clear (its exact stored
	 * payload, so its hash can be recomputed, plus a decoded copy to read it),
	 * then every vote of the chain as {seq, prev_hash, ciphertext, hash}.
	 * No voter identity and no date in clear: they are inside each ciphertext,
	 * readable only with the private key at the end of the campaign.
	 *
	 * @return	string|int<-1,-1>	Pretty-printed JSON, or -1 if KO
	 */
	public function buildVoteFile()
	{
		global $langs;

		$langs->load('salonerp@salonerp');
		$votes = $this->fetchVotes();
		if (!is_array($votes)) {
			return -1;
		}

		$file = array(
			'format' => 'salonerp-votes/1',
			'campaign' => (string) $this->ref,
			'generated_at' => gmdate('Y-m-d\TH:i:s\Z', dol_now()),
			'how_to_verify' => array(
				'genesis_hash = sha256(genesis_payload), genesis_payload taken as the exact string below',
				'vote hash = sha256 of the JSON {"ciphertext":...,"prev_hash":...,"seq":...} (keys in this order, no spaces, slashes unescaped)',
				'prev_hash of vote 1 = genesis_hash; prev_hash of vote n = hash of vote n-1',
				'ciphertext = libsodium sealed box (crypto_box_seal) with the campaign public_key, base64; it holds the voter, the date and the points',
			),
			'genesis_hash' => (string) $this->genesis_hash,
			'genesis_payload' => (string) $this->genesis_payload,
			'genesis' => json_decode((string) $this->genesis_payload, true),
			// Readable meaning of genesis.tie_rule. Outside the hashed genesis:
			// this text depends on the reader's language, the code does not.
			'tie_rule_explanation' => $langs->transnoentitiesnoconv('CampaignTieRuleExplanation'),
			'votes' => $votes,
			'last_hash' => empty($votes) ? (string) $this->genesis_hash : $votes[count($votes) - 1]['hash'],
		);

		return json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Explicit, from-scratch construction of a libsodium "sealed box": exactly
	 * what sodium_crypto_box_seal($padded, $publicKey) computes internally,
	 * except that function generates its own ephemeral keypair and throws the
	 * secret half away, while this one takes it as a parameter so the caller
	 * can keep it. Pure and reusable: castVote() calls it to seal a real vote
	 * (a fresh eph_sk every time, see there), and SalonerpVoteFile checks a
	 * voter's receipt by calling it again with the eph_sk out of that receipt
	 * and comparing the result, byte for byte, to the ciphertext recorded in
	 * the chain at the receipt's seq.
	 *
	 * ciphertext = eph_pk || crypto_box(padded, nonce, eph_sk, publicKey)
	 * nonce      = BLAKE2b(eph_pk . publicKey, SODIUM_CRYPTO_BOX_NONCEBYTES)
	 *
	 * This is bit-for-bit what sodium_crypto_box_seal() would have produced
	 * with this eph_sk, and the result is openable by
	 * sodium_crypto_box_seal_open() unchanged: a sealed box only ever opens to
	 * the one plaintext it was built from, so nobody, not even the voter who
	 * holds eph_sk, can later forge a receipt matching a different ballot.
	 *
	 * @param	string	$padded				Padded plaintext to seal (sodium_pad() output)
	 * @param	string	$publicKey			Recipient's raw X25519 public key (32 bytes)
	 * @param	string	$ephemeralSecret	Raw X25519 secret key of a fresh, one-time keypair (32 bytes)
	 * @return	string						Raw sealed box: eph_pk (32 bytes) followed by the box (len($padded) + SODIUM_CRYPTO_BOX_SEALBYTES - 32 bytes)
	 */
	public static function sealBallot(string $padded, string $publicKey, string $ephemeralSecret): string
	{
		$ephemeralPublic = sodium_crypto_box_publickey_from_secretkey($ephemeralSecret);
		$nonce = sodium_crypto_generichash($ephemeralPublic.$publicKey, '', SODIUM_CRYPTO_BOX_NONCEBYTES);
		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($ephemeralSecret, $publicKey);

		$box = sodium_crypto_box($padded, $nonce, $keypair);

		sodium_memzero($keypair);
		sodium_memzero($nonce);

		return $ephemeralPublic.$box;
	}

	/**
	 * Build a voter's receipt right after their vote was sealed: the one and
	 * only proof they can hold of its exact content, since the campaign never
	 * stores eph_sk anywhere (see castVote()). Format 'salonerp-receipt/1'.
	 * Pure: everything it needs is passed in, nothing is read from the
	 * database.
	 *
	 * Anyone holding this receipt and a vote file of the campaign can, without
	 * any campaign key, pad 'ballot' to the file's genesis ballot_size, reseal
	 * it with sealBallot() and 'ephemeral_key', and compare the result to the
	 * ciphertext of vote #seq in the file: see SalonerpVoteFile's receipt
	 * check. A sealed box opens to a single plaintext, so a match is
	 * conclusive proof of what was cast, and nothing else can ever be made to
	 * match it.
	 *
	 * @param	string	$ref				Campaign ref
	 * @param	string	$genesisPayload		Campaign's stored genesis payload (used only to name voters/thirdparties for 'ballot_decoded')
	 * @param	string	$genesisHash		Campaign's stored genesis hash
	 * @param	int		$seq				Rank of the vote in the chain
	 * @param	string	$hash				Chain hash of the vote (Campaign::computeVoteHash())
	 * @param	string	$ballotPlaintext	Exact, unpadded JSON of the ballot (Campaign::buildBallotPlaintext())
	 * @param	string	$ephemeralSecret	Raw X25519 secret key of the fresh, one-time keypair used to seal this vote (32 bytes)
	 * @return	string						Pretty-printed JSON receipt
	 */
	public static function buildReceipt(string $ref, string $genesisPayload, string $genesisHash, int $seq, string $hash, string $ballotPlaintext, string $ephemeralSecret): string
	{
		global $langs;

		$langs->load('salonerp@salonerp');

		$genesis = json_decode($genesisPayload, true);
		$ballotArray = json_decode($ballotPlaintext, true);
		$decoded = is_array($ballotArray) ? self::describeBallot($ballotArray, is_array($genesis) ? $genesis : null) : array();

		$receipt = array(
			'format' => 'salonerp-receipt/1',
			'campaign' => $ref,
			'genesis_hash' => $genesisHash,
			'seq' => $seq,
			'hash' => $hash,
			'ballot' => $ballotPlaintext,
			'ephemeral_key' => base64_encode($ephemeralSecret),
			'how_to_verify' => array(
				$langs->transnoentitiesnoconv('ReceiptHowToVerify1'),
				$langs->transnoentitiesnoconv('ReceiptHowToVerify2'),
				$langs->transnoentitiesnoconv('ReceiptHowToVerify3'),
				$langs->transnoentitiesnoconv('ReceiptHowToVerify4'),
			),
			'ballot_decoded' => $decoded,
		);

		return json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Cast a vote: seal it with the campaign public key and append it to the
	 * chain. Runs under the campaign lock (see lockAndFetchStatus()), so the
	 * checks below and the append are atomic, and two votes can never compete
	 * for the same rank.
	 *
	 * Refuses, with translated messages in ->errors, if:
	 *  - the campaign is neither validated nor open (reread under lock);
	 *  - now is outside [date_start, date_end[;
	 *  - the user is not a voter of the campaign, or has already voted;
	 *  - the vote is not open yet and the user is not the creator, or the
	 *    creator's private key does not match the public key;
	 *  - a thirdparty is not in the frozen list, points are negative, or
	 *    the total is not exactly the voter's envelope.
	 *
	 * The first valid vote (the creator's) opens the campaign to the others.
	 *
	 * On success, $receiptOut is filled with the voter's receipt
	 * (Campaign::buildReceipt()): their sole proof of this vote's exact
	 * content, since the ephemeral secret key used to seal it (eph_sk) is
	 * generated fresh here and NEVER persisted anywhere (no column carries
	 * it, no session, no log, no file) — exactly like validate() with the
	 * campaign's private key, it exists only in this call's locals and in
	 * $receiptOut, until the caller shows it to the voter once and it is
	 * wiped. $receiptOut is left untouched (whatever the caller passed) on
	 * every refused path.
	 *
	 * @param	User				$user			Voting user
	 * @param	array<int|string,int|string>	$allocations	Points by thirdparty id
	 * @param	string				$privateKeyB64	Creator's private key, required for the first vote only
	 * @param	?string				$receiptOut		Out: JSON receipt on success (Campaign::buildReceipt()), untouched on failure
	 * @return	int								Rank of the vote in the chain (>0), or -1 if KO
	 */
	public function castVote(User $user, array $allocations, string $privateKeyB64 = '', ?string &$receiptOut = null)
	{
		global $langs;

		$langs->load('salonerp@salonerp');
		dol_include_once('/salonerp/class/campaignvoter.class.php');
		$this->errors = array();

		$this->db->begin();

		$status = $this->lockAndFetchStatus();
		if ($status !== self::STATUS_VALIDATED && $status !== self::STATUS_VOTE_OPEN) {
			$this->errors[] = $langs->trans('ErrorCampaignVoteNotOpen');
			$this->db->rollback();
			return -1;
		}

		// No compatibility: a genesis frozen before ballot_size existed cannot
		// be padded to a size it never fixed, so voting on it is refused
		// outright, same as reveal() and SalonerpVoteFile::analyse().
		$ballotSize = $this->genesisBallotSize();
		if ($ballotSize === null) {
			$this->errors[] = $langs->trans('ErrorCampaignGenesisMissingBallotSize');
			$this->db->rollback();
			return -1;
		}

		$now = dol_now();
		if ($now < (int) $this->date_start || $now >= (int) $this->date_end) {
			$this->errors[] = $langs->trans('ErrorCampaignVoteOutsideWindow', dol_print_date($this->date_start, 'dayhour', 'tzuserrel'), dol_print_date($this->date_end, 'dayhour', 'tzuserrel'));
			$this->db->rollback();
			return -1;
		}

		$voterObj = new CampaignVoter($this->db);
		$voters = $voterObj->fetchByCampaign($this->id);
		if (!is_array($voters) || !isset($voters[(int) $user->id])) {
			$this->errors[] = $langs->trans('ErrorCampaignNotAVoter');
			$this->db->rollback();
			return -1;
		}
		$envelope = (int) $voters[(int) $user->id]->points;

		$votedAt = $this->fetchUserVoteDate($user->id);
		if ($votedAt !== null) {
			$this->errors[] = ($votedAt === false) ? $this->db->lasterror() : $langs->trans('ErrorCampaignAlreadyVoted');
			$this->db->rollback();
			return -1;
		}

		if ($status === self::STATUS_VALIDATED) {
			if ((int) $user->id !== (int) $this->fk_user_creat) {
				$this->errors[] = $langs->trans('ErrorCampaignCreatorMustVoteFirst');
				$this->db->rollback();
				return -1;
			}
			if (!self::checkPrivateKey($privateKeyB64, (string) $this->public_key)) {
				$this->errors[] = $langs->trans('ErrorCampaignPrivateKeyMismatch');
				$this->db->rollback();
				return -1;
			}
		}

		// Points: only thirdparties of the frozen list, integers >= 0, total
		// exactly the envelope. Zero allocations are dropped from the ballot.
		$frozenIds = $this->fetchThirdpartyIds();
		if (!is_array($frozenIds)) {
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}
		$ballot = array();
		$total = 0;
		foreach ($allocations as $socId => $points) {
			if (!in_array((int) $socId, $frozenIds, true)) {
				$this->errors[] = $langs->trans('ErrorCampaignVoteUnknownThirdparty', (int) $socId);
				continue;
			}
			if (!preg_match('/^\d+$/', trim((string) $points))) {
				$this->errors[] = $langs->trans('ErrorCampaignVoteInvalidPoints');
				continue;
			}
			if ((int) $points > 0) {
				$ballot[(int) $socId] = (int) $points;
				$total += (int) $points;
			}
		}
		if (empty($this->errors) && $total !== $envelope) {
			$this->errors[] = $langs->trans('ErrorCampaignVoteTotalMismatch', $total, $envelope);
		}
		if (!empty($this->errors)) {
			$this->db->rollback();
			return -1;
		}
		ksort($ballot);

		$sql = "SELECT seq, hash FROM ".$this->db->prefix()."salonerp_campaign_vote";
		$sql .= " WHERE fk_campaign = ".((int) $this->id)." ORDER BY seq DESC";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$last = $this->db->fetch_object($resql);
		$this->db->free($resql);
		$seq = $last ? (int) $last->seq + 1 : 1;
		$prevHash = $last ? (string) $last->hash : (string) $this->genesis_hash;

		$pairs = array();
		foreach ($ballot as $socId => $points) {
			$pairs[] = array($socId, $points);
		}
		$plaintext = self::buildBallotPlaintext((string) $this->ref, (int) $now, (int) $user->id, $pairs, $seq);
		// Guard, never expected to trigger: computeBallotSize() at validation
		// time is a true majorant of every ballot this method can ever build.
		if (strlen($plaintext) >= $ballotSize) {
			$this->errors[] = $langs->trans('ErrorCampaignBallotExceedsSize');
			sodium_memzero($plaintext);
			$this->db->rollback();
			return -1;
		}
		// sodium_pad() with blockSize = $ballotSize pads to the next multiple
		// of $ballotSize strictly above strlen($plaintext): since that length
		// is < $ballotSize, the result is always exactly $ballotSize bytes,
		// whatever the ballot. That is what hides its shape in the ciphertext.
		$padded = sodium_pad($plaintext, $ballotSize);
		$publicKey = (string) base64_decode((string) $this->public_key, true);

		// A fresh, one-time keypair for this vote alone. Its secret half
		// (eph_sk) is what lets sealBallot() be reconstructed and checked
		// later against a receipt (see SalonerpVoteFile): it is NEVER
		// persisted (no column of salonerp_campaign_vote carries it, no
		// session, no log, no file), only ever kept in the locals of this
		// call, until it is handed once to the voter below and wiped.
		$ephKeypair = sodium_crypto_box_keypair();
		$ephemeralSecret = sodium_crypto_box_secretkey($ephKeypair);
		sodium_memzero($ephKeypair);

		$ciphertextRaw = self::sealBallot($padded, $publicKey, $ephemeralSecret);
		sodium_memzero($padded);
		$ciphertext = base64_encode($ciphertextRaw);
		sodium_memzero($ciphertextRaw);
		$hash = self::computeVoteHash($seq, $prevHash, $ciphertext);

		$sql = "INSERT INTO ".$this->db->prefix()."salonerp_campaign_vote";
		$sql .= " (fk_campaign, seq, fk_user, ciphertext, prev_hash, hash, date_creation) VALUES (";
		$sql .= ((int) $this->id).", ".((int) $seq).", ".((int) $user->id);
		$sql .= ", '".$this->db->escape($ciphertext)."', '".$this->db->escape($prevHash)."', '".$this->db->escape($hash)."'";
		$sql .= ", '".$this->db->idate($now)."')";
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			sodium_memzero($plaintext);
			sodium_memzero($ephemeralSecret);
			$this->db->rollback();
			return -1;
		}

		if ($status === self::STATUS_VALIDATED) {
			$sql = "UPDATE ".$this->db->prefix().$this->table_element;
			$sql .= " SET status = ".self::STATUS_VOTE_OPEN;
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".self::STATUS_VALIDATED;
			if (!$this->db->query($sql)) {
				$this->errors[] = $this->db->lasterror();
				sodium_memzero($plaintext);
				sodium_memzero($ephemeralSecret);
				$this->db->rollback();
				return -1;
			}
			$this->status = self::STATUS_VOTE_OPEN;
		}

		$this->db->commit();

		// Built and filled in only on this successful path, exactly once:
		// like validate()'s private key, the receipt (and the eph_sk it
		// carries) only ever exists in this response and the voter's browser
		// from here on. Never re-derivable afterwards: nothing above kept it.
		$receiptOut = self::buildReceipt((string) $this->ref, (string) $this->genesis_payload, (string) $this->genesis_hash, $seq, $hash, $plaintext, $ephemeralSecret);

		sodium_memzero($plaintext);
		sodium_memzero($ephemeralSecret);

		return $seq;
	}

	/**
	 * Check a voter's receipt against THIS campaign's own official chain: the
	 * vote file is built here, server-side (buildVoteFile()), instead of
	 * being supplied by the caller. This is what lets "Contester un vote"
	 * (the Decrypt tab) verify a receipt on its own, with nothing but the
	 * receipt itself — no vote file to hunt for or trust beforehand.
	 *
	 * The reference here is this Dolibarr instance: a match only proves the
	 * receipt agrees with what THIS server currently serves as its official
	 * chain. For a check that does not have to trust this server at all,
	 * the receipt must instead be checked against an independently-obtained
	 * vote file, e.g. on the External decryption page of another instance
	 * (see SalonerpVoteFile::analyse() directly).
	 *
	 * @param	string	$receiptJson	Receipt content to check (Campaign::buildReceipt())
	 * @return	?array<string,mixed>	SalonerpVoteFile::analyse()'s 'receipt' entry, or null if the official file itself could not be built (fetchVotes() SQL error)
	 */
	public function checkReceiptAgainstOfficialChain(string $receiptJson): ?array
	{
		dol_include_once('/salonerp/class/salonerpvotefile.class.php');

		$officialFile = $this->buildVoteFile();
		if (!is_string($officialFile)) {
			return null;
		}
		$official = $this->fetchVotes();
		$expected = array('ref' => (string) $this->ref, 'genesis_hash' => (string) $this->genesis_hash);

		$report = SalonerpVoteFile::analyse($officialFile, '', $expected, is_array($official) ? $official : array(), $receiptJson);

		return $report['receipt'];
	}

	/**
	 * Count the votes: for each thirdparty of the frozen list, the voter who
	 * put the most points on it wins. Pure function, no database access, so
	 * anyone can check it from the revealed ballots.
	 *
	 * Ties, rule B_E fixed in the genesis:
	 *  - B: the tied voter who gave it the largest share of their own envelope
	 *    (points / envelope) wins, compared exactly as a1*e2 vs a2*e1;
	 *  - E: if still tied, verifiable draw: each remaining voter gets
	 *    sha256(lastHash . ':' . thirdparty id . ':' . voter id), the smallest
	 *    hash (hex string order) wins. lastHash is the hash of the last vote of
	 *    the chain: it did not exist before the vote closed.
	 * A thirdparty that got no point at all goes to nobody (rule 'none').
	 *
	 * @param	array<array{fk_user:int,points:array<array{0:int,1:int}>}>	$ballots		Decrypted ballots
	 * @param	array<int,int>		$envelopes		Voters' envelopes, keyed by user id
	 * @param	int[]				$thirdpartyIds	The frozen thirdparty list
	 * @param	string				$lastHash		Hash of the last vote of the chain
	 * @return	array<int,array{fk_user:?int,points:int,rule:string,detail:array<string,mixed>}>	Keyed by thirdparty id, sorted
	 */
	public static function computeResults(array $ballots, array $envelopes, array $thirdpartyIds, string $lastHash): array
	{
		$bids = array();
		foreach ($ballots as $ballot) {
			foreach ($ballot['points'] as $pair) {
				if ((int) $pair[1] > 0) {
					$bids[(int) $pair[0]][(int) $ballot['fk_user']] = (int) $pair[1];
				}
			}
		}

		$results = array();
		$ids = array_map('intval', $thirdpartyIds);
		sort($ids);
		foreach ($ids as $socId) {
			$candidates = isset($bids[$socId]) ? $bids[$socId] : array();
			ksort($candidates);
			$detail = array('bids' => array());
			foreach ($candidates as $userId => $points) {
				$detail['bids'][] = array('fk_user' => $userId, 'points' => $points, 'envelope' => (int) $envelopes[$userId]);
			}
			if (empty($candidates)) {
				$results[$socId] = array('fk_user' => null, 'points' => 0, 'rule' => 'none', 'detail' => $detail);
				continue;
			}

			$max = max($candidates);
			$tied = array_keys(array_filter($candidates, function ($p) use ($max) {
				return $p === $max;
			}));
			if (count($tied) === 1) {
				$results[$socId] = array('fk_user' => $tied[0], 'points' => $max, 'rule' => 'max', 'detail' => $detail);
				continue;
			}

			// B: largest share of own envelope, exact integer comparison.
			$best = array();
			foreach ($tied as $userId) {
				if (empty($best)) {
					$best = array($userId);
					continue;
				}
				$ref = $best[0];
				$cmp = ($candidates[$userId] * (int) $envelopes[$ref]) <=> ($candidates[$ref] * (int) $envelopes[$userId]);
				if ($cmp > 0) {
					$best = array($userId);
				} elseif ($cmp === 0) {
					$best[] = $userId;
				}
			}
			$detail['tied'] = $tied;
			if (count($best) === 1) {
				$results[$socId] = array('fk_user' => $best[0], 'points' => $max, 'rule' => 'B', 'detail' => $detail);
				continue;
			}

			// E: verifiable draw among the voters still tied after B.
			$draw = array();
			foreach ($best as $userId) {
				$draw[$userId] = hash('sha256', $lastHash.':'.$socId.':'.$userId);
			}
			asort($draw, SORT_STRING);
			$detail['draw'] = array('seed' => $lastHash, 'hashes' => $draw);
			$results[$socId] = array('fk_user' => (int) array_key_first($draw), 'points' => $max, 'rule' => 'E', 'detail' => $detail);
		}

		return $results;
	}

	/**
	 * Open every sealed ballot of the chain with a private key, and check each
	 * one against its database row: same rank, same voter, same campaign.
	 * Refuses outright if the stored genesis carries no ballot_size (a
	 * non-compliant genesis: no compatibility). Otherwise, after
	 * sealed_box_open(), the plaintext's length must be exactly ballot_size
	 * and sodium_unpad() must succeed: any other outcome is an anomaly, not a
	 * key mismatch, and is refused the same way as an unreadable ballot.
	 *
	 * @param	string	$privateKeyB64	Base64 private key of the campaign
	 * @return	array<array{seq:int,fk_user:int,date:string,points:array<array{0:int,1:int}>}>|int<-1,-1>	Ballots by seq, or -1 (reason in ->error)
	 */
	protected function openBallots(string $privateKeyB64)
	{
		global $langs;

		$ballotSize = $this->genesisBallotSize();
		if ($ballotSize === null) {
			$this->error = $langs->trans('ErrorCampaignGenesisMissingBallotSize');
			return -1;
		}

		$sql = "SELECT seq, fk_user, ciphertext FROM ".$this->db->prefix()."salonerp_campaign_vote";
		$sql .= " WHERE fk_campaign = ".((int) $this->id)." ORDER BY seq ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey((string) base64_decode($privateKeyB64, true), (string) base64_decode((string) $this->public_key, true));
		$ballots = array();
		$error = '';
		while ($obj = $this->db->fetch_object($resql)) {
			$padded = sodium_crypto_box_seal_open((string) base64_decode((string) $obj->ciphertext, true), $keypair);
			$plain = false;
			$wrongSize = false;
			if ($padded !== false) {
				if (strlen($padded) !== $ballotSize) {
					$wrongSize = true;
				} else {
					try {
						$plain = sodium_unpad($padded, $ballotSize);
					} catch (\SodiumException $e) {
						$wrongSize = true;
					}
				}
			}
			$ballot = ($plain === false) ? null : json_decode($plain, true);
			if (!is_array($ballot)) {
				$error = $wrongSize
					? $langs->trans('ErrorCampaignBallotWrongSize', (int) $obj->seq)
					: $langs->trans('ErrorCampaignBallotUnreadable', (int) $obj->seq);
				break;
			}
			if ((int) $ballot['seq'] !== (int) $obj->seq || (int) $ballot['fk_user'] !== (int) $obj->fk_user || (string) $ballot['campaign'] !== (string) $this->ref) {
				$error = $langs->trans('ErrorCampaignBallotMismatch', (int) $obj->seq);
				break;
			}
			$ballots[] = array('seq' => (int) $obj->seq, 'fk_user' => (int) $ballot['fk_user'], 'date' => (string) $ballot['date'], 'points' => $ballot['points']);
		}
		$this->db->free($resql);
		sodium_memzero($keypair);

		if ($error !== '') {
			$this->error = $error;
			return -1;
		}

		return $ballots;
	}

	/**
	 * The revealed ballots, opened with the stored private key. Only once the
	 * campaign is revealed: before that no key is stored.
	 *
	 * @return	array<array{seq:int,fk_user:int,date:string,points:array<array{0:int,1:int}>}>|int<-1,-1>	Ballots by seq, or -1
	 */
	public function fetchBallots()
	{
		if ((int) $this->status !== self::STATUS_REVEALED || empty($this->private_key)) {
			return -1;
		}

		return $this->openBallots((string) $this->private_key);
	}

	/**
	 * The frozen results of the count.
	 *
	 * @return	array<int,array{fk_user:?int,points:int,rule:string,detail:array<string,mixed>}>|int<-1,-1>	Keyed by thirdparty id, or -1
	 */
	public function fetchResults()
	{
		$sql = "SELECT fk_soc, fk_user, points, rule, detail FROM ".$this->db->prefix()."salonerp_campaign_result";
		$sql .= " WHERE fk_campaign = ".((int) $this->id)." ORDER BY fk_soc ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$results = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$results[(int) $obj->fk_soc] = array(
				'fk_user' => $obj->fk_user === null ? null : (int) $obj->fk_user,
				'points' => (int) $obj->points,
				'rule' => (string) $obj->rule,
				'detail' => (array) json_decode((string) $obj->detail, true),
			);
		}
		$this->db->free($resql);

		return $results;
	}

	/**
	 * Reveal the votes, after date_end, by the creator re-entering the private
	 * key. Under the campaign lock: the key is checked, then the WHOLE chain is
	 * verified from the genesis before anything is decrypted, then every
	 * ballot is opened and checked against its row. Only if all of this holds
	 * are the results counted and frozen, and the key stored (it is public from
	 * then on). Any failure leaves the campaign untouched.
	 *
	 * @param	User	$user			The campaign creator
	 * @param	string	$privateKeyB64	Base64 private key
	 * @return	int<-1,1>				Return integer <0 if KO (translated messages in ->errors), >0 if OK
	 */
	public function reveal(User $user, string $privateKeyB64)
	{
		global $langs;

		$langs->load('salonerp@salonerp');
		dol_include_once('/salonerp/class/campaignvoter.class.php');
		$this->errors = array();

		$this->db->begin();

		$status = $this->lockAndFetchStatus();
		if ($status === self::STATUS_REVEALED) {
			$this->errors[] = $langs->trans('ErrorCampaignAlreadyRevealed');
		} elseif ($status !== self::STATUS_ENDED) {
			$this->errors[] = $langs->trans('ErrorCampaignRevealNotEnded');
		} elseif ((int) $user->id !== (int) $this->fk_user_creat) {
			$this->errors[] = $langs->trans('ErrorCampaignOnlyCreatorCanReveal');
		} elseif (!self::checkPrivateKey($privateKeyB64, (string) $this->public_key)) {
			$this->errors[] = $langs->trans('ErrorCampaignPrivateKeyMismatch');
		}
		if (!empty($this->errors)) {
			$this->db->rollback();
			return -1;
		}

		$votes = $this->fetchVotes();
		$chainError = is_array($votes) ? self::verifyChain((string) $this->genesis_payload, (string) $this->genesis_hash, $votes) : $this->error;
		if ($chainError !== '') {
			$this->errors[] = $langs->trans('ErrorCampaignChainBroken', $chainError);
			$this->db->rollback();
			return -1;
		}

		$ballots = $this->openBallots($privateKeyB64);
		if (!is_array($ballots)) {
			$this->errors[] = $this->error;
			$this->db->rollback();
			return -1;
		}

		$voterObj = new CampaignVoter($this->db);
		$envelopes = array();
		foreach ((array) $voterObj->fetchByCampaign($this->id) as $userId => $v) {
			$envelopes[(int) $userId] = (int) $v->points;
		}
		$lastHash = empty($votes) ? (string) $this->genesis_hash : $votes[count($votes) - 1]['hash'];
		$results = self::computeResults($ballots, $envelopes, (array) $this->fetchThirdpartyIds(), $lastHash);

		foreach ($results as $socId => $r) {
			$sql = "INSERT INTO ".$this->db->prefix()."salonerp_campaign_result (fk_campaign, fk_soc, fk_user, points, rule, detail) VALUES (";
			$sql .= ((int) $this->id).", ".((int) $socId).", ".($r['fk_user'] === null ? "NULL" : (int) $r['fk_user']);
			$sql .= ", ".((int) $r['points']).", '".$this->db->escape($r['rule'])."'";
			$sql .= ", '".$this->db->escape(json_encode($r['detail'], JSON_UNESCAPED_SLASHES))."')";
			if (!$this->db->query($sql)) {
				$this->errors[] = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$now = dol_now();
		$sql = "UPDATE ".$this->db->prefix().$this->table_element;
		$sql .= " SET status = ".self::STATUS_REVEALED;
		$sql .= ", private_key = '".$this->db->escape($privateKeyB64)."'";
		$sql .= ", date_reveal = '".$this->db->idate($now)."'";
		$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".self::STATUS_ENDED;
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		$this->status = self::STATUS_REVEALED;
		$this->private_key = $privateKeyB64;
		$this->date_reveal = $now;

		return 1;
	}

	/**
	 * Add each thirdparty's winner as one of its sales representatives, once,
	 * by the creator, after the reveal. Nobody is removed: the existing
	 * representatives stay. Thirdparties deleted since the validation are
	 * skipped. All-or-nothing.
	 *
	 * @param	User	$user	The campaign creator
	 * @return	array{added:array<int,int>,already:array<int,int>,skipped:array<int,int>}|int<-1,-1>	Report (thirdparty id => winner id), or -1 (translated messages in ->errors)
	 */
	public function attributeSalesReps(User $user)
	{
		global $langs;

		$langs->load('salonerp@salonerp');
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$this->errors = array();

		$this->db->begin();

		if ($this->lockAndFetchStatus() !== self::STATUS_REVEALED) {
			$this->errors[] = $langs->trans('ErrorCampaignNotRevealed');
		} elseif ((int) $user->id !== (int) $this->fk_user_creat) {
			$this->errors[] = $langs->trans('ErrorCampaignOnlyCreatorCanAttribute');
		} else {
			$sql = "SELECT date_attribution FROM ".$this->db->prefix().$this->table_element." WHERE rowid = ".((int) $this->id);
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if (!$obj || !empty($obj->date_attribution)) {
				$this->errors[] = $langs->trans('ErrorCampaignAlreadyAttributed');
			}
		}
		$results = empty($this->errors) ? $this->fetchResults() : array();
		if (!is_array($results)) {
			$this->errors[] = $this->error;
		}
		if (!empty($this->errors)) {
			$this->db->rollback();
			return -1;
		}

		$report = array('added' => array(), 'already' => array(), 'skipped' => array());
		foreach ($results as $socId => $r) {
			if ($r['fk_user'] === null) {
				continue;
			}
			$soc = new Societe($this->db);
			if ($soc->fetch($socId) <= 0) {
				$report['skipped'][$socId] = $r['fk_user'];
				continue;
			}
			$sql = "SELECT COUNT(*) as nb FROM ".$this->db->prefix()."societe_commerciaux";
			$sql .= " WHERE fk_soc = ".((int) $socId)." AND fk_user = ".((int) $r['fk_user']);
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if ($obj && (int) $obj->nb > 0) {
				$report['already'][$socId] = $r['fk_user'];
				continue;
			}
			if ($soc->add_commercial($user, $r['fk_user']) < 0) {
				$this->errors[] = $soc->error;
				$this->db->rollback();
				return -1;
			}
			$report['added'][$socId] = $r['fk_user'];
		}

		$now = dol_now();
		$sql = "UPDATE ".$this->db->prefix().$this->table_element;
		$sql .= " SET date_attribution = '".$this->db->idate($now)."', fk_user_attribution = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		$this->date_attribution = $now;
		$this->fk_user_attribution = (int) $user->id;

		return $report;
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
			foreach (array(
				self::STATUS_DRAFT => 'Draft',
				self::STATUS_VALIDATED => 'Validated',
				self::STATUS_VOTE_OPEN => 'CampaignStatusVoteOpen',
				self::STATUS_ENDED => 'CampaignStatusEnded',
				self::STATUS_REVEALED => 'CampaignStatusRevealed',
				self::STATUS_EXTINCT => 'CampaignStatusExtinct',
			) as $code => $key) {
				$this->labelStatus[$code] = $langs->transnoentitiesnoconv($key);
				$this->labelStatusShort[$code] = $langs->transnoentitiesnoconv($key);
			}
		}

		$statusTypes = array(
			self::STATUS_DRAFT => 'status0',
			self::STATUS_VALIDATED => 'status1',
			self::STATUS_VOTE_OPEN => 'status4',
			self::STATUS_ENDED => 'status6',
			self::STATUS_REVEALED => 'status4',
			self::STATUS_EXTINCT => 'status9',
		);
		$statusType = isset($statusTypes[$status]) ? $statusTypes[$status] : 'status'.$status;
		if (!isset($this->labelStatus[$status])) {
			return '';
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

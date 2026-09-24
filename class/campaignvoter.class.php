<?php
/* Copyright (C) 2026 Solauv
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
 */

/**
 * \file        class/campaignvoter.class.php
 * \ingroup     salonerp
 * \brief       Light CRUD class for CampaignVoter: a user and the point budget
 *              allocated to them for one campaign. No dedicated screen: it is
 *              only ever managed from the Campaign card (voters block).
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for CampaignVoter
 */
class CampaignVoter extends CommonObject
{
	/**
	 * @var string 		ID of module.
	 */
	public $module = 'salonerp';

	/**
	 * @var string 		ID to identify managed object.
	 */
	public $element = 'campaignvoter';

	/**
	 * @var string 		Name of table without prefix where object is stored.
	 */
	public $table_element = 'salonerp_campaign_voter';

	/**
	 * @var int<0,1>	Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var int<0,1>|string		Does this object support multicompany module ?
	 */
	public $ismultientitymanaged = 0;

	/**
	 * @inheritdoc
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "TechnicalID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "index" => 1),
		"fk_campaign" => array("type" => "integer", "label" => "Campaign", "enabled" => "1", 'position' => 10, 'notnull' => 1, "visible" => "0", "index" => 1),
		"fk_user" => array("type" => "integer:User:user/class/user.class.php", "label" => "User", "enabled" => "1", 'position' => 20, 'notnull' => 1, "visible" => "1", "index" => 1),
		"points" => array("type" => "integer", "label" => "Points", "enabled" => "1", 'position' => 30, 'notnull' => 1, "visible" => "1"),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "-2"),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2"),
	);
	/** @var int */
	public $rowid;
	/** @var int */
	public $fk_campaign;
	/** @var int */
	public $fk_user;
	/** @var int */
	public $points;
	public $date_creation;
	public $tms;

	/**
	 * Constructor
	 *
	 * @param	DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Check that points is a strictly positive integer. The column is a SQL
	 * integer and every caller passes an int (GETPOSTINT() on the card, or a
	 * PHP int computed in Campaign::syncVotersFromGroup()), so a "not an
	 * integer" branch here would be unreachable: only the positivity check is
	 * meaningful.
	 *
	 * @return	bool	True if points is valid
	 */
	protected function hasValidPoints()
	{
		return ((int) $this->points) > 0;
	}

	/**
	 * Read the parent campaign's status straight from the database: never
	 * trust a flag passed by the caller or a property on this object, since
	 * CampaignVoter carries no status of its own.
	 *
	 * @param	int	$fkCampaign	Id of the parent campaign
	 * @return	bool			True if the parent campaign is currently a draft
	 */
	protected function parentCampaignIsDraft($fkCampaign)
	{
		if (empty($fkCampaign)) {
			return false;
		}

		$sql = "SELECT status FROM ".$this->db->prefix()."salonerp_campaign";
		$sql .= " WHERE rowid = ".((int) $fkCampaign);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		// Campaign::STATUS_DRAFT is 0: not referencing the Campaign class here
		// avoids a require_once cycle between the two class files.
		return $obj && (int) $obj->status === 0;
	}

	/**
	 * Create object into database. Refused if the parent campaign is not
	 * (still) a draft, status reread from the database.
	 *
	 * @param	User		$user		User that creates
	 * @param	int<0,1> 	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,max>				Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 1)
	{
		if (empty($this->fk_campaign) || empty($this->fk_user)) {
			$this->error = 'ErrorCampaignVoterMissingKeys';
			return -1;
		}
		if (!$this->parentCampaignIsDraft($this->fk_campaign)) {
			$this->error = 'ErrorCampaignNotDraftCannotBeModified';
			return -1;
		}
		if (!$this->hasValidPoints()) {
			$this->error = 'ErrorCampaignVoterPointsNotPositive';
			return -1;
		}

		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param	int    		$id   	Id object
	 * @param	?string		$ref	Unused
	 * @return	int<-1,1>			Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id);
	}

	/**
	 * Update object into database. Refused if the parent campaign is not
	 * (still) a draft, status reread from the database.
	 *
	 * @param	User		$user		User that modifies
	 * @param	int<0,1>	$notrigger	0=launch triggers after, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 1)
	{
		if (!$this->parentCampaignIsDraft($this->fk_campaign)) {
			$this->error = 'ErrorCampaignNotDraftCannotBeModified';
			return -1;
		}
		if (!$this->hasValidPoints()) {
			$this->error = 'ErrorCampaignVoterPointsNotPositive';
			return -1;
		}

		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object in database. Refused if the parent campaign is not (still)
	 * a draft, status reread from the database.
	 *
	 * @param	User		$user		User that deletes
	 * @param	int<0,1> 	$notrigger	0=launch triggers, 1=disable triggers
	 * @return	int<-1,1>				Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 1)
	{
		if (!$this->parentCampaignIsDraft($this->fk_campaign)) {
			$this->error = 'ErrorCampaignNotDraftCannotBeModified';
			return -1;
		}

		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Fetch every voter of a campaign, keyed by fk_user.
	 *
	 * @param	int	$campaignId	Id of the campaign
	 * @return	array<int,self>|int<-1,-1>	Voters keyed by fk_user, or <0 if KO
	 */
	public function fetchByCampaign($campaignId)
	{
		$records = array();

		$sql = "SELECT ".$this->getFieldList('t');
		$sql .= " FROM ".$this->db->prefix().$this->table_element." as t";
		$sql .= " WHERE t.fk_campaign = ".((int) $campaignId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$record = new self($this->db);
			$record->setVarsFromFetchObj($obj);
			$records[(int) $record->fk_user] = $record;
		}
		$this->db->free($resql);

		return $records;
	}

	/**
	 * Delete every voter of a campaign. Used when deleting a draft campaign
	 * (the SQL foreign key already cascades, this is belt-and-braces so the
	 * caller gets a clean error to report instead of relying only on the DB).
	 *
	 * @param	int	$campaignId	Id of the campaign
	 * @return	int<-1,1>	Return integer <0 if KO, >0 if OK
	 */
	public function deleteByCampaign($campaignId)
	{
		$sql = "DELETE FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE fk_campaign = ".((int) $campaignId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		return 1;
	}
}

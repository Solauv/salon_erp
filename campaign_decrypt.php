<?php
/* Copyright (C) 2007-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2024-2025  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		MDW						<mdeweerd@users.noreply.github.com>
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
 * Oeuvre derivee de htdocs/recruitment/recruitmentjobposition_note.php
 * de Dolibarr ERP/CRM (version 24.0.1).
 */

/**
 *  \file       campaign_decrypt.php
 *  \ingroup    salonerp
 *  \brief      Decrypt tab of a Campaign: check a vote file against the official chain, decrypt it once revealed
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
dol_include_once('/salonerp/class/campaign.class.php');
dol_include_once('/salonerp/class/salonerpvotefile.class.php');
dol_include_once('/salonerp/lib/salonerp_campaign.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("salonerp@salonerp"));

// Get parameters
$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');

// Initialize technical objects
$object = new Campaign($db);
$hookmanager->initHooks(array('campaigndecrypt', 'globalcard'));

if ($id > 0 || !empty($ref)) {
	$object->fetch($id, $ref);
	$object->refreshTimeStatus();
}

$permissiontoread = $user->hasRight('salonerp', 'campaign', 'read');

if (!isModEnabled('salonerp')) {
	accessforbidden("Module salonerp not enabled");
}
if ($user->socid > 0) {
	accessforbidden();
}
if (!$permissiontoread) {
	accessforbidden();
}
// No table/objectid passed to restrictedArea(): the generic fallback would
// otherwise look for a non-existent llx_salonerp table.
restrictedArea($user, 'salonerp', 0, '', 'campaign');
if ($object->id > 0 && !in_array((int) $object->entity, array_map('intval', explode(',', (string) getEntity('campaign'))), true)) {
	accessforbidden();
}
if ($object->id <= 0 || (int) $object->status === Campaign::STATUS_DRAFT) {
	accessforbidden();
}


/*
 * Actions
 */

$report = null;
$reshook = $hookmanager->executeHooks('doActions', array(), $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}
if (empty($reshook) && $action == 'analyse' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken()) {
	$content = salonerpReadUploadedVoteFile();
	if ($content === false) {
		setEventMessages($langs->trans('ErrorVoteFileMissing'), null, 'errors');
	} else {
		$official = $object->fetchVotes();
		// Decryption only once revealed: before, no key is stored.
		$key = ((int) $object->status === Campaign::STATUS_REVEALED) ? (string) $object->private_key : '';
		$report = SalonerpVoteFile::analyse($content, $key, array('ref' => (string) $object->ref, 'genesis_hash' => (string) $object->genesis_hash), is_array($official) ? $official : array());
	}
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('Campaign').' - '.$langs->trans('VoteFileDecryptTab');
llxHeader('', $title, '');

$head = campaignPrepareHead($object);
print dol_get_fiche_head($head, 'decrypt', $langs->trans("Campaign"), -1, $object->picto);

$linkback = '<a href="'.dol_buildpath('/salonerp/campaign_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

$revealed = ((int) $object->status === Campaign::STATUS_REVEALED);
print '<p>'.$langs->trans($revealed ? 'VoteFileDecryptTabTextRevealed' : 'VoteFileDecryptTabText').'</p>';

print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="analyse">';
print '<input type="hidden" name="id" value="'.$object->id.'">';
print '<input type="file" name="votefile" accept=".json,application/json"> ';
print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans($revealed ? 'VoteFileCheckAndDecrypt' : 'VoteFileCheck')).'">';
print '</form>';
print '<p class="opacitymedium">'.$langs->trans('VoteFileNotStored').'</p>';

if (is_array($report)) {
	salonerpPrintVoteFileReport($report);
}

print '</div>';

print dol_get_fiche_end();

// End of page
llxFooter();
$db->close();

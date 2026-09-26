<?php
/* Copyright (C) 2017       Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024-2025  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		Anthony Berton 			<anthony.berton@bb2a.fr>
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
 * Oeuvre derivee de htdocs/modulebuilder/template/myobject_card.php
 * de Dolibarr ERP/CRM (version 24.0.1).
 */

/**
 *    \file       campaign_card.php
 *    \ingroup    salonerp
 *    \brief      Page to create/edit/view/validate a campaign
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/salonerp/class/campaign.class.php');
dol_include_once('/salonerp/class/campaignvoter.class.php');
dol_include_once('/salonerp/lib/salonerp_campaign.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("salonerp@salonerp", "categories", "users", "companies"));

// Get parameters
$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel');
$backtopage = GETPOST('backtopage', 'alpha');

// Initialize technical objects
$object = new Campaign($db);
$hookmanager->initHooks(array('campaigncard', 'globalcard'));
$form = new Form($db);
$formother = new FormOther($db);

if (($id > 0 || !empty($ref)) && $action != 'add') {
	$object->fetch($id, $ref);
	// Past date_end: extinct if the creator never voted, ended otherwise.
	$object->refreshTimeStatus();
}

$permissiontoread = $user->hasRight('salonerp', 'campaign', 'read');
$permissiontoadd = $user->hasRight('salonerp', 'campaign', 'write');
$permissiontodelete = $user->hasRight('salonerp', 'campaign', 'delete') || ($permissiontoadd && in_array((int) $object->status, array(Campaign::STATUS_DRAFT, Campaign::STATUS_EXTINCT), true));
$permissiontovalidate = $permissiontoadd && $object->id > 0 && (int) $user->id === (int) $object->fk_user_creat;

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
// otherwise look for a non-existent llx_salonerp table. Feature-level module
// and permission checks are enough here; the entity of the loaded object is
// verified explicitly right below.
restrictedArea($user, 'salonerp', 0, '', 'campaign');
if ($object->id > 0 && !in_array((int) $object->entity, array_map('intval', explode(',', (string) getEntity('campaign'))), true)) {
	accessforbidden();
}

$error = 0;

// This is only ever filled on a successful validate() in THIS request: never
// stored in session, never logged, never persisted. Once the response is
// sent, it only exists in the campaign creator's browser.
$privateKeyToShowOnce = '';


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if ($cancel) {
		$action = '';
		if (!empty($backtopage)) {
			header('Location: '.$backtopage);
			exit;
		}
	}

	// Create
	if ($action == 'add' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $permissiontoadd) {
		$object->ref = GETPOST('ref', 'alphanohtml');
		$object->label = GETPOST('label', 'alphanohtml');
		$object->description = GETPOST('description', 'restricthtml');
		$object->date_start = GETPOSTDATE('date_start', 'getpost');
		$object->date_end = GETPOSTDATE('date_end', 'getpost');
		$object->fk_category = GETPOSTINT('fk_category');
		$object->fk_usergroup = GETPOSTINT('fk_usergroup');
		$object->default_points = GETPOSTINT('default_points');
		$object->note_public = GETPOST('note_public', 'restricthtml');
		$object->note_private = GETPOST('note_private', 'restricthtml');

		if (empty($object->ref)) {
			$error++;
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentities('Ref')), null, 'errors');
		}
		if (empty($object->label)) {
			$error++;
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentities('Label')), null, 'errors');
		}
		if (empty($object->date_start) || empty($object->date_end)) {
			$error++;
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentities('DateStart').'/'.$langs->transnoentities('DateEnd')), null, 'errors');
		} else {
			$dateErrors = $object->checkDates();
			if (!empty($dateErrors)) {
				$error++;
				setEventMessages(null, $dateErrors, 'errors');
			}
		}
		$categoryError = $object->checkCategoryReference($object->fk_category);
		if ($categoryError !== '') {
			$error++;
			setEventMessages($categoryError, null, 'errors');
		}
		$groupError = $object->checkGroupReference($object->fk_usergroup);
		if ($groupError !== '') {
			$error++;
			setEventMessages($groupError, null, 'errors');
		}

		if (!$error) {
			$result = $object->create($user);
			if ($result > 0) {
				// The draft is created even if the automatic synchronisation of
				// voters or thirdparties failed: report it, the "Synchroniser"
				// buttons on the draft let the user retry by hand.
				if (!empty($object->errors)) {
					setEventMessages(null, $object->errors, 'errors');
				}
				header('Location: '.dol_buildpath('/salonerp/campaign_card.php', 1).'?id='.$result);
				exit;
			} else {
				setEventMessages($object->error, $object->errors, 'errors');
				$action = 'create';
			}
		} else {
			$action = 'create';
		}
	}

	// Update (draft only)
	if ($action == 'update' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $permissiontoadd && $object->id > 0) {
		$object->label = GETPOST('label', 'alphanohtml');
		$object->description = GETPOST('description', 'restricthtml');
		$object->date_start = GETPOSTDATE('date_start', 'getpost');
		$object->date_end = GETPOSTDATE('date_end', 'getpost');
		$object->fk_category = GETPOSTINT('fk_category');
		$object->fk_usergroup = GETPOSTINT('fk_usergroup');
		$object->default_points = GETPOSTINT('default_points');
		$object->note_public = GETPOST('note_public', 'restricthtml');
		$object->note_private = GETPOST('note_private', 'restricthtml');

		$inputErrors = $object->checkDates();
		$inputErrors[] = $object->checkCategoryReference($object->fk_category);
		$inputErrors[] = $object->checkGroupReference($object->fk_usergroup);
		$inputErrors = array_values(array_filter($inputErrors));
		if (!empty($inputErrors)) {
			setEventMessages(null, $inputErrors, 'errors');
			$action = 'edit';
		} else {
			$result = $object->update($user);
			if ($result > 0) {
				// The draft is modified even if the automatic resynchronisation
				// triggered by a group/tag change failed: report it, the
				// "Synchroniser" buttons on the draft let the user retry by hand.
				if (!empty($object->errors)) {
					setEventMessages(null, $object->errors, 'errors');
				}
				header('Location: '.dol_buildpath('/salonerp/campaign_card.php', 1).'?id='.$object->id);
				exit;
			} else {
				setEventMessages($langs->trans($object->error), null, 'errors');
				$action = 'edit';
			}
		}
	}

	// Delete (draft only)
	if ($action == 'confirm_delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $confirm == 'yes' && $permissiontodelete && $object->id > 0) {
		$result = $object->delete($user);
		if ($result > 0) {
			setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
			header('Location: '.dol_buildpath('/salonerp/campaign_list.php', 1));
			exit;
		} else {
			setEventMessages($langs->trans($object->error), null, 'errors');
		}
	}

	// Sync voters from group (draft only)
	if ($action == 'sync_voters' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $permissiontoadd && $object->id > 0) {
		$result = $object->syncVotersFromGroup($user);
		if ($result > 0) {
			setEventMessages($langs->trans('VotersSynchronized'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($object->error), null, 'errors');
		}
		header('Location: '.dol_buildpath('/salonerp/campaign_card.php', 1).'?id='.$object->id);
		exit;
	}

	// Sync thirdparties from the customer tag (draft only)
	if ($action == 'sync_thirdparties' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $permissiontoadd && $object->id > 0) {
		$result = $object->syncThirdpartiesFromCategory($user);
		if ($result > 0) {
			setEventMessages($langs->trans('ThirdpartiesSynchronized'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($object->error), null, 'errors');
		}
		header('Location: '.dol_buildpath('/salonerp/campaign_card.php', 1).'?id='.$object->id);
		exit;
	}

	// Save voter points (draft only). All-or-nothing: either every submitted
	// point value is saved, or none is.
	if ($action == 'save_voters' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $permissiontoadd && $object->id > 0) {
		$pointsByUser = GETPOST('points', 'array:int');
		if ($object->saveVoterPoints($user, is_array($pointsByUser) ? $pointsByUser : array()) > 0) {
			setEventMessages($langs->trans('VoterPointsSaved'), null, 'mesgs');
		} else {
			setEventMessages($object->error, null, 'errors');
		}
		header('Location: '.dol_buildpath('/salonerp/campaign_card.php', 1).'?id='.$object->id);
		exit;
	}

	// Validate: generates the keypair and the genesis hash. On success we do
	// NOT redirect: the private key must be shown in this very response, it
	// will never be available again.
	if ($action == 'confirm_validate' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $confirm == 'yes' && $permissiontovalidate && $object->id > 0) {
		// The key page is the answer to a POST: F5 resubmits it. Say plainly
		// that the campaign IS validated, instead of a red "not a draft" that
		// reads as a failed validation. validate() keeps its own locked check.
		if ((int) $object->status !== Campaign::STATUS_DRAFT) {
			setEventMessages($langs->trans('CampaignAlreadyValidatedKeyShownOnce'), null, 'warnings');
			header('Location: '.dol_buildpath('/salonerp/campaign_card.php', 1).'?id='.$object->id);
			exit;
		}
		$result = $object->validate($user);
		if (is_string($result)) {
			$privateKeyToShowOnce = $result;
			setEventMessages($langs->trans('CampaignValidated'), null, 'mesgs');
		} else {
			setEventMessages(null, $object->errors, 'errors');
		}
		$action = '';
	}
}


/*
 * View
 */

$title = $langs->trans('Campaign');
if ($action == 'create') {
	$title = $langs->trans('NewCampaign');
}
$help_url = '';

// The private key must never be cached anywhere (browser disk cache, proxy,
// history replay): send the headers before any output, including llxHeader().
if (!empty($privateKeyToShowOnce)) {
	header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
	header('Pragma: no-cache');
}

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-salonerp page-card');

// Part to create
if ($action == 'create') {
	if (empty($permissiontoadd)) {
		accessforbidden('NotEnoughPermissions', 0, 1);
	}

	print load_fiche_titre($title, '', $object->picto);

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	if ($backtopage) {
		print '<input type="hidden" name="backtopage" value="'.dol_escape_htmltag($backtopage).'">';
	}

	print dol_get_fiche_head(array(), '');

	print '<table class="border centpercent tableforfieldcreate">'."\n";

	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Ref').'</td><td>';
	print '<input type="text" class="minwidth300" name="ref" value="'.dol_escape_htmltag(GETPOST('ref', 'alpha')).'">';
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td>';
	print '<input type="text" class="minwidth300" name="label" value="'.dol_escape_htmltag(GETPOST('label', 'alpha')).'">';
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('Description').'</td><td>';
	print '<textarea name="description" class="quatrevingtpercent" rows="3">'.dol_escape_htmltag(GETPOST('description', 'restricthtml')).'</textarea>';
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('DateStart').'</td><td>';
	print $form->selectDate(GETPOSTDATE('date_start', 'getpost') ? GETPOSTDATE('date_start', 'getpost') : -1, 'date_start', 1, 1, 0, 'add', 1, 1);
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('DateEnd').'</td><td>';
	print $form->selectDate(GETPOSTDATE('date_end', 'getpost') ? GETPOSTDATE('date_end', 'getpost') : -1, 'date_end', 1, 1, 0, 'add', 1, 1);
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('CustomerCategory').'</td><td>';
	print $formother->select_categories('customer', GETPOSTINT('fk_category'), 'fk_category', 0, 1, 'minwidth300');
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('VotersGroup').'</td><td>';
	print $form->select_dolgroups(GETPOSTINT('fk_usergroup'), 'fk_usergroup', 1, '', 0, '', array(), '0', false, 'minwidth300');
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('DefaultPoints').'</td><td>';
	print '<input type="number" min="1" step="1" name="default_points" class="width75" value="'.(GETPOSTINT('default_points') > 0 ? GETPOSTINT('default_points') : '').'">';
	print '</td></tr>';

	print '</table>'."\n";

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel('Create');

	print '</form>';
} elseif ($action == 'edit' && $object->id > 0 && $object->status == Campaign::STATUS_DRAFT) {
	// Part to edit record (draft only)
	print load_fiche_titre($langs->trans('Campaign'), '', $object->picto);

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="'.$object->id.'">';

	print dol_get_fiche_head();

	print '<table class="border centpercent tableforfieldedit">'."\n";

	print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>'.dol_escape_htmltag($object->ref).'</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td>';
	print '<input type="text" class="minwidth300" name="label" value="'.dol_escape_htmltag($object->label).'">';
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('Description').'</td><td>';
	print '<textarea name="description" class="quatrevingtpercent" rows="3">'.dol_escape_htmltag((string) $object->description).'</textarea>';
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('DateStart').'</td><td>';
	print $form->selectDate($object->date_start, 'date_start', 1, 1, 0, 'update', 1, 1);
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('DateEnd').'</td><td>';
	print $form->selectDate($object->date_end, 'date_end', 1, 1, 0, 'update', 1, 1);
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('CustomerCategory').'</td><td>';
	print $formother->select_categories('customer', $object->fk_category, 'fk_category', 0, 1, 'minwidth300');
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('VotersGroup').'</td><td>';
	print $form->select_dolgroups($object->fk_usergroup, 'fk_usergroup', 1, '', 0, '', array(), '0', false, 'minwidth300');
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('DefaultPoints').'</td><td>';
	print '<input type="number" min="1" step="1" name="default_points" class="width75" value="'.((int) $object->default_points > 0 ? (int) $object->default_points : '').'">';
	print '</td></tr>';

	print '</table>';

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel();

	print '</form>';
} elseif ($object->id > 0) {
	// Part to show record
	$formconfirm = '';

	// useajax=0: the ajax dialog answers "Yes" with a GET redirect, which the
	// POST-only confirm_* actions above would silently ignore.
	if ($action == 'delete') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('DeleteCampaign'), $langs->trans('ConfirmDeleteObject'), 'confirm_delete', '', 0, 0);
	}
	if ($action == 'validate' && $permissiontovalidate) {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('ConfirmValidateCampaignTitle'), $langs->trans('ConfirmValidateCampaignText'), 'confirm_validate', '', 0, 0);
	}

	$parameters = array('formConfirm' => $formconfirm);
	$reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action);
	if (empty($reshook)) {
		$formconfirm .= $hookmanager->resPrint;
	} elseif ($reshook > 0) {
		$formconfirm = $hookmanager->resPrint;
	}

	print $formconfirm;

	// Private key, shown once, right after a successful validation. Never
	// written to a log, a session or a file: it only ever exists in this
	// HTML response and in the browser's memory/download.
	if (!empty($privateKeyToShowOnce)) {
		print '<div class="warning border centpercent" style="padding: 10px; margin-bottom: 15px;">';
		print '<p><strong>'.$langs->trans('PrivateKeyWarningTitle').'</strong></p>';
		print '<p>'.$langs->trans('PrivateKeyWarningText').'</p>';
		print '<p>'.$langs->trans('PrivateKeyLabel').' : ';
		print '<code id="salonerp-privatekey" style="word-break: break-all;">'.dol_escape_htmltag($privateKeyToShowOnce).'</code></p>';
		print '<input type="text" id="salonerp-privatekey-input" class="minwidth300" readonly value="'.dol_escape_htmltag($privateKeyToShowOnce).'" style="position: absolute; left: -9999px;">';
		print '<button type="button" id="salonerp-copy-privatekey" class="button smallpaddingimp">'.$langs->trans('CopyPrivateKey').'</button> ';
		print '<button type="button" id="salonerp-download-privatekey" class="button smallpaddingimp">'.$langs->trans('DownloadPrivateKey').'</button>';
		print '</div>';
		// Both the key and the ref are encoded with the JSON_HEX_* flags: the
		// resulting literals are safe to embed directly inside a <script> block.
		print '<script nonce="'.getNonce().'">
		document.addEventListener("DOMContentLoaded", function () {
			var keyNode = document.getElementById("salonerp-privatekey");
			var keyInput = document.getElementById("salonerp-privatekey-input");
			var copyBtn = document.getElementById("salonerp-copy-privatekey");
			var dlBtn = document.getElementById("salonerp-download-privatekey");
			if (copyBtn) {
				var copyLabel = copyBtn.textContent;
				var copyDone = function () {
					copyBtn.textContent = '.json_encode($langs->transnoentitiesnoconv('CopyPrivateKeyDone'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';
					setTimeout(function () { copyBtn.textContent = copyLabel; }, 3000);
				};
				copyBtn.addEventListener("click", function () {
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(keyNode.textContent).then(copyDone);
					} else {
						keyInput.style.position = "static";
						keyInput.select();
						keyInput.setSelectionRange(0, 99999);
						try {
							if (document.execCommand("copy")) {
								copyDone();
							}
						} finally {
							keyInput.style.position = "absolute";
						}
					}
				});
			}
			var downloadKey = function () {
				var blob = new Blob([keyNode.textContent], {type: "text/plain"});
				var url = URL.createObjectURL(blob);
				var a = document.createElement("a");
				a.href = url;
				a.download = '.json_encode((string) $object->ref, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).' + ".key";
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
			};
			if (dlBtn) {
				dlBtn.addEventListener("click", downloadKey);
			}
			// Automatic download, same function as the button above: the one
			// and only chance to grab this key without retyping it. If the
			// browser blocks it (some do, for an unsolicited download), the
			// button remains the fallback: see PrivateKeyWarningText.
			downloadKey();
		});
		</script>';
	}

	$head = campaignPrepareHead($object);

	print dol_get_fiche_head($head, 'card', $langs->trans('Campaign'), -1, $object->picto);

	$linkback = '<a href="'.dol_buildpath('/salonerp/campaign_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '');

	print '<div class="fichecenter">';
	print '<div class="fichehalfleft">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">'."\n";

	unset($object->fields['ref']);
	include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_view.tpl.php';

	// The template above closes the left column (table + div) then opens the
	// right column (div + table) but leaves both unclosed: this is the
	// standard Dolibarr two-column pattern, closed here by the caller.
	print '</table>';
	print '</div>';
	print '</div>';

	print '<div class="clearboth"></div>';

	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';
	print '<p class="opacitymedium">';
	print '<strong>'.$langs->trans('CampaignTieRuleTitle').'</strong> : ';
	print $langs->trans('CampaignTieRuleExplanation');
	print '</p>';
	print '</div>';

	print dol_get_fiche_end();

	/*
	 * Voters
	 */
	$voter = new CampaignVoter($db);
	$voters = $voter->fetchByCampaign($object->id);
	if (!is_array($voters)) {
		$voters = array();
	}
	ksort($voters);

	$isDraft = ($object->status == Campaign::STATUS_DRAFT);

	// Standard list title: the count is shown the way every Dolibarr list shows it.
	print_barre_liste($langs->trans('Voters'), 0, $_SERVER['PHP_SELF'], '', '', '', '', count($voters), count($voters), 'user', 0, '', '', -1, 0, 1);

	if ($isDraft && $permissiontoadd) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="sync_voters">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SyncVotersFromGroup')).'">';
		print '</form><br>';
	}

	if (empty($voters)) {
		print '<p class="opacitymedium">'.$langs->trans('NoVoterYet').'</p>';
	} else {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="save_voters">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';

		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('VoterUser').'</td>';
		print '<td class="right">'.$langs->trans('VoterPoints').'</td>';
		print '</tr>';

		$voterUser = new User($db);
		foreach ($voters as $fkUser => $voterRecord) {
			print '<tr class="oddeven">';
			print '<td>';
			if ($voterUser->fetch($fkUser) > 0) {
				print $voterUser->getNomUrl(-1);
				if ((int) $fkUser === (int) $object->fk_user_creat) {
					print ' <span class="opacitymedium">('.$langs->trans('Manager').')</span>';
				}
			} else {
				print '#'.((int) $fkUser);
			}
			print '</td>';
			print '<td class="right">';
			if ($isDraft && $permissiontoadd) {
				print '<input type="number" min="1" step="1" class="width75 right" name="points['.((int) $fkUser).']" value="'.((int) $voterRecord->points).'">';
			} else {
				print (int) $voterRecord->points;
			}
			print '</td>';
			print '</tr>';
		}

		print '</table>';
		print '</div>';

		if ($isDraft && $permissiontoadd) {
			print '<div class="tabsAction">';
			print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('SaveVoterPoints')).'">';
			print '</div>';
		}

		print '</form>';
	}

	/*
	 * Thirdparties: the frozen list the voters share their points across
	 */
	$campaignThirdparties = $object->fetchThirdparties();
	if (!is_array($campaignThirdparties)) {
		$campaignThirdparties = array();
	}

	print_barre_liste($langs->trans('CampaignThirdparties'), 0, $_SERVER['PHP_SELF'], '', '', '', '', count($campaignThirdparties), count($campaignThirdparties), 'company', 0, '', '', -1, 0, 1);
	print '<p class="opacitymedium">'.$langs->trans($isDraft ? 'CampaignThirdpartiesDraftHelp' : 'CampaignThirdpartiesFrozenHelp').'</p>';

	if ($isDraft && $permissiontoadd) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="sync_thirdparties">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SyncThirdpartiesFromCategory')).'">';
		print '</form><br>';
	}

	if (empty($campaignThirdparties)) {
		print '<p class="opacitymedium">'.$langs->trans('NoThirdpartyYet').'</p>';
	} else {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('ThirdParty').'</td>';
		print '<td>'.$langs->trans('CustomerCode').'</td>';
		print '<td>'.$langs->trans('Zip').'</td>';
		print '<td>'.$langs->trans('Town').'</td>';
		print '<td class="right">'.$langs->trans('TechnicalID').'</td>';
		print '</tr>';

		$soc = new Societe($db);
		foreach ($campaignThirdparties as $socId => $row) {
			$exists = ($soc->fetch($socId) > 0);
			// Draft: the thirdparty as it is now. Validated: the snapshot taken
			// at validation, the original version, whatever happened since.
			if ($isDraft) {
				$shown = array('name' => $exists ? $soc->name : '', 'code_client' => $exists ? $soc->code_client : '', 'zip' => $exists ? $soc->zip : '', 'town' => $exists ? $soc->town : '');
			} else {
				$shown = $row;
			}

			print '<tr class="oddeven">';
			print '<td>';
			if ($exists) {
				if ($isDraft) {
					print $soc->getNomUrl(1);
				} else {
					// Link to the live thirdparty, labelled with the snapshot name.
					print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $socId).'">'.img_picto('', 'company', 'class="pictofixedwidth"').dol_escape_htmltag((string) $shown['name']).'</a>';
				}
				if (!$isDraft && (string) $soc->name !== (string) $shown['name']) {
					print ' <span class="opacitymedium">('.$langs->trans('CampaignThirdpartyNowNamed', (string) $soc->name).')</span>';
				}
			} else {
				print dol_escape_htmltag((string) $shown['name']);
				print ' <span class="opacitymedium">('.$langs->trans('CampaignThirdpartyDeleted').')</span>';
			}
			print '</td>';
			print '<td>'.dol_escape_htmltag((string) $shown['code_client']).'</td>';
			print '<td>'.dol_escape_htmltag((string) $shown['zip']).'</td>';
			print '<td>'.dol_escape_htmltag((string) $shown['town']).'</td>';
			print '<td class="right">'.((int) $socId).'</td>';
			print '</tr>';
		}

		print '</table>';
		print '</div>';
	}

	// Buttons for actions
	if ($isDraft) {
		print '<div class="tabsAction">'."\n";
		$parameters = array();
		$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		}
		if (empty($reshook)) {
			print dolGetButtonAction('', $langs->trans('Modify'), 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=edit&token='.newToken(), '', $permissiontoadd);

			if ($permissiontovalidate) {
				print dolGetButtonAction('', $langs->trans('Validate'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=validate&token='.newToken(), '', $permissiontovalidate);
			}

			// Always a plain link to the POST confirmation form (see formconfirm
			// above): the builder's ajax variant relies on a hidden dialog that
			// is never rendered here, and would answer with a GET anyway.
			$deleteUrl = $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delete&token='.newToken();
			print dolGetButtonAction('', $langs->trans("Delete"), 'delete', $deleteUrl, 'action-delete-no-ajax', $permissiontodelete);
		}
		print '</div>'."\n";
	} elseif ((int) $object->status === Campaign::STATUS_EXTINCT) {
		// Extinct: the creator never opened the vote, nothing to keep.
		print '<div class="tabsAction">'."\n";
		print dolGetButtonAction('', $langs->trans("Delete"), 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delete&token='.newToken(), 'action-delete-no-ajax', $permissiontodelete);
		print '</div>'."\n";
	}
}

// End of page
llxFooter();
$db->close();

// The private key only ever lived in this response's output buffer (already
// flushed by llxFooter()/the page lifecycle) and in this local variable:
// wipe it from process memory now that it has been displayed.
if (!empty($privateKeyToShowOnce)) {
	sodium_memzero($privateKeyToShowOnce);
}

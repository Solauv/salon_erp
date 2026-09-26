<?php
/* Copyright (C) 2007-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2024-2025  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		MDW						<mdeweerd@users.noreply.github.com>
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
 * Oeuvre derivee de htdocs/recruitment/recruitmentjobposition_note.php
 * de Dolibarr ERP/CRM (version 24.0.1).
 */

/**
 *  \file       campaign_vote.php
 *  \ingroup    salonerp
 *  \brief      Vote tab of a Campaign: vote file download and sealed, chained vote
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
dol_include_once('/salonerp/class/campaignvoter.class.php');
dol_include_once('/salonerp/lib/salonerp_campaign.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("salonerp@salonerp", "companies"));

// Get parameters
$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');

// Initialize technical objects
$object = new Campaign($db);
$hookmanager->initHooks(array('campaignvote', 'globalcard'));

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

// Being in the campaign's voter list is what grants the vote (with the read
// right above): no dedicated permission.
$voterObj = new CampaignVoter($db);
$voters = $voterObj->fetchByCampaign($object->id);
$isVoter = is_array($voters) && isset($voters[(int) $user->id]);
$envelope = $isVoter ? (int) $voters[(int) $user->id]->points : 0;
$isCreator = ((int) $user->id === (int) $object->fk_user_creat);

// The vote file must be downloaded before voting: remembered per campaign in
// the session, checked again server-side when the vote is posted.
$sessionKey = 'salonerp_votefile_'.((int) $object->id);

// This is only ever filled on a successful castVote() in THIS request: never
// stored in session, never logged, never persisted. Once the response is
// sent, the receipt (and the eph_sk it carries) only ever exists in the
// voter's browser.
$receiptToShowOnce = '';
$receiptSeqToShowOnce = 0;


/*
 * Actions
 */

$reshook = $hookmanager->executeHooks('doActions', array(), $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}
if (empty($reshook)) {
	if ($action == 'downloadfile' && $isVoter) {
		$content = $object->buildVoteFile();
		if (is_string($content)) {
			$_SESSION[$sessionKey] = 1;
			$votes = $object->fetchVotes();
			$filename = dol_sanitizeFileName($object->ref).'-votes-'.(is_array($votes) ? count($votes) : 0).'.json';
			header('Content-Type: application/json; charset=UTF-8');
			header('Content-Disposition: attachment; filename="'.$filename.'"');
			header('Cache-Control: no-store');
			print $content;
			exit;
		}
		setEventMessages($object->error, null, 'errors');
	}

	if ($action == 'vote' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $isVoter) {
		$error = 0;
		if (empty($_SESSION[$sessionKey])) {
			$error++;
			setEventMessages($langs->trans('ErrorCampaignVoteDownloadFirst'), null, 'errors');
		}
		if (dol_strtolower(trim(GETPOST('confirmtext', 'alphanohtml'))) !== dol_strtolower($langs->transnoentitiesnoconv('CampaignVoteConfirmWord'))) {
			$error++;
			setEventMessages($langs->trans('ErrorCampaignVoteNotConfirmed', $langs->transnoentitiesnoconv('CampaignVoteConfirmWord')), null, 'errors');
		}
		if (!$error) {
			// Empty fields are thirdparties the voter left out: zero points.
			$allocations = array();
			$posted = GETPOST('points', 'array:alphanohtml');
			foreach ((is_array($posted) ? $posted : array()) as $socId => $points) {
				if (trim((string) $points) !== '') {
					$allocations[(int) $socId] = $points;
				}
			}
			$receipt = null;
			$result = $object->castVote($user, $allocations, (string) GETPOST('privatekey', 'alphanohtml'), $receipt);
			if ($result > 0) {
				setEventMessages($langs->trans('CampaignVoteRecorded'), null, 'mesgs');
				// No redirect here, on purpose: the receipt is shown exactly
				// once, in this very response, exactly like the campaign's
				// private key after validate() on campaign_card.php. A
				// redirect (or a page reload) would lose it forever.
				$receiptToShowOnce = (string) $receipt;
				$receiptSeqToShowOnce = $result;
			} else {
				setEventMessages(null, $object->errors, 'errors');
			}
		}
	}
}


/*
 * View
 */

$form = new Form($db);

// The receipt must never be cached anywhere (browser disk cache, proxy,
// history replay): send the headers before any output, including
// llxHeader(), exactly like campaign_card.php does for the private key.
if (!empty($receiptToShowOnce)) {
	header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
	header('Pragma: no-cache');
}

$title = $langs->trans('Campaign').' - '.$langs->trans('CampaignVoteTab');
llxHeader('', $title, '');

$head = campaignPrepareHead($object);
print dol_get_fiche_head($head, 'vote', $langs->trans("Campaign"), -1, $object->picto);

$linkback = '<a href="'.dol_buildpath('/salonerp/campaign_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '');

// Receipt, shown once, right after a successful vote. Never written to a
// log, a session or a file: it only ever exists in this HTML response and in
// the voter's browser memory/download, exactly like the private key on
// campaign_card.php.
if (!empty($receiptToShowOnce)) {
	$receiptFilename = 'recu-'.dol_sanitizeFileName((string) $object->ref).'-'.$receiptSeqToShowOnce.'.json';

	print '<div class="warning border centpercent" style="padding: 10px; margin-bottom: 15px;">';
	print '<p><strong>'.$langs->trans('ReceiptWarningTitle').'</strong></p>';
	print '<p>'.$langs->trans('ReceiptWarningText').'</p>';
	print '<pre id="salonerp-receipt" class="wordbreak" style="max-height: 300px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: #fff; padding: 5px;">'.dol_escape_htmltag($receiptToShowOnce, 0, 1).'</pre>';
	print '<textarea id="salonerp-receipt-input" readonly style="position: absolute; left: -9999px;">'.dol_escape_htmltag($receiptToShowOnce, 0, 1).'</textarea>';
	print '<button type="button" id="salonerp-copy-receipt" class="button smallpaddingimp">'.$langs->trans('CopyReceipt').'</button> ';
	print '<button type="button" id="salonerp-download-receipt" class="button smallpaddingimp">'.$langs->trans('DownloadReceipt').'</button>';
	print '</div>';
	// $keepn = 1 above: by default dol_escape_htmltag() turns newlines into a
	// literal \n, and the copied or downloaded receipt would no longer be JSON.
	// The receipt JSON and the filename are encoded with the JSON_HEX_*
	// flags: the resulting literals are safe to embed directly inside a
	// <script> block (same pattern as campaign_card.php's private key).
	print '<script nonce="'.getNonce().'">
	document.addEventListener("DOMContentLoaded", function () {
		var node = document.getElementById("salonerp-receipt");
		var input = document.getElementById("salonerp-receipt-input");
		var copyBtn = document.getElementById("salonerp-copy-receipt");
		var dlBtn = document.getElementById("salonerp-download-receipt");
		if (copyBtn) {
			var copyLabel = copyBtn.textContent;
			var copyDone = function () {
				copyBtn.textContent = '.json_encode($langs->transnoentitiesnoconv('CopyReceiptDone'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';
				setTimeout(function () { copyBtn.textContent = copyLabel; }, 3000);
			};
			copyBtn.addEventListener("click", function () {
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(node.textContent).then(copyDone);
				} else {
					input.style.position = "static";
					input.select();
					input.setSelectionRange(0, 99999999);
					try {
						if (document.execCommand("copy")) {
							copyDone();
						}
					} finally {
						input.style.position = "absolute";
					}
				}
			});
		}
		var downloadReceipt = function () {
			var blob = new Blob([node.textContent], {type: "application/json"});
			var url = URL.createObjectURL(blob);
			var a = document.createElement("a");
			a.href = url;
			a.download = '.json_encode($receiptFilename, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
		};
		if (dlBtn) {
			dlBtn.addEventListener("click", downloadReceipt);
		}
		// Automatic download, same function as the button above: the one and
		// only chance to grab this receipt without recasting the vote. If the
		// browser blocks it (some do, for an unsolicited download), the
		// button remains the fallback: see ReceiptWarningText.
		downloadReceipt();
	});
	</script>';
}

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

$status = (int) $object->status;
$now = dol_now();
$votes = $object->fetchVotes();
$nbVotes = is_array($votes) ? count($votes) : 0;
$votedAt = $isVoter ? $object->fetchUserVoteDate($user->id) : null;
$start = dol_print_date($object->date_start, 'dayhour', 'tzuserrel');
$end = dol_print_date($object->date_end, 'dayhour', 'tzuserrel');
$inWindow = ($now >= (int) $object->date_start && $now < (int) $object->date_end);

// Where the vote stands, in one sentence.
if ($status === Campaign::STATUS_VALIDATED) {
	if (!$inWindow) {
		$stateText = $langs->trans('CampaignVoteStateNotStarted', $start, $end);
	} elseif ($isCreator) {
		$stateText = $langs->trans('CampaignVoteStateCreatorFirst', $end);
	} else {
		$stateText = $langs->trans('CampaignVoteStateWaitCreator', $end);
	}
} elseif ($status === Campaign::STATUS_VOTE_OPEN) {
	$stateText = $langs->trans('CampaignVoteStateOpen', $end);
} elseif ($status === Campaign::STATUS_ENDED) {
	$stateText = $langs->trans('CampaignVoteStateEnded', $end);
} elseif ($status === Campaign::STATUS_REVEALED) {
	$stateText = $langs->trans('CampaignVoteStateRevealed');
} elseif ($status === Campaign::STATUS_EXTINCT) {
	$stateText = $langs->trans('CampaignVoteStateExtinct', $end);
} else {
	$stateText = '';
}

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(5).'</td></tr>';
print '<tr><td>'.$langs->trans('CampaignVotesRecorded').'</td><td>'.$nbVotes.'</td></tr>';
if ($isVoter) {
	print '<tr><td>'.$langs->trans('CampaignYourEnvelope').'</td><td>'.$envelope.'</td></tr>';
	print '<tr><td>'.$langs->trans('CampaignYourVote').'</td><td>';
	print $votedAt ? $langs->trans('CampaignYouVotedOn', dol_print_date($votedAt, 'dayhour', 'tzuserrel')) : '<span class="opacitymedium">'.$langs->trans('CampaignYouHaveNotVoted').'</span>';
	print '</td></tr>';
}
print '</table>';

if ($stateText !== '') {
	print '<div class="info clearboth">'.$stateText.'</div>';
}

if (!$isVoter) {
	print '<div class="opacitymedium">'.$langs->trans('CampaignNotAVoterText').'</div>';
} else {
	// main.inc.php's CSRF protection refuses a GET carrying an action without
	// a token.
	$downloadUrl = dol_buildpath('/salonerp/campaign_vote.php', 1).'?id='.$object->id.'&action=downloadfile&token='.newToken();

	$canVoteNow = !$votedAt && $inWindow
		&& ($status === Campaign::STATUS_VOTE_OPEN || ($status === Campaign::STATUS_VALIDATED && $isCreator));

	if ($votedAt) {
		print '<p>'.$langs->trans('CampaignVotedText').'</p>';
	}
	if (!$canVoteNow) {
		print '<div class="tabsAction"><a class="butAction" id="salonerp-download" href="'.$downloadUrl.'">'.$langs->trans('CampaignDownloadVoteFile').'</a></div>';
	} else {
		$campaignThirdparties = $object->fetchThirdparties();
		if (!is_array($campaignThirdparties)) {
			$campaignThirdparties = array();
		}
		$downloaded = !empty($_SESSION[$sessionKey]);
		// After a refused vote, the voter gets back what they typed (never
		// the private key).
		$postedPoints = GETPOST('points', 'array:int');
		if (!is_array($postedPoints)) {
			$postedPoints = array();
		}

		print load_fiche_titre($langs->trans('CampaignVoteStep1'), '', '');
		print '<p class="opacitymedium">'.$langs->trans('CampaignVoteStep1Text').'</p>';
		print '<a class="button" id="salonerp-download" href="'.$downloadUrl.'">'.$langs->trans('CampaignDownloadVoteFile').'</a>';

		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" id="salonerp-vote-form">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="vote">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';

		print load_fiche_titre($langs->trans('CampaignVoteStep2'), '', '');
		print '<p>'.$langs->trans('CampaignVoteStep2Text', $envelope).'</p>';
		print '<p><strong>'.$langs->trans('CampaignPointsLeft').' : <span id="salonerp-left">'.$envelope.'</span></strong>';
		print ' &nbsp; <label><input type="checkbox" id="salonerp-filter"> '.$langs->trans('CampaignOnlySelected').'</label></p>';

		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td class="width25"></td>';
		print '<td>'.$langs->trans('ThirdParty').'</td>';
		print '<td>'.$langs->trans('CustomerCode').'</td>';
		print '<td>'.$langs->trans('Town').'</td>';
		print '<td class="right">'.$langs->trans('Points').'</td>';
		print '</tr>';
		foreach ($campaignThirdparties as $socId => $row) {
			print '<tr class="oddeven salonerp-row">';
			print '<td><input type="checkbox" class="salonerp-select"></td>';
			print '<td>'.dol_escape_htmltag((string) $row['name']).'</td>';
			print '<td>'.dol_escape_htmltag((string) $row['code_client']).'</td>';
			print '<td>'.dol_escape_htmltag((string) $row['town']).'</td>';
			print '<td class="right"><input type="number" min="0" step="1" class="width75 right salonerp-points" name="points['.((int) $socId).']" value="'.(!empty($postedPoints[$socId]) ? (int) $postedPoints[$socId] : '').'"></td>';
			print '</tr>';
		}
		print '</table>';
		print '</div>';

		print load_fiche_titre($langs->trans('CampaignVoteStep3'), '', '');
		print '<div class="warning">'.$langs->trans('CampaignVoteFinalWarning').'</div>';
		if ($status === Campaign::STATUS_VALIDATED) {
			// The creator's first vote proves they hold the private key: the
			// key is checked against the public key and never stored.
			print '<p>'.$langs->trans('CampaignCreatorKeyText').'</p>';
			print '<p><input type="password" name="privatekey" class="minwidth400" autocomplete="off" placeholder="'.dol_escape_htmltag($langs->trans('PrivateKeyLabel')).'"></p>';
		}
		print '<p>'.$langs->trans('CampaignVoteTypeConfirm', $langs->transnoentitiesnoconv('CampaignVoteConfirmWord')).' ';
		print '<input type="text" name="confirmtext" autocomplete="off" class="width100"></p>';
		print '<div class="center"><input type="submit" class="button" id="salonerp-submit" value="'.dol_escape_htmltag($langs->trans('CampaignCastVote')).'"'.($downloaded ? '' : ' disabled').'></div>';
		if (!$downloaded) {
			print '<p class="center opacitymedium" id="salonerp-download-first">'.$langs->trans('ErrorCampaignVoteDownloadFirst').'</p>';
		}
		print '</form>';

		print '<script nonce="'.getNonce().'">
		document.addEventListener("DOMContentLoaded", function () {
			var envelope = '.((int) $envelope).';
			var left = document.getElementById("salonerp-left");
			var filter = document.getElementById("salonerp-filter");
			var rows = document.querySelectorAll(".salonerp-row");
			function refresh() {
				var total = 0;
				rows.forEach(function (row) {
					var input = row.querySelector(".salonerp-points");
					var select = row.querySelector(".salonerp-select");
					var value = parseInt(input.value, 10);
					if (value > 0) {
						total += value;
						select.checked = true;
					}
					row.style.display = (filter.checked && !select.checked) ? "none" : "";
				});
				left.textContent = envelope - total;
				left.style.color = (envelope - total === 0) ? "green" : ((envelope - total < 0) ? "red" : "");
			}
			rows.forEach(function (row) {
				row.querySelector(".salonerp-points").addEventListener("input", refresh);
				row.querySelector(".salonerp-select").addEventListener("change", refresh);
			});
			filter.addEventListener("change", refresh);
			var download = document.getElementById("salonerp-download");
			download.addEventListener("click", function () {
				document.getElementById("salonerp-submit").disabled = false;
				var note = document.getElementById("salonerp-download-first");
				if (note) {
					note.style.display = "none";
				}
			});
			refresh();
		});
		</script>';
	}
}

print '</div>';

print dol_get_fiche_end();

// End of page
llxFooter();
$db->close();

// The receipt only ever lived in this response's output buffer (already
// flushed by llxFooter()/the page lifecycle) and in this local variable:
// wipe it from process memory now that it has been displayed.
if (!empty($receiptToShowOnce)) {
	sodium_memzero($receiptToShowOnce);
}

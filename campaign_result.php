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
 *  \file       campaign_result.php
 *  \ingroup    salonerp
 *  \brief      Results tab of a Campaign: reveal, count, sales reps attribution
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

// Load translation files required by the page
$langs->loadLangs(array("salonerp@salonerp", "companies"));

// Get parameters
$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$onlyMine = GETPOSTINT('onlymine');

// Initialize technical objects
$object = new Campaign($db);
$hookmanager->initHooks(array('campaignresult', 'globalcard'));

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
if ($object->id <= 0 || !in_array((int) $object->status, array(Campaign::STATUS_ENDED, Campaign::STATUS_REVEALED), true)) {
	accessforbidden();
}

$isCreator = ((int) $user->id === (int) $object->fk_user_creat);


/*
 * Actions
 */

$reshook = $hookmanager->executeHooks('doActions', array(), $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}
if (empty($reshook)) {
	if ($action == 'reveal' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $isCreator) {
		if (dol_strtolower(trim(GETPOST('confirmtext', 'alphanohtml'))) !== dol_strtolower($langs->transnoentitiesnoconv('CampaignVoteConfirmWord'))) {
			setEventMessages($langs->trans('ErrorCampaignVoteNotConfirmed', $langs->transnoentitiesnoconv('CampaignVoteConfirmWord')), null, 'errors');
		} elseif ($object->reveal($user, (string) GETPOST('privatekey', 'alphanohtml')) > 0) {
			setEventMessages($langs->trans('CampaignRevealed'), null, 'mesgs');
			header('Location: '.dol_buildpath('/salonerp/campaign_result.php', 1).'?id='.$object->id);
			exit;
		} else {
			setEventMessages(null, $object->errors, 'errors');
		}
	}

	if ($action == 'confirm_attribute' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken() && $confirm == 'yes' && $isCreator) {
		$report = $object->attributeSalesReps($user);
		if (is_array($report)) {
			setEventMessages($langs->trans('CampaignAttributionDone', count($report['added']), count($report['already']), count($report['skipped'])), null, 'mesgs');
			header('Location: '.dol_buildpath('/salonerp/campaign_result.php', 1).'?id='.$object->id);
			exit;
		}
		setEventMessages(null, $object->errors, 'errors');
	}
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('Campaign').' - '.$langs->trans('CampaignResultTab');
llxHeader('', $title, '');

$head = campaignPrepareHead($object);
print dol_get_fiche_head($head, 'result', $langs->trans("Campaign"), -1, $object->picto);

$linkback = '<a href="'.dol_buildpath('/salonerp/campaign_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

// A small cache of user names: many rows name the same voters.
$userNames = array();
$userLink = function ($userId) use ($db, &$userNames) {
	if (!isset($userNames[$userId])) {
		$u = new User($db);
		$userNames[$userId] = ($u->fetch($userId) > 0) ? $u->getNomUrl(-1) : '#'.((int) $userId);
	}
	return $userNames[$userId];
};

if ((int) $object->status === Campaign::STATUS_ENDED) {
	if (!$isCreator) {
		print '<div class="info clearboth">'.$langs->trans('CampaignRevealWait').'</div>';
	} else {
		print '<div class="info clearboth">'.$langs->trans('CampaignRevealText').'</div>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="reveal">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		print '<p><input type="password" name="privatekey" class="minwidth400" autocomplete="off" placeholder="'.dol_escape_htmltag($langs->trans('PrivateKeyLabel')).'"></p>';
		print '<p>'.$langs->trans('CampaignVoteTypeConfirm', $langs->transnoentitiesnoconv('CampaignVoteConfirmWord')).' ';
		print '<input type="text" name="confirmtext" autocomplete="off" class="width100"></p>';
		print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('CampaignReveal')).'"></div>';
		print '</form>';
	}
} else {
	$votes = $object->fetchVotes();
	$lastHash = (is_array($votes) && !empty($votes)) ? $votes[count($votes) - 1]['hash'] : (string) $object->genesis_hash;

	// Keys: public from now on, so anyone can open the vote file themselves.
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('CampaignDateReveal').'</td><td>'.dol_print_date($object->date_reveal, 'dayhour', 'tzuserrel').'</td></tr>';
	print '<tr><td>'.$langs->trans('PublicKey').'</td><td><code style="word-break: break-all;">'.dol_escape_htmltag((string) $object->public_key).'</code></td></tr>';
	print '<tr><td>'.$langs->trans('PrivateKeyLabel').'</td><td><code style="word-break: break-all;">'.dol_escape_htmltag((string) $object->private_key).'</code></td></tr>';
	print '<tr><td>'.$langs->trans('CampaignLastHash').'</td><td><code style="word-break: break-all;">'.dol_escape_htmltag($lastHash).'</code></td></tr>';
	print '</table>';
	print '<p class="opacitymedium">'.$langs->trans('CampaignKeyNowPublic').'</p>';

	// Sales representatives: a separate, explicit, one-time step.
	if (!empty($object->date_attribution)) {
		// trans() escapes its parameters: the user link (HTML) goes in after.
		$attributedOn = $langs->trans('CampaignAttributedOn', dol_print_date($object->date_attribution, 'dayhour', 'tzuserrel'), '__USER__');
		print '<div class="info clearboth">'.str_replace('__USER__', $userLink((int) $object->fk_user_attribution), $attributedOn).'</div>';
	} elseif ($isCreator) {
		if ($action == 'attribute') {
			// useajax=0: a POST form, the only method confirm_attribute accepts.
			print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('CampaignAttribute'), $langs->trans('CampaignAttributeConfirm'), 'confirm_attribute', '', 0, 0);
		}
		print '<div class="tabsAction">'.dolGetButtonAction('', $langs->trans('CampaignAttribute'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=attribute&token='.newToken(), '', true).'</div>';
	} else {
		print '<div class="opacitymedium">'.$langs->trans('CampaignNotAttributedYet').'</div>';
	}

	// Results
	$results = $object->fetchResults();
	$results = is_array($results) ? $results : array();
	$thirdparties = $object->fetchThirdparties();
	$thirdparties = is_array($thirdparties) ? $thirdparties : array();
	if ($onlyMine) {
		$results = array_filter($results, function ($r) use ($user) {
			return $r['fk_user'] === (int) $user->id;
		});
	}

	$filterUrl = $_SERVER['PHP_SELF'].'?id='.$object->id.($onlyMine ? '' : '&onlymine=1');
	$filterLink = '<a href="'.$filterUrl.'"><span class="'.($onlyMine ? 'fas fa-check-square' : 'far fa-square').' paddingright"></span>'.$langs->trans('CampaignOnlyMine').'</a>';
	print_barre_liste($langs->trans('CampaignResults'), 0, $_SERVER['PHP_SELF'], '', '', '', $filterLink, count($results), count($results), 'company', 0, '', '', -1, 0, 1);

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('ThirdParty').'</td>';
	print '<td>'.$langs->trans('CampaignBidsReceived').'</td>';
	print '<td>'.$langs->trans('CampaignWinner').'</td>';
	print '<td class="right">'.$langs->trans('Points').'</td>';
	print '<td>'.$langs->trans('CampaignDecidedBy').'</td>';
	print '<td>'.$langs->trans('CampaignCalculation').'</td>';
	print '</tr>';
	foreach ($results as $socId => $r) {
		$name = isset($thirdparties[$socId]) ? (string) $thirdparties[$socId]['name'] : '#'.$socId;
		print '<tr class="oddeven">';
		print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $socId).'">'.img_picto('', 'company', 'class="pictofixedwidth"').dol_escape_htmltag($name).'</a></td>';

		// Who bid what on this thirdparty: the ballots read the other way
		// round, highest bid first, the winner in bold.
		$bids = isset($r['detail']['bids']) ? $r['detail']['bids'] : array();
		usort($bids, function ($a, $b) {
			return ($b['points'] <=> $a['points']) ?: ($a['fk_user'] <=> $b['fk_user']);
		});
		$received = array();
		foreach ($bids as $bid) {
			$entry = $userLink($bid['fk_user']).' : '.((int) $bid['points']);
			$received[] = ($bid['fk_user'] === $r['fk_user']) ? '<strong>'.$entry.'</strong>' : $entry;
		}
		print '<td class="nowraponall">'.(empty($received) ? '<span class="opacitymedium">'.$langs->trans('CampaignNoBid').'</span>' : implode('<br>', $received)).'</td>';

		print '<td>'.($r['fk_user'] === null ? '<span class="opacitymedium">'.$langs->trans('CampaignNobody').'</span>' : $userLink($r['fk_user'])).'</td>';
		print '<td class="right">'.($r['points'] > 0 ? $r['points'] : '').'</td>';
		print '<td>'.$langs->trans('CampaignRule_'.$r['rule']).'</td>';

		// The tie-break, spelled out so anyone can redo it: only the voters
		// tied on the highest bid, only when there was a tie.
		$lines = array();
		foreach ($bids as $bid) {
			if (($r['rule'] !== 'B' && $r['rule'] !== 'E') || !in_array($bid['fk_user'], $r['detail']['tied'], true)) {
				continue;
			}
			$line = dol_escape_htmltag(strip_tags($userLink($bid['fk_user'])).' : '.$bid['points']);
			$line .= ' / '.((int) $bid['envelope']).' = '.price2num(100 * $bid['points'] / $bid['envelope'], 2).' %';
			if ($r['rule'] === 'E' && isset($r['detail']['draw']['hashes'][$bid['fk_user']])) {
				// Shortened on screen, complete in the tooltip: enough to redo the draw.
				$drawHash = (string) $r['detail']['draw']['hashes'][$bid['fk_user']];
				$line .= ' — sha256 = <span class="classfortooltip" title="'.dol_escape_htmltag($drawHash).'">'.dol_escape_htmltag(substr($drawHash, 0, 12)).'…</span>';
			}
			$lines[] = $line;
		}
		print '<td class="small">'.implode('<br>', $lines);
		if ($r['rule'] === 'E') {
			print '<br><span class="opacitymedium">'.$langs->trans('CampaignDrawFormula', (int) $socId).'</span>';
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';

	// Every ballot, in clear.
	$ballots = $object->fetchBallots();
	$ballots = is_array($ballots) ? $ballots : array();
	print_barre_liste($langs->trans('CampaignBallots'), 0, $_SERVER['PHP_SELF'], '', '', '', '', count($ballots), count($ballots), 'user', 0, '', '', -1, 0, 1);
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td class="right width50">'.$langs->trans('CampaignSeq').'</td>';
	print '<td>'.$langs->trans('CampaignVoter').'</td>';
	print '<td>'.$langs->trans('Date').'</td>';
	print '<td>'.$langs->trans('CampaignDistribution').'</td>';
	print '</tr>';
	foreach ($ballots as $ballot) {
		$parts = array();
		foreach ($ballot['points'] as $pair) {
			$socName = isset($thirdparties[(int) $pair[0]]) ? (string) $thirdparties[(int) $pair[0]]['name'] : '#'.((int) $pair[0]);
			$parts[] = dol_escape_htmltag($socName).' : '.((int) $pair[1]);
		}
		print '<tr class="oddeven">';
		print '<td class="right">'.$ballot['seq'].'</td>';
		print '<td>'.$userLink($ballot['fk_user']).'</td>';
		print '<td>'.dol_print_date(dol_stringtotime($ballot['date'], 1), 'dayhour', 'tzuserrel').'</td>';
		print '<td>'.implode(' ; ', $parts).'</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

print '</div>';

print dol_get_fiche_end();

// End of page
llxFooter();
$db->close();

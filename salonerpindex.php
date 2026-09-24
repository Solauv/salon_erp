<?php
/* Copyright (C) 2001-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2015       Jean-François Ferry     <jfefe@aternatik.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
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
 * Oeuvre derivee de htdocs/modulebuilder/template/myobjectindex.php
 * de Dolibarr ERP/CRM (version 24.0.1).
 */

/**
 *	\file       salonerp/salonerpindex.php
 *	\ingroup    salonerp
 *	\brief      Home page of the Votes top menu: latest campaigns.
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

// Load translation files required by the page
$langs->loadLangs(array("salonerp@salonerp"));

if (!isModEnabled('salonerp')) {
	accessforbidden("Module salonerp not enabled");
}
if ($user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('salonerp', 'campaign', 'read')) {
	accessforbidden();
}

$max = getDolUserInt('MAIN_SIZE_SHORTLIST_LIMIT', getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT', 5));


/*
 * View
 */

$form = new Form($db);

llxHeader("", $langs->trans("SalonerpArea"), '', '', 0, 0, '', '', '', 'mod-salonerp page-index');

print load_fiche_titre($langs->trans("SalonerpArea"), '', 'fa-vote-yea');

print '<div class="fichecenter"><div class="fichethirdleft">';
print '</div><div class="fichetwothirdright">';

$campaign = new Campaign($db);
$campaigns = $campaign->fetchAll('DESC', 't.tms', $max, 0);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">'.$langs->trans("Campaigns").'</th>';
print '<th class="right">'.$langs->trans("Status").'</th>';
print '</tr>';

if (is_array($campaigns) && count($campaigns) > 0) {
	foreach ($campaigns as $campaignLine) {
		print '<tr class="oddeven">';
		print '<td class="nowrap">'.$campaignLine->getNomUrl(1).'</td>';
		print '<td>'.dol_escape_htmltag((string) $campaignLine->label).'</td>';
		print '<td class="right">'.$campaignLine->getLibStatut(3).'</td>';
		print '</tr>';
	}
} else {
	print '<tr class="oddeven"><td colspan="3" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
}
print '</table>';

if ($user->hasRight('salonerp', 'campaign', 'write')) {
	print '<div class="tabsAction">';
	print dolGetButtonAction('', $langs->trans('NewCampaign'), 'default', dol_buildpath('/salonerp/campaign_card.php', 1).'?action=create', '', 1);
	print '</div>';
}

print '</div></div>';

// End of page
llxFooter();
$db->close();

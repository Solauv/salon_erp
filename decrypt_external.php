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
 *  \file       decrypt_external.php
 *  \ingroup    salonerp
 *  \brief      External decryption: check and decrypt any vote file with its revealed key, without its campaign
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
dol_include_once('/salonerp/class/salonerpvotefile.class.php');
dol_include_once('/salonerp/lib/salonerp_campaign.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("salonerp@salonerp"));

$action = GETPOST('action', 'aZ09');

if (!isModEnabled('salonerp')) {
	accessforbidden("Module salonerp not enabled");
}
if ($user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('salonerp', 'campaign', 'read')) {
	accessforbidden();
}
restrictedArea($user, 'salonerp', 0, '', 'campaign');


/*
 * Actions
 */

// The standalone script, to check and decrypt without any Dolibarr at all.
if ($action == 'downloadscript') {
	header('Content-Type: text/x-php; charset=UTF-8');
	header('Content-Disposition: attachment; filename="dechiffrer-votes.php"');
	header('Cache-Control: no-store');
	print salonerpExternalDecryptScript()."\n";
	exit;
}

$report = null;
if ($action == 'analyse' && $_SERVER['REQUEST_METHOD'] === 'POST' && salonerpCheckPostToken()) {
	$content = salonerpReadUploadedVoteFile();
	if ($content === false) {
		setEventMessages($langs->trans('ErrorVoteFileMissing'), null, 'errors');
	} else {
		// No campaign here: only what the file itself proves, plus the key.
		$report = SalonerpVoteFile::analyse($content, trim((string) GETPOST('privatekey', 'alphanohtml')));
	}
}


/*
 * View
 */

$title = $langs->trans('ExternalDecryption');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-salonerp page-decrypt-external');

print load_fiche_titre($title, '', 'fa-unlock');

print '<p>'.$langs->trans('ExternalDecryptionText').'</p>';

print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="analyse">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield fieldrequired">'.$langs->trans('VoteFile').'</td><td><input type="file" name="votefile" accept=".json,application/json"></td></tr>';
print '<tr><td>'.$langs->trans('RevealedPrivateKey').'</td><td><input type="text" name="privatekey" class="centpercent" autocomplete="off" value="'.dol_escape_htmltag(trim((string) GETPOST('privatekey', 'alphanohtml'))).'"><br><span class="opacitymedium">'.$langs->trans('RevealedPrivateKeyHelp').'</span></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VoteFileCheckAndDecrypt')).'"></div>';
print '</form>';
print '<p class="opacitymedium">'.$langs->trans('VoteFileNotStored').'</p>';

if (is_array($report)) {
	salonerpPrintVoteFileReport($report);
}

// Without Dolibarr at all: the procedure and the script.
print load_fiche_titre($langs->trans('ManualDecryptionTitle'), '', '');
print '<p>'.$langs->trans('ManualDecryptionText').'</p>';
print '<ol>';
foreach (array('ManualDecryptionStep1', 'ManualDecryptionStep2', 'ManualDecryptionStep3', 'ManualDecryptionStep4') as $step) {
	print '<li>'.$langs->trans($step).'</li>';
}
print '</ol>';
print '<div class="tabsAction"><a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=downloadscript&token='.newToken().'">'.$langs->trans('ManualDecryptionDownload').'</a></div>';
// htmlspecialchars(), not dol_escape_htmltag(): the latter drops the line
// breaks, and the script would show as a single endless line. Long lines
// wrap on screen only (pre-wrap): a copy keeps the real line breaks.
print '<pre style="padding: 10px; border: 1px solid #ccc; white-space: pre-wrap; overflow-wrap: anywhere;">'.htmlspecialchars(salonerpExternalDecryptScript(), ENT_QUOTES, 'UTF-8').'</pre>';

// End of page
llxFooter();
$db->close();

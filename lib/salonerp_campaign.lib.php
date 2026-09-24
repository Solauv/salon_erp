<?php
/* Copyright (C) 2026		Solauv
 * Copyright (C) 2025       Frédéric France         <frederic.france@free.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Oeuvre derivee de htdocs/modulebuilder/template/lib/mymodule_myobject.lib.php
 * de Dolibarr ERP/CRM (version 24.0.1).
 */

/**
 * \file    salonerp/lib/salonerp_campaign.lib.php
 * \ingroup salonerp
 * \brief   Library files with common functions for Campaign
 */

/**
 * Prepare array of tabs for Campaign: card, notes, vote once validated, results once ended.
 *
 * @param	Campaign	$object					Campaign
 * @return 	array<array{string,string,string}>	Array of tabs
 */
function campaignPrepareHead($object)
{
	global $langs, $conf;

	$langs->load("salonerp@salonerp");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/salonerp/campaign_card.php", 1), array('id' => $object->id));
	$head[$h][1] = $langs->trans("Campaign");
	$head[$h][2] = 'card';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/salonerp/campaign_note.php", 1), array('id' => $object->id));
	$nbNote = 0;
	if (!empty($object->note_private)) {
		$nbNote++;
	}
	if (!empty($object->note_public)) {
		$nbNote++;
	}
	$head[$h][1] = $langs->trans('Notes');
	if ($nbNote > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">'.$nbNote.'</span>';
	}
	$head[$h][2] = 'note';
	$h++;

	// The vote tab exists as soon as the campaign is frozen: from then on it
	// explains who can vote and when, and serves the vote file.
	if ((int) $object->status !== Campaign::STATUS_DRAFT) {
		$head[$h][0] = dolBuildUrl(dol_buildpath("/salonerp/campaign_vote.php", 1), array('id' => $object->id));
		$head[$h][1] = $langs->trans('CampaignVoteTab');
		$head[$h][2] = 'vote';
		$h++;
	}

	// Results: once the vote has ended (reveal form), then the count.
	if (in_array((int) $object->status, array(Campaign::STATUS_ENDED, Campaign::STATUS_REVEALED), true)) {
		$head[$h][0] = dolBuildUrl(dol_buildpath("/salonerp/campaign_result.php", 1), array('id' => $object->id));
		$head[$h][1] = $langs->trans('CampaignResultTab');
		$head[$h][2] = 'result';
		$h++;
	}

	// Decrypt: integrity checks of a vote file from validation on, its
	// decryption once revealed.
	if ((int) $object->status !== Campaign::STATUS_DRAFT) {
		$head[$h][0] = dolBuildUrl(dol_buildpath("/salonerp/campaign_decrypt.php", 1), array('id' => $object->id));
		$head[$h][1] = $langs->trans('VoteFileDecryptTab');
		$head[$h][2] = 'decrypt';
		$h++;
	}

	// Show more tabs from modules
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'campaign@salonerp');
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'campaign@salonerp', 'remove');

	return $head;
}

/**
 * Explicit CSRF token check for the mutating actions of the campaign pages
 * (add, update, delete, sync, save points, validate, vote), verified regardless of
 * the site's MAIN_SECURITY_CSRF_WITH_TOKEN setting: main.inc.php already
 * performs a similar check when that option is on, but these actions must
 * refuse a forged request even when it is off.
 *
 * @return bool True if the posted token matches the session's current token
 */
function salonerpCheckPostToken()
{
	$posted = GETPOST('token', 'alpha');
	$expected = empty($_SESSION['token']) ? '' : $_SESSION['token'];

	return $posted !== '' && $expected !== '' && hash_equals($expected, $posted);
}

/**
 * Read the vote file posted in the 'votefile' field of the current request:
 * checked as a genuine upload, within SalonerpVoteFile::MAX_SIZE. Never
 * stored: only its content is returned.
 *
 * @return	string|false	File content, or false if no valid file was posted
 */
function salonerpReadUploadedVoteFile()
{
	if (empty($_FILES['votefile']) || !is_array($_FILES['votefile'])) {
		return false;
	}
	$upload = $_FILES['votefile'];
	if ((int) $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']) || (int) $upload['size'] > SalonerpVoteFile::MAX_SIZE) {
		return false;
	}
	$content = file_get_contents($upload['tmp_name']);

	return is_string($content) ? $content : false;
}

/**
 * Print the report of SalonerpVoteFile::analyse(): the file, each check with
 * its verdict, then the decrypted votes when a key was usable.
 *
 * @param	array<string,mixed>	$report	Report
 * @return	void
 */
function salonerpPrintVoteFileReport(array $report)
{
	global $langs;

	if ($report['fatal'] !== '') {
		print '<div class="error">'.dol_escape_htmltag($report['fatal']).'</div>';
		return;
	}

	$name = function ($names, $id) {
		return (isset($names[$id]) && $names[$id] !== '') ? dol_escape_htmltag($names[$id]) : '#'.((int) $id);
	};

	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('Campaign').'</td><td>'.dol_escape_htmltag($report['file']['campaign']).'</td></tr>';
	print '<tr><td>'.$langs->trans('VoteFileGeneratedAt').'</td><td>'.dol_escape_htmltag($report['file']['generated_at']).'</td></tr>';
	print '<tr><td>'.$langs->trans('CampaignVotesRecorded').'</td><td>'.((int) $report['file']['votes']).'</td></tr>';
	print '<tr><td>'.$langs->trans('GenesisHash').'</td><td><code style="word-break: break-all;">'.dol_escape_htmltag($report['file']['genesis_hash']).'</code></td></tr>';
	print '</table>';

	print load_fiche_titre($langs->trans('VoteFileChecks'), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	foreach ($report['checks'] as $check) {
		print '<tr class="oddeven">';
		print '<td class="width25">'.($check['ok'] ? img_picto('', 'tick', 'class="pictofixedwidth"') : img_picto('', 'error', 'class="pictofixedwidth"')).'</td>';
		print '<td class="titlefield"><strong>'.dol_escape_htmltag($check['label']).'</strong></td>';
		print '<td>'.dol_escape_htmltag($check['detail']).'</td>';
		print '</tr>';
	}
	print '</table></div>';

	if ($report['decrypt_error'] !== '') {
		print '<div class="error">'.dol_escape_htmltag($report['decrypt_error']).'</div>';
	}
	if (!is_array($report['ballots'])) {
		return;
	}

	print_barre_liste($langs->trans('VoteFileDecryptedVotes'), 0, $_SERVER['PHP_SELF'], '', '', '', '', count($report['ballots']), count($report['ballots']), 'user', 0, '', '', -1, 0, 1);
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td class="right width50">'.$langs->trans('CampaignSeq').'</td>';
	print '<td>'.$langs->trans('CampaignVoter').'</td>';
	print '<td>'.$langs->trans('Date').'</td>';
	print '<td>'.$langs->trans('CampaignDistribution').'</td>';
	print '</tr>';
	foreach ($report['ballots'] as $ballot) {
		print '<tr class="oddeven">';
		print '<td class="right">'.((int) $ballot['seq']).'</td>';
		if (!isset($ballot['fk_user'])) {
			print '<td colspan="3" class="error">'.dol_escape_htmltag($ballot['error']).'</td>';
		} else {
			$parts = array();
			foreach ($ballot['points'] as $pair) {
				$parts[] = $name($report['thirdparties'], (int) $pair[0]).' : '.((int) $pair[1]);
			}
			print '<td>'.$name($report['voters'], $ballot['fk_user']).'</td>';
			print '<td>'.dol_print_date(dol_stringtotime($ballot['date'], 1), 'dayhour', 'tzuserrel').'</td>';
			print '<td>'.implode(' ; ', $parts);
			if ($ballot['error'] !== '') {
				print '<br><span class="error">'.dol_escape_htmltag($ballot['error']).'</span>';
			}
			print '</td>';
		}
		print '</tr>';
	}
	print '</table></div>';
}

/**
 * Standalone PHP script that checks and decrypts a vote file without
 * Dolibarr: offered for download on the external decryption page, and shown
 * there in full so anyone can read what it does before running it.
 *
 * @return	string	Script source
 */
function salonerpExternalDecryptScript()
{
	return <<<'SCRIPT'
<?php
// Vérifie et déchiffre un fichier des votes salonerp, sans Dolibarr.
// Usage : php dechiffrer-votes.php FICHIER.json CLE_PRIVEE_BASE64
// Nécessite PHP 7.2 ou plus avec l'extension sodium (présente par défaut).
$f = json_decode(file_get_contents($argv[1]), true);
$g = json_decode($f['genesis_payload'], true);
$nom = array();
foreach (array_merge($g['voters'], $g['thirdparties']) as $x) {
	if (is_array($x) && isset($x['name'])) {
		$nom[$x['id']] = $x['name'];
	}
}
$n = function ($id) use ($nom) {
	return (isset($nom[$id]) ? $nom[$id].' ' : '').'#'.$id;
};
echo 'Genèse : ', hash('sha256', $f['genesis_payload']) === $f['genesis_hash'] ? 'intacte' : 'ALTÉRÉE', "\n";
$kp = sodium_crypto_box_keypair_from_secretkey_and_publickey(base64_decode($argv[2]), base64_decode($g['public_key']));
$prev = $f['genesis_hash'];
foreach ($f['votes'] as $v) {
	$json = json_encode(array('ciphertext' => $v['ciphertext'], 'prev_hash' => $prev, 'seq' => $v['seq']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$ok = ($v['prev_hash'] === $prev && hash('sha256', $json) === $v['hash']);
	echo 'Vote ', $v['seq'], ' : chaîne ', $ok ? 'intacte' : 'ALTÉRÉE', "\n";
	$b = json_decode((string) sodium_crypto_box_seal_open(base64_decode($v['ciphertext']), $kp), true);
	if (!is_array($b)) {
		echo "  illisible avec cette clé\n";
	} else {
		echo '  ', $n($b['fk_user']), ', le ', $b['date'], "\n";
		foreach ($b['points'] as $p) {
			echo '    ', $n($p[0]), ' : ', $p[1], "\n";
		}
	}
	$prev = $v['hash'];
}
SCRIPT;
}

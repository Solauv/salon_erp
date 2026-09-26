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
 * Read the receipt optionally posted in the 'receiptfile' field of the
 * current request: unlike the vote file, this field is optional. Never
 * stored: only its content is returned. A present-but-broken upload (too
 * large, failed transfer) is returned as a deliberately invalid, non-empty
 * string rather than silently ignored: SalonerpVoteFile::analyse() then
 * reports it as an unreadable receipt instead of pretending none was given.
 *
 * @return	string	Receipt content, '' if none was posted
 */
function salonerpReadUploadedReceiptFile()
{
	if (empty($_FILES['receiptfile']) || !is_array($_FILES['receiptfile']) || (int) $_FILES['receiptfile']['error'] === UPLOAD_ERR_NO_FILE) {
		return '';
	}
	$upload = $_FILES['receiptfile'];
	if ((int) $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
		return '?';
	}
	$content = file_get_contents($upload['tmp_name']);

	return is_string($content) && $content !== '' ? $content : '?';
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

	if (isset($report['receipt']) && is_array($report['receipt'])) {
		salonerpPrintReceiptCheck($report['receipt']);
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
 * Print the receipt check of SalonerpVoteFile::analyse()'s 'receipt' entry:
 * each individual check, then the verdict, with the ballot in clear (voter
 * and thirdparty names, from the file's own genesis) only when it matches.
 * Needs no campaign key: this works while the vote is still open.
 *
 * The reference the receipt was checked against changes the warning shown
 * next to a matching verdict: a vote file uploaded by the reader (the usual
 * case, $againstOfficial false — decrypt_external.php, or the Decrypt tab
 * when a vote file was dropped there too) is only ever as trustworthy as
 * that file itself, so the reader is told to compare its final hash. The
 * campaign's own Decrypt tab, when checking a receipt alone, instead builds
 * the file itself from its own official chain ($againstOfficial true): the
 * caveat is then about the server being the reference, not about the file.
 *
 * @param	array{fatal:string,verdict:?bool,seq:?int,last_hash?:string,checks:array<array{label:string,ok:bool,detail:string}>,ballot:?array<string,mixed>}	$receipt	Receipt check report
 * @param	bool	$againstOfficial	Whether $receipt was checked against this campaign's own official chain (built server-side) rather than a file the reader supplied
 * @return	void
 */
function salonerpPrintReceiptCheck(array $receipt, $againstOfficial = false)
{
	global $langs;

	print load_fiche_titre($langs->trans('ReceiptCheckTitle'), '', '');

	if ($receipt['fatal'] !== '') {
		print '<div class="error">'.dol_escape_htmltag($receipt['fatal']).'</div>';
		return;
	}

	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	foreach ($receipt['checks'] as $check) {
		print '<tr class="oddeven">';
		print '<td class="width25">'.($check['ok'] ? img_picto('', 'tick', 'class="pictofixedwidth"') : img_picto('', 'error', 'class="pictofixedwidth"')).'</td>';
		print '<td class="titlefield"><strong>'.dol_escape_htmltag($check['label']).'</strong></td>';
		print '<td>'.dol_escape_htmltag($check['detail']).'</td>';
		print '</tr>';
	}
	print '</table></div>';

	if (empty($receipt['verdict'])) {
		print '<div class="error clearboth">'.dol_escape_htmltag($langs->trans('ReceiptVerdictMismatch')).'</div>';
	} else {
		print '<div class="ok clearboth">'.dol_escape_htmltag($langs->trans('ReceiptVerdictMatch', $receipt['seq'])).'</div>';
		if ($againstOfficial) {
			// Built and checked by this very instance, against its own official
			// chain: no file's hash to compare here, but the check is only as
			// trustworthy as this server. An independent check needs a file this
			// server had no hand in producing, on another instance entirely.
			print '<div class="warning clearboth">'.dol_escape_htmltag($langs->trans('ReceiptCheckedAgainstOfficialChain')).'</div>';
		} else {
			// The verdict is only worth the file it was checked against: a forged
			// receipt can come with a forged, self-consistent file.
			print '<div class="warning clearboth">'.dol_escape_htmltag($langs->trans('ReceiptFileAuthenticity', $receipt['last_hash'])).'</div>';
		}
	}
	// The proven vote: shown whenever the ciphertext was reproduced, even if
	// the receipt's readable part was edited, so the reader sees what was
	// really recorded.
	if (is_array($receipt['ballot'])) {
		$b = $receipt['ballot'];
		print '<p><strong>'.dol_escape_htmltag($langs->trans('ReceiptProvenBallotTitle', $receipt['seq'])).'</strong></p>';
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">'.$langs->trans('CampaignVoter').'</td><td>'.dol_escape_htmltag($b['voter']).'</td></tr>';
		print '<tr><td>'.$langs->trans('Date').'</td><td>'.dol_print_date(dol_stringtotime($b['date'], 1), 'dayhour', 'tzuserrel').'</td></tr>';
		print '<tr><td>'.$langs->trans('CampaignDistribution').'</td><td>';
		$parts = array();
		foreach ($b['thirdparties'] as $t) {
			$parts[] = dol_escape_htmltag($t['name']).' : '.((int) $t['points']);
		}
		print implode(' ; ', $parts);
		print '</td></tr>';
		print '</table>';
	}
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
// Usage : php dechiffrer-votes.php FICHIER.json [CLE_PRIVEE_BASE64] [--recu RECU.json]
// La clé privée n'est nécessaire que pour déchiffrer les votes (une fois
// révélée) ; la vérification d'un reçu, elle, ne nécessite aucune clé de
// campagne et fonctionne pendant le vote.
// Nécessite PHP 7.2 ou plus avec l'extension sodium (présente par défaut).
$cleprivee = null;
$fichierrecu = null;
for ($i = 2; $i < count($argv); $i++) {
	if ($argv[$i] === '--recu' && isset($argv[$i + 1])) {
		$fichierrecu = $argv[++$i];
	} elseif ($cleprivee === null) {
		$cleprivee = $argv[$i];
	}
}

$f = json_decode(file_get_contents($argv[1]), true);
$g = json_decode($f['genesis_payload'], true);
// Pas de compatibilité : une genèse figée avant ballot_size n'est pas
// exploitable par ce script (elle ne fixe aucune taille de bourrage).
if (!isset($g['ballot_size']) || !is_int($g['ballot_size']) || $g['ballot_size'] <= 0) {
	fwrite(STDERR, "Genèse invalide : taille de bulletin (ballot_size) absente ou incorrecte. Fichier non conforme.\n");
	exit(1);
}
$tailleBulletin = $g['ballot_size'];
$nom = array();
foreach (array_merge($g['voters'], $g['thirdparties']) as $x) {
	if (is_array($x) && isset($x['id'], $x['name'])) {
		$nom[$x['id']] = $x['name'];
	}
}
$n = function ($id) use ($nom) {
	return (isset($nom[$id]) ? $nom[$id].' ' : '').'#'.$id;
};
$integre = (hash('sha256', $f['genesis_payload']) === $f['genesis_hash']);
echo 'Genèse : ', $integre ? 'intacte' : 'ALTÉRÉE', "\n";

$kp = ($cleprivee !== null) ? sodium_crypto_box_keypair_from_secretkey_and_publickey(base64_decode($cleprivee), base64_decode($g['public_key'])) : null;
$prev = $f['genesis_hash'];
foreach ($f['votes'] as $v) {
	$json = json_encode(array('ciphertext' => $v['ciphertext'], 'prev_hash' => $prev, 'seq' => $v['seq']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$ok = ($v['prev_hash'] === $prev && hash('sha256', $json) === $v['hash']);
	$ok = $ok && strlen(base64_decode($v['ciphertext'])) === $tailleBulletin + SODIUM_CRYPTO_BOX_SEALBYTES;
	$integre = $integre && $ok;
	echo 'Vote ', $v['seq'], ' : chaîne et taille ', $ok ? 'intactes' : 'ALTÉRÉES', "\n";
	if ($kp !== null) {
		// Le bulletin en clair est bourré (sodium_pad) à exactement
		// ballot_size octets : toute autre taille, ou un débourrage qui
		// échoue, est une anomalie et est traitée comme un bulletin illisible.
		$padded = sodium_crypto_box_seal_open(base64_decode($v['ciphertext']), $kp);
		$b = null;
		if ($padded !== false && strlen($padded) === $tailleBulletin) {
			try {
				$clair = sodium_unpad($padded, $tailleBulletin);
				$b = json_decode($clair, true);
			} catch (SodiumException $e) {
				$b = null;
			}
		}
		if (!is_array($b)) {
			echo "  illisible avec cette clé, ou taille inattendue une fois déchiffré (fichier ou clé invalide)\n";
		} else {
			echo '  ', $n($b['fk_user']), ', le ', $b['date'], "\n";
			foreach ($b['points'] as $p) {
				echo '    ', $n($p[0]), ' : ', $p[1], "\n";
			}
		}
	}
	$prev = $v['hash'];
}
echo 'Empreinte finale du fichier : ', $prev, "\n";

// Vérification d'un reçu de vote (--recu) : ne prouve rien seul, doit
// correspondre au chiffré du vote de même rang dans CE fichier. Ne nécessite
// aucune clé de campagne : seule la clé publique de la genèse sert au
// recalcul, exactement comme Campaign::sealBallot() côté serveur.
if ($fichierrecu !== null) {
	$r = json_decode(file_get_contents($fichierrecu), true);
	$vote = null;
	foreach ($f['votes'] as $v) {
		if (is_array($r) && isset($r['seq']) && (int) $v['seq'] === (int) $r['seq']) {
			$vote = $v;
			break;
		}
	}
	$ballot = is_array($r) && isset($r['ballot']) ? json_decode($r['ballot'], true) : null;
	// Un reçu ne se compare qu'à un fichier intègre, et au vote qui porte
	// l'empreinte de chaîne du reçu.
	$ok = $integre && is_array($r) && $vote !== null && is_array($ballot)
		&& hash_equals((string) $f['genesis_hash'], (string) $r['genesis_hash'])
		&& hash_equals((string) $vote['hash'], (string) $r['hash'])
		&& (string) $ballot['campaign'] === (string) $f['campaign']
		&& (int) $ballot['seq'] === (int) $r['seq']
		&& strlen($r['ballot']) < $tailleBulletin;
	if ($ok) {
		$padded = sodium_pad($r['ballot'], $tailleBulletin);
		$ephSk = base64_decode($r['ephemeral_key']);
		$pk = base64_decode($g['public_key']);
		$ephPk = sodium_crypto_box_publickey_from_secretkey($ephSk);
		$nonce = sodium_crypto_generichash($ephPk.$pk, '', SODIUM_CRYPTO_BOX_NONCEBYTES);
		$box = sodium_crypto_box($padded, $nonce, sodium_crypto_box_keypair_from_secretkey_and_publickey($ephSk, $pk));
		$recalcule = base64_encode($ephPk.$box);
		$ok = hash_equals((string) $vote['ciphertext'], $recalcule);
	}
	// Partie lisible (ballot_decoded) : elle doit dire exactement ce que dit
	// le bulletin vérifié, sinon le reçu a été modifié.
	$lisibleOk = false;
	if ($ok) {
		$d = isset($r['ballot_decoded']) && is_array($r['ballot_decoded']) ? $r['ballot_decoded'] : array();
		$dit = array();
		foreach ((isset($d['thirdparties']) && is_array($d['thirdparties'])) ? $d['thirdparties'] : array() as $t) {
			$dit[] = array(isset($t['id']) ? $t['id'] : null, isset($t['points']) ? $t['points'] : null);
		}
		$lisibleOk = isset($d['fk_user'], $d['date']) && $d['fk_user'] === $ballot['fk_user'] && $d['date'] === $ballot['date'] && $dit === $ballot['points'];
		echo '  Vote vérifié : ', $n($ballot['fk_user']), ', le ', $ballot['date'], "\n";
		foreach ($ballot['points'] as $p) {
			echo '    ', $n($p[0]), ' : ', $p[1], "\n";
		}
		if (!$lisibleOk) {
			echo "  PARTIE LISIBLE MODIFIÉE : ballot_decoded ne dit pas ce que dit le vote vérifié ci-dessus.\n";
		}
	}
	$ok = $ok && $lisibleOk;
	echo 'Reçu du vote ', (is_array($r) && isset($r['seq']) ? $r['seq'] : '?'), ' : ', $ok ? 'correspond exactement au vote enregistré' : 'NE CORRESPOND PAS (ce reçu ne prouve rien)', "\n";
	if ($ok) {
		echo "  Valable seulement si ce fichier des votes est authentique : comparez son empreinte finale\n";
		echo "  à celle d'un fichier téléchargé vous-même sur l'instance de la campagne, ou d'autres votants.\n";
	}
}
SCRIPT;
}

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
 * \file        class/salonerpvotefile.class.php
 * \ingroup     salonerp
 * \brief       Analysis of a downloaded vote file: structure, integrity,
 *              agreement with the official chain, decryption. No database
 *              access: the same analysis runs on the campaign's own instance
 *              (Decrypt tab) and on any other one (external decryption).
 */

dol_include_once('/salonerp/class/campaign.class.php');

/**
 * Class SalonerpVoteFile
 */
class SalonerpVoteFile
{
	/** Largest vote file accepted, in bytes. */
	const MAX_SIZE = 5242880;

	/** Format written by Campaign::buildVoteFile(). */
	const FORMAT = 'salonerp-votes/1';

	/**
	 * Analyse a vote file.
	 *
	 * The report always holds 'fatal' (translated message, '' when the file
	 * could be read). When readable, it also holds:
	 *  - 'file': campaign ref, generation date, number of votes;
	 *  - 'checks': list of {label, ok, detail}, translated;
	 *  - 'voters' / 'thirdparties': names by id, taken from the file's own genesis;
	 *  - 'ballots': decrypted votes, or null when no key was given, or when the
	 *    genesis carries no ballot_size (see 'decrypt_error' then);
	 *  - 'decrypt_error': translated reason the key could not be used, or ''.
	 *
	 * No compatibility: a genesis with no ballot_size
	 * fails its own dedicated check and is never decrypted, whatever the key.
	 *
	 * @param	string								$json			File content
	 * @param	string								$privateKeyB64	Revealed private key, '' to only check integrity
	 * @param	?array{ref:string,genesis_hash:string}	$expected	Campaign the file must belong to, null outside it
	 * @param	?array<array{seq:int,prev_hash:string,ciphertext:string,hash:string}>	$official	Official chain, null when not available
	 * @return	array<string,mixed>					Report, see above
	 */
	public static function analyse(string $json, string $privateKeyB64 = '', ?array $expected = null, ?array $official = null): array
	{
		global $langs;

		$langs->load('salonerp@salonerp');

		$file = self::parse($json);
		if (is_string($file)) {
			return array('fatal' => $langs->trans('ErrorVoteFileInvalid', $file));
		}
		$genesis = json_decode($file['genesis_payload'], true);
		$ballotSize = (is_array($genesis) && isset($genesis['ballot_size']) && is_int($genesis['ballot_size']) && $genesis['ballot_size'] > 0) ? $genesis['ballot_size'] : null;

		$report = array(
			'fatal' => '',
			'file' => array(
				'campaign' => $file['campaign'],
				'generated_at' => isset($file['generated_at']) ? (string) $file['generated_at'] : '',
				'votes' => count($file['votes']),
				'genesis_hash' => $file['genesis_hash'],
			),
			'checks' => array(),
			'voters' => self::namesById(isset($genesis['voters']) ? $genesis['voters'] : array()),
			'thirdparties' => self::namesById(isset($genesis['thirdparties']) ? $genesis['thirdparties'] : array()),
			'ballots' => null,
			'decrypt_error' => '',
		);

		$sameCampaign = true;
		if ($expected !== null) {
			$sameCampaign = hash_equals((string) $expected['genesis_hash'], $file['genesis_hash']);
			$report['checks'][] = array(
				'label' => $langs->trans('VoteFileCheckCampaign'),
				'ok' => $sameCampaign,
				'detail' => $sameCampaign ? $langs->trans('VoteFileCheckCampaignOk', $expected['ref']) : $langs->trans('VoteFileCheckCampaignKo', $file['campaign'], $expected['ref']),
			);
		}

		$genesisOk = hash_equals($file['genesis_hash'], hash('sha256', $file['genesis_payload']));
		$report['checks'][] = array(
			'label' => $langs->trans('VoteFileCheckGenesis'),
			'ok' => $genesisOk,
			'detail' => $langs->trans($genesisOk ? 'VoteFileCheckGenesisOk' : 'VoteFileCheckGenesisKo'),
		);

		// Every ciphertext must be exactly ballot_size + the sealed box overhead:
		// checked without any key, so a voter can see during the vote that no
		// ballot's length gives anything away.
		$sizeOk = ($ballotSize !== null);
		if (!$sizeOk) {
			$sizeDetail = $langs->trans('VoteFileCheckBallotSizeMissing');
		} else {
			$sealedSize = $ballotSize + SODIUM_CRYPTO_BOX_SEALBYTES;
			$sizeDetail = $langs->trans('VoteFileCheckBallotSizeOk', $sealedSize);
			foreach ($file['votes'] as $vote) {
				if (strlen((string) base64_decode($vote['ciphertext'], true)) !== $sealedSize) {
					$sizeOk = false;
					$sizeDetail = $langs->trans('VoteFileCheckBallotSizeKo', (int) $vote['seq'], $sealedSize);
					break;
				}
			}
		}
		$report['checks'][] = array(
			'label' => $langs->trans('VoteFileCheckBallotSize'),
			'ok' => $sizeOk,
			'detail' => $sizeDetail,
		);

		$chainError = Campaign::verifyChain($file['genesis_payload'], $file['genesis_hash'], $file['votes']);
		$report['checks'][] = array(
			'label' => $langs->trans('VoteFileCheckChain'),
			'ok' => ($chainError === ''),
			'detail' => ($chainError === '') ? $langs->trans('VoteFileCheckChainOk', count($file['votes'])) : $langs->trans('VoteFileCheckChainKo', $chainError),
		);

		if ($official !== null && $sameCampaign) {
			$report['checks'][] = self::compareWithOfficial($file['votes'], $official);
		}

		if ($privateKeyB64 !== '') {
			$publicKey = isset($genesis['public_key']) ? (string) $genesis['public_key'] : '';
			if ($ballotSize === null) {
				$report['decrypt_error'] = $langs->trans('ErrorCampaignGenesisMissingBallotSize');
			} elseif (!Campaign::checkPrivateKey($privateKeyB64, $publicKey)) {
				$report['decrypt_error'] = $langs->trans('ErrorCampaignPrivateKeyMismatch');
			} else {
				$report['ballots'] = self::openBallots($file['votes'], $privateKeyB64, $publicKey, isset($genesis['ref']) ? (string) $genesis['ref'] : '', $ballotSize);
			}
		}

		return $report;
	}

	/**
	 * Strict structural reading of a vote file.
	 *
	 * @param	string	$json	File content
	 * @return	array{campaign:string,generated_at?:string,genesis_hash:string,genesis_payload:string,votes:array<array{seq:int,prev_hash:string,ciphertext:string,hash:string}>}|string	The file, or the (untranslated) reason it is unreadable
	 */
	protected static function parse(string $json)
	{
		if (strlen($json) > self::MAX_SIZE) {
			return 'file too large';
		}
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return 'not JSON';
		}
		if (!isset($data['format']) || $data['format'] !== self::FORMAT) {
			return 'unknown format';
		}
		foreach (array('campaign', 'genesis_hash', 'genesis_payload') as $key) {
			if (!isset($data[$key]) || !is_string($data[$key])) {
				return 'missing '.$key;
			}
		}
		if (!preg_match('/^[0-9a-f]{64}$/', $data['genesis_hash'])) {
			return 'bad genesis_hash';
		}
		if (!is_array(json_decode($data['genesis_payload'], true))) {
			return 'bad genesis_payload';
		}
		if (!isset($data['votes']) || !is_array($data['votes']) || !array_is_list($data['votes'])) {
			return 'missing votes';
		}

		$votes = array();
		foreach ($data['votes'] as $i => $vote) {
			if (!is_array($vote) || !isset($vote['seq'], $vote['prev_hash'], $vote['ciphertext'], $vote['hash'])
				|| !is_int($vote['seq'])
				|| !is_string($vote['prev_hash']) || !preg_match('/^[0-9a-f]{64}$/', $vote['prev_hash'])
				|| !is_string($vote['hash']) || !preg_match('/^[0-9a-f]{64}$/', $vote['hash'])
				|| !is_string($vote['ciphertext']) || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $vote['ciphertext'])) {
				return 'bad vote at position '.($i + 1);
			}
			$votes[] = array('seq' => $vote['seq'], 'prev_hash' => $vote['prev_hash'], 'ciphertext' => $vote['ciphertext'], 'hash' => $vote['hash']);
		}

		$file = array(
			'campaign' => $data['campaign'],
			'genesis_hash' => $data['genesis_hash'],
			'genesis_payload' => $data['genesis_payload'],
			'votes' => $votes,
		);
		if (isset($data['generated_at']) && is_string($data['generated_at'])) {
			$file['generated_at'] = $data['generated_at'];
		}

		return $file;
	}

	/**
	 * Does the file's chain agree with the official one? A file downloaded
	 * earlier is only a beginning of it, which is fine. Otherwise, name the
	 * first rank where they part.
	 *
	 * @param	array<array{seq:int,prev_hash:string,ciphertext:string,hash:string}>	$votes		The file's votes
	 * @param	array<array{seq:int,prev_hash:string,ciphertext:string,hash:string}>	$official	The official chain
	 * @return	array{label:string,ok:bool,detail:string}
	 */
	protected static function compareWithOfficial(array $votes, array $official): array
	{
		global $langs;

		$label = $langs->trans('VoteFileCheckOfficial');
		foreach ($votes as $i => $vote) {
			if (!isset($official[$i])) {
				return array('label' => $label, 'ok' => false, 'detail' => $langs->trans('VoteFileCheckOfficialMissing', $i + 1, count($official)));
			}
			// The whole entry, not just its hash: a file whose ciphertext was
			// altered but whose recorded hash was left alone must not pass.
			if ($official[$i]['seq'] !== $vote['seq'] || !hash_equals($official[$i]['hash'], $vote['hash'])
				|| !hash_equals($official[$i]['prev_hash'], $vote['prev_hash']) || !hash_equals($official[$i]['ciphertext'], $vote['ciphertext'])) {
				return array('label' => $label, 'ok' => false, 'detail' => $langs->trans('VoteFileCheckOfficialDiverge', $i + 1));
			}
		}

		$detail = $langs->trans('VoteFileCheckOfficialOk', count($votes), count($official));
		if (count($votes) < count($official)) {
			$detail .= ' '.$langs->trans('VoteFileCheckOfficialOlder');
		}

		return array('label' => $label, 'ok' => true, 'detail' => $detail);
	}

	/**
	 * Open each sealed vote of the file. After seal_open(), the plaintext's
	 * length must be exactly $ballotSize and sodium_unpad() must succeed: any
	 * other outcome is an anomaly (wrong ballot_size, tampered padding), not a
	 * key mismatch, and the vote is flagged unreadable just the same.
	 *
	 * @param	array<array{seq:int,prev_hash:string,ciphertext:string,hash:string}>	$votes	The file's votes
	 * @param	string	$privateKeyB64	Private key, already checked against the public key
	 * @param	string	$publicKeyB64	Public key from the file's genesis
	 * @param	string	$ref			Campaign ref from the file's genesis
	 * @param	int		$ballotSize		ballot_size from the file's genesis, already checked to be a positive int
	 * @return	array<array{seq:int,error:string,fk_user?:int,date?:string,points?:array<array{0:int,1:int}>}>
	 */
	protected static function openBallots(array $votes, string $privateKeyB64, string $publicKeyB64, string $ref, int $ballotSize): array
	{
		global $langs;

		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey((string) base64_decode($privateKeyB64, true), (string) base64_decode($publicKeyB64, true));
		$ballots = array();
		foreach ($votes as $vote) {
			$padded = sodium_crypto_box_seal_open((string) base64_decode($vote['ciphertext'], true), $keypair);
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
			if (!is_array($ballot) || !isset($ballot['seq'], $ballot['fk_user'], $ballot['date'], $ballot['points'], $ballot['campaign'])) {
				$ballots[] = array('seq' => $vote['seq'], 'error' => $langs->trans($wrongSize ? 'VoteFileBallotWrongSize' : 'VoteFileBallotUnreadable'));
				continue;
			}
			$error = '';
			if ((int) $ballot['seq'] !== $vote['seq'] || (string) $ballot['campaign'] !== $ref) {
				// A ballot moved from another rank or campaign: shown, flagged.
				$error = $langs->trans('VoteFileBallotMisplaced', (int) $ballot['seq'], (string) $ballot['campaign']);
			}
			$ballots[] = array('seq' => $vote['seq'], 'error' => $error, 'fk_user' => (int) $ballot['fk_user'], 'date' => (string) $ballot['date'], 'points' => (array) $ballot['points']);
		}
		sodium_memzero($keypair);

		return $ballots;
	}

	/**
	 * Names by id from a genesis list: objects {id, name, ...} only. No
	 * compatibility with a genesis frozen before names were added.
	 *
	 * @param	array<mixed>	$list	genesis.voters or genesis.thirdparties
	 * @return	array<int,string>		Name by id ('' when the genesis has none)
	 */
	protected static function namesById(array $list): array
	{
		$names = array();
		foreach ($list as $item) {
			if (is_array($item) && isset($item['id'])) {
				$names[(int) $item['id']] = isset($item['name']) ? (string) $item['name'] : '';
			}
		}

		return $names;
	}
}

<?php
/* Copyright (C) 2026		EBI
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
 * Prepare array of tabs for Campaign: Fiche + Notes only in this lot.
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

	// Show more tabs from modules
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'campaign@salonerp');
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'campaign@salonerp', 'remove');

	return $head;
}

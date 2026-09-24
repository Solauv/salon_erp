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
 * Oeuvre derivee de htdocs/modulebuilder/template/lib/mymodule.lib.php
 * de Dolibarr ERP/CRM (version 24.0.1).
 */

/**
 * \file    salonerp/lib/salonerp.lib.php
 * \ingroup salonerp
 * \brief   Library files with common functions for Salonerp
 */

/**
 * Prepare admin pages header. No setup constant in this lot: the only tab is "About".
 *
 * @return array<array{string,string,string}>
 */
function salonerpAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("salonerp@salonerp");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/salonerp/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	complete_head_from_modules($conf, $langs, null, $head, $h, 'salonerp@salonerp');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'salonerp@salonerp', 'remove');

	return $head;
}

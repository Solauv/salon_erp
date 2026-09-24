-- Copyright (C) 2026		EBI
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.
--
-- Oeuvre derivee de htdocs/modulebuilder/template/sql/llx_mymodule_myobject.sql
-- de Dolibarr ERP/CRM (version 24.0.1).

CREATE TABLE llx_salonerp_campaign(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer NOT NULL DEFAULT 1,
	ref varchar(128) NOT NULL,
	label varchar(255),
	description text,
	note_public text,
	note_private text,
	date_start datetime NOT NULL,
	date_end datetime NOT NULL,
	fk_category integer NOT NULL,
	fk_usergroup integer NOT NULL,
	default_points integer,
	public_key varchar(64) DEFAULT NULL,
	tie_rule varchar(16) NOT NULL DEFAULT 'B_E',
	genesis_hash varchar(64) DEFAULT NULL,
	genesis_payload text DEFAULT NULL,
	date_validation datetime DEFAULT NULL,
	fk_user_valid integer DEFAULT NULL,
	-- Lot 3 : cle privee ressaisie par le responsable a la revelation, puis
	-- affichee a tous (les votes sont alors publics) ; NULL jusque-la.
	private_key varchar(64) DEFAULT NULL,
	date_reveal datetime DEFAULT NULL,
	date_attribution datetime DEFAULT NULL,
	fk_user_attribution integer DEFAULT NULL,
	status smallint NOT NULL DEFAULT 0,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14)
) ENGINE=innodb;

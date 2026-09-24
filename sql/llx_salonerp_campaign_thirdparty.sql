-- Copyright (C) 2026 Solauv
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

-- Tiers d'une campagne : la liste figee sur laquelle les votants repartissent
-- leurs points. Remplie depuis le tag client tant que la campagne est en
-- brouillon, puis figee a la validation : ses ids entrent dans l'empreinte de
-- genese. Volontairement SANS cle etrangere vers llx_societe : supprimer un
-- tiers de Dolibarr ne doit pas alterer une liste figee.
-- name, code_client, zip, town : photo du tiers prise A LA VALIDATION (NULL en
-- brouillon), inscrite elle aussi dans la genese. Elle garde la version
-- originale si le tiers est ensuite modifie ou supprime.
CREATE TABLE llx_salonerp_campaign_thirdparty(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_campaign integer NOT NULL,
	fk_soc integer NOT NULL,
	name varchar(128) NULL,
	code_client varchar(24) NULL,
	zip varchar(25) NULL,
	town varchar(50) NULL
) ENGINE=innodb;

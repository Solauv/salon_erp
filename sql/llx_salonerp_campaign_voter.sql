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

-- Votants d'une campagne, et l'enveloppe de points qui leur est allouee.
-- Aucune donnee de vote (chiffree) dans cette table au lot 1 : voir lot 2.
CREATE TABLE llx_salonerp_campaign_voter(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_campaign integer NOT NULL,
	fk_user integer NOT NULL,
	points integer NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

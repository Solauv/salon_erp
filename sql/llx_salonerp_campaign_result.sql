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

-- Resultat du depouillement, une ligne par tiers de la campagne, calcule UNE
-- fois a la revelation puis fige : l'affichage ne recalcule jamais.
-- fk_user : votant gagnant, NULL si le tiers n'a recu aucun point.
-- rule : 'none' (aucun point), 'max' (plus forte mise seule), 'B' (egalite
-- tranchee par la part d'enveloppe), 'E' (tirage verifiable).
-- detail : JSON du calcul (candidats, mises, enveloppes, empreintes du tirage).
CREATE TABLE llx_salonerp_campaign_result(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_campaign integer NOT NULL,
	fk_soc integer NOT NULL,
	fk_user integer DEFAULT NULL,
	points integer NOT NULL DEFAULT 0,
	rule varchar(8) NOT NULL,
	detail text NOT NULL
) ENGINE=innodb;

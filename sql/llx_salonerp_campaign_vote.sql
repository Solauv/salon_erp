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

-- Votes d'une campagne, chaines par empreinte SHA-256.
-- ciphertext : sealed box sodium (cle publique de la campagne), en base64, du
-- contenu du vote : votant, date et repartition des points. Seul ciphertext,
-- seq, prev_hash et hash vont dans le fichier telechargeable : aucune identite
-- en clair, qui pourrait trahir une strategie de vote.
-- fk_user est garde EN BASE pour interdire un second vote ; il n'est jamais
-- affiche aux autres votants ni ecrit en clair dans le fichier.
-- hash = sha256 du JSON canonique {"ciphertext","prev_hash","seq"} ; prev_hash
-- du premier vote = empreinte de genese de la campagne.
CREATE TABLE llx_salonerp_campaign_vote(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_campaign integer NOT NULL,
	seq integer NOT NULL,
	fk_user integer NOT NULL,
	ciphertext text NOT NULL,
	prev_hash char(64) NOT NULL,
	hash char(64) NOT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;

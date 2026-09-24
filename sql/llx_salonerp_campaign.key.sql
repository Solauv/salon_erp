-- Copyright (C) 2026		Solauv
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
-- Oeuvre derivee de htdocs/modulebuilder/template/sql/llx_mymodule_myobject.key.sql
-- de Dolibarr ERP/CRM (version 24.0.1).

-- Idempotent, run on every activation: covers instances that already had the
-- table before genesis_payload existed (CREATE TABLE above is only applied on
-- a fresh install and is silently ignored by _load_tables() otherwise).
ALTER TABLE llx_salonerp_campaign ADD COLUMN IF NOT EXISTS genesis_payload text DEFAULT NULL;

ALTER TABLE llx_salonerp_campaign ADD INDEX idx_salonerp_campaign_rowid (rowid);
ALTER TABLE llx_salonerp_campaign ADD UNIQUE INDEX uk_salonerp_campaign_ref (ref, entity);
ALTER TABLE llx_salonerp_campaign ADD INDEX idx_salonerp_campaign_entity (entity);
ALTER TABLE llx_salonerp_campaign ADD INDEX idx_salonerp_campaign_fk_category (fk_category);
ALTER TABLE llx_salonerp_campaign ADD INDEX idx_salonerp_campaign_fk_usergroup (fk_usergroup);
ALTER TABLE llx_salonerp_campaign ADD INDEX idx_salonerp_campaign_status (status);

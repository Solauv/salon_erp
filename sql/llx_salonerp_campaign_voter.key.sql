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

ALTER TABLE llx_salonerp_campaign_voter ADD INDEX idx_salonerp_campaign_voter_fk_campaign (fk_campaign);
ALTER TABLE llx_salonerp_campaign_voter ADD INDEX idx_salonerp_campaign_voter_fk_user (fk_user);
ALTER TABLE llx_salonerp_campaign_voter ADD UNIQUE INDEX uk_salonerp_campaign_voter (fk_campaign, fk_user);
ALTER TABLE llx_salonerp_campaign_voter ADD CONSTRAINT fk_salonerp_campaign_voter_campaign FOREIGN KEY (fk_campaign) REFERENCES llx_salonerp_campaign (rowid) ON DELETE CASCADE;

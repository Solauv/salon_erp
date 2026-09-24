-- Copyright (C) 2026 EBI
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

ALTER TABLE llx_salonerp_campaign_thirdparty ADD UNIQUE INDEX uk_salonerp_campaign_thirdparty (fk_campaign, fk_soc);
ALTER TABLE llx_salonerp_campaign_thirdparty ADD CONSTRAINT fk_salonerp_campaign_thirdparty_campaign FOREIGN KEY (fk_campaign) REFERENCES llx_salonerp_campaign (rowid) ON DELETE CASCADE;

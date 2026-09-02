-- Where a monitor is. A location is a place with a name, an address you would
-- recognise, and the coordinates the dashboard map plots it at.
--
-- Coordinates are stored rather than looked up: geocoding an address means
-- calling somebody else's service from a self-hosted install, and a monitoring
-- tool that quietly phones home is not the deal. The map picker on the
-- location form fills the two numbers in with a click.

CREATE TABLE {{locations}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    -- Free text, shown under the name. A street address, a city, a rack
    -- number -- whatever tells the person reading it where to go.
    `address` VARCHAR(255) NULL,
    -- Six decimals is roughly a tenth of a metre, far past anything a world
    -- map can show, and it leaves room for a more precise picker later.
    `latitude` DECIMAL(9,6) NOT NULL,
    `longitude` DECIMAL(9,6) NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_locations_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A monitor sits at one place at most. Deleting the place leaves the monitor
-- alone and merely unpins it: losing history because somebody tidied up a
-- list of addresses would be a poor trade.
ALTER TABLE {{monitors}}
    ADD COLUMN `location_id` INT UNSIGNED NULL COMMENT 'Where this is watched from the map; NULL means it is not pinned anywhere' AFTER `tags`,
    ADD KEY `idx_monitors_location` (`location_id`),
    ADD CONSTRAINT `fk_monitors_location` FOREIGN KEY (`location_id`) REFERENCES {{locations}} (`id`) ON DELETE SET NULL;

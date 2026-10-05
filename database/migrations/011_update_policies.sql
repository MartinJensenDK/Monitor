-- Automatic updates.
--
-- Until now every update on every machine started with somebody pressing a
-- button. An update policy is that press, written down once: check for updates
-- on these weekdays at this time, install them on those weekdays at that time,
-- and -- if it is allowed to -- restart afterwards when the machine says it
-- needs one.
--
-- A policy only ever queues the same named commands a person could. It is not
-- a second way in: a machine still refuses anything it was not installed to
-- allow, and the scheduler only asks machines that have said they will agree.

CREATE TABLE {{update_policies}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,

    -- Days are a bitmask, Monday in the lowest bit and Sunday in the seventh,
    -- so "is today one of them" is a single AND. Times are wall-clock in the
    -- site's own time zone; the scheduler works out what that means in UTC
    -- each day, so a schedule set for 03:00 stays at 03:00 across a change to
    -- or from summer time.
    `check_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `check_days` TINYINT UNSIGNED NOT NULL DEFAULT 127,
    `check_time` TIME NOT NULL DEFAULT '06:00:00',

    `install_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `install_days` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `install_time` TIME NOT NULL DEFAULT '03:00:00',

    -- Off unless somebody turns it on. A restart only follows an install this
    -- policy asked for and that finished, only on a machine that reports it
    -- needs one, and only on a machine installed with --allow-reboot.
    `restart_after` TINYINT(1) NOT NULL DEFAULT 0,

    -- The scheduled moment each half last acted on, in UTC. Compared with the
    -- most recent moment the schedule names: newer means due, the same means
    -- already done. Only real runs are recorded here. A schedule begins at its
    -- next occurrence after a save rather than firing for one that went by a
    -- minute before somebody pressed Save -- that rule reads updated_at.
    `last_check_at` DATETIME NULL,
    `last_install_at` DATETIME NULL,
    `last_check_result` VARCHAR(255) NULL,
    `last_install_result` VARCHAR(255) NULL,

    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_update_policies_user` FOREIGN KEY (`created_by`) REFERENCES {{users}} (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One policy per machine, held on the machine. A column rather than a join
-- table because "at most one" is then the database's rule rather than ours to
-- remember, and two policies can never both decide to install on the same
-- machine at once. Deleting a policy leaves its machines without one.
ALTER TABLE {{devices}}
    ADD COLUMN `update_policy_id` INT UNSIGNED NULL
        COMMENT 'The update policy this machine follows; NULL for none'
        AFTER `allow_reboot`,
    ADD KEY `idx_devices_update_policy` (`update_policy_id`),
    ADD CONSTRAINT `fk_devices_update_policy` FOREIGN KEY (`update_policy_id`)
        REFERENCES {{update_policies}} (`id`) ON DELETE SET NULL;

-- Which policy queued a command, when one did rather than a person. The
-- machine's page names it, so "who asked for this restart at 03:05" has an
-- answer; and the restart step reads it, to restart only after an install the
-- policy itself asked for.
ALTER TABLE {{device_commands}}
    ADD COLUMN `policy_id` INT UNSIGNED NULL
        COMMENT 'The update policy that queued this, if not a person'
        AFTER `requested_by`,
    ADD KEY `idx_device_commands_policy` (`policy_id`, `command`, `status`),
    ADD CONSTRAINT `fk_device_commands_policy` FOREIGN KEY (`policy_id`)
        REFERENCES {{update_policies}} (`id`) ON DELETE SET NULL;

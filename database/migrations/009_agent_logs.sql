-- What the agent did, in its own words.
--
-- Until now a machine's page showed what it *is* -- disks, updates, packages --
-- and a short timeline of things this server worked out about it. What was
-- missing is what the agent itself was doing: that it started, that it updated
-- itself, that a command ran and what the package manager said while it ran.
--
-- Command output in particular is worth streaming rather than waiting for.
-- Installing updates can take minutes, and a progress line arriving while it
-- happens is the difference between watching and wondering.

CREATE TABLE {{device_logs}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    -- Set when the line belongs to a command that was running at the time, so
    -- the interface can show that command's output on its own.
    `command_id` INT UNSIGNED NULL,
    `level` ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
    `message` VARCHAR(1000) NOT NULL,
    -- The machine's own clock, which may be wrong, and ours, which is the one
    -- the ordering is done by. Keeping both means a skewed clock is visible
    -- rather than quietly reshuffling the log.
    `logged_at` DATETIME NULL,
    `received_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_device_logs` (`device_id`, `id`),
    KEY `idx_device_logs_command` (`command_id`),
    KEY `idx_device_logs_age` (`received_at`),
    CONSTRAINT `fk_device_logs_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_device_logs_command` FOREIGN KEY (`command_id`) REFERENCES {{device_commands}} (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE {{devices}}
    -- How much the agent is asked to say. 'debug' includes every poll, which
    -- is a great deal of nothing most of the time; 'info' is the useful
    -- default and still records everything the agent actually does.
    ADD COLUMN `log_level` ENUM('error','warn','info','debug') NOT NULL DEFAULT 'info'
        COMMENT 'How much the agent sends back'
        AFTER `poll_seconds`,

    -- Whether this machine has consented to being updated from here. Set from
    -- the agent's own configuration at enrolment, not from this side: the
    -- decision belongs to whoever installed it.
    ADD COLUMN `self_update` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'The machine allows the agent to replace itself'
        AFTER `agent_version`,

    ADD COLUMN `agent_updated_at` DATETIME NULL AFTER `self_update`;

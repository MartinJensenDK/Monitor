-- Machines that report about themselves.
--
-- Everything so far is watched from the outside: the scheduler reaches out and
-- asks. A server or a laptop cannot be asked that way -- what is worth knowing
-- about it (how full the disk is, which updates are waiting, whether it wants
-- a reboot) is only visible from the inside. So the direction is reversed: a
-- small agent runs on the machine and posts in.
--
-- That reversal is the whole security question here, and it is answered in
-- three places. Enrolment is the only route in, and it trades a shared key for
-- a token that belongs to one machine. The token is stored as a hash, so a
-- database dump does not let anybody speak as somebody else's server. And the
-- agent connects outward only -- no port is opened on the machines being
-- watched, and there is nothing to reach even if this site is compromised,
-- beyond the short list of commands an administrator can queue.

-- The key you hand out when rolling the agent out. It is not what a machine
-- keeps: it is what a machine spends, once, to be given its own token.
--
-- Keeping the two apart is what makes a mistake survivable. A key that leaks
-- is revoked without touching a single machine that already enrolled with it,
-- and a machine whose token leaks is revoked without cutting off the rest.
CREATE TABLE {{enrollment_keys}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    -- Only the hash is kept. The key itself is shown once, at the moment it is
    -- made, and cannot be read back out of here afterwards.
    `token_hash` CHAR(64) NOT NULL,
    -- The first few characters, so the list can say which key is which without
    -- holding enough of one to use it.
    `token_hint` VARCHAR(16) NOT NULL,
    -- What machines arriving on this key are called. 'auto' lets the agent's
    -- own guess stand.
    `kind` ENUM('auto','server','client') NOT NULL DEFAULT 'auto',
    `group_id` INT UNSIGNED NULL COMMENT 'Machines enrolling on this key are shared with this group',
    `location_id` INT UNSIGNED NULL COMMENT 'Where machines enrolling on this key stand, if they all stand together',
    `max_uses` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 means no limit',
    `uses` INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `last_used_at` DATETIME NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_enrollment_keys_token` (`token_hash`),
    KEY `idx_enrollment_keys_group` (`group_id`),
    KEY `idx_enrollment_keys_location` (`location_id`),
    CONSTRAINT `fk_enrollment_keys_group` FOREIGN KEY (`group_id`) REFERENCES {{user_groups}} (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_enrollment_keys_location` FOREIGN KEY (`location_id`) REFERENCES {{locations}} (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_enrollment_keys_user` FOREIGN KEY (`created_by`) REFERENCES {{users}} (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One enrolled machine.
--
-- The wide row is deliberate: it is the snapshot every list and card reads, so
-- showing forty machines costs one query rather than forty. The history lives
-- in device_metrics, and the lists (disks, updates, packages) live in their
-- own tables and are replaced wholesale on each report.
CREATE TABLE {{devices}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- The public identifier. URLs and the agent's own config use this, so an
    -- auto-increment id is never a thing you can guess your way along.
    `uuid` CHAR(36) NOT NULL,
    `name` VARCHAR(190) NOT NULL COMMENT 'What a person calls it; starts as the hostname',
    `hostname` VARCHAR(190) NOT NULL,
    `fqdn` VARCHAR(255) NULL,

    -- Which of the two pages it appears on. The agent guesses, and its guess
    -- is kept separately: once somebody has said otherwise by hand, the guess
    -- stops overruling them on every report.
    `kind` ENUM('server','client') NOT NULL DEFAULT 'server',
    `agent_kind` ENUM('server','client','unknown') NOT NULL DEFAULT 'unknown',
    `kind_locked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Somebody set the kind by hand; stop believing the agent',

    -- As with the enrolment key, only the hash. Comparing a hash of what was
    -- presented against this is a plain indexed lookup, and the token itself
    -- exists in exactly one place: the config file on the machine.
    `token_hash` CHAR(64) NOT NULL,
    `token_hint` VARCHAR(16) NOT NULL,
    `token_issued_at` DATETIME NOT NULL,
    `enrollment_key_id` INT UNSIGNED NULL,

    `location_id` INT UNSIGNED NULL,

    `os_family` ENUM('linux','windows','macos','other') NOT NULL DEFAULT 'other',
    `os_name` VARCHAR(120) NULL,
    `os_version` VARCHAR(80) NULL,
    `kernel` VARCHAR(120) NULL,
    `arch` VARCHAR(32) NULL,
    `manufacturer` VARCHAR(120) NULL,
    `model` VARCHAR(120) NULL,
    `serial_number` VARCHAR(120) NULL,
    `cpu_model` VARCHAR(190) NULL,
    `cpu_cores` SMALLINT UNSIGNED NULL,
    `memory_bytes` BIGINT UNSIGNED NULL,
    `virtualisation` VARCHAR(40) NULL,

    `agent_version` VARCHAR(32) NULL,
    -- Where the report actually came from, and where the machine believes it
    -- lives. They differ behind NAT, and the difference is worth seeing.
    `report_ip` VARCHAR(45) NULL,
    `primary_ip` VARCHAR(45) NULL,

    -- Latest reading of each thing a card shows.
    `cpu_percent` DECIMAL(5,2) NULL,
    `memory_used_bytes` BIGINT UNSIGNED NULL,
    `swap_used_bytes` BIGINT UNSIGNED NULL,
    `disk_total_bytes` BIGINT UNSIGNED NULL,
    `disk_used_bytes` BIGINT UNSIGNED NULL,
    `load1` DECIMAL(8,2) NULL,
    `uptime_seconds` BIGINT UNSIGNED NULL,
    `boot_at` DATETIME NULL,

    `updates_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `updates_security` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `reboot_required` TINYINT(1) NOT NULL DEFAULT 0,
    `package_count` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `service_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `port_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- 'pending' is enrolled but never heard from. 'stale' is late. 'offline'
    -- is late enough to worry about. 'disabled' is switched off here, and the
    -- agent is told to stop reporting.
    `status` ENUM('pending','online','stale','offline','disabled') NOT NULL DEFAULT 'pending',
    `status_since` DATETIME NULL,
    `interval_seconds` INT UNSIGNED NOT NULL DEFAULT 300 COMMENT 'How often the agent is told to report',
    `commands_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` VARCHAR(500) NULL,

    `enrolled_at` DATETIME NOT NULL,
    `last_seen_at` DATETIME NULL,
    `last_report_at` DATETIME NULL,
    `revoked_at` DATETIME NULL COMMENT 'Token refused from this moment on; the row stays for the history',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_devices_uuid` (`uuid`),
    UNIQUE KEY `uq_devices_token` (`token_hash`),
    KEY `idx_devices_kind_status` (`kind`, `status`),
    KEY `idx_devices_seen` (`last_seen_at`),
    KEY `idx_devices_location` (`location_id`),
    CONSTRAINT `fk_devices_key` FOREIGN KEY (`enrollment_key_id`) REFERENCES {{enrollment_keys}} (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_devices_location` FOREIGN KEY (`location_id`) REFERENCES {{locations}} (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_devices_user` FOREIGN KEY (`created_by`) REFERENCES {{users}} (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who may see a machine, the same way monitor_group_access decides who may see
-- a monitor. Same shape on purpose: one idea of sharing, learned once.
CREATE TABLE {{device_group_access}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    `group_id` INT UNSIGNED NOT NULL,
    `access` ENUM('view','edit') NOT NULL DEFAULT 'view',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_group` (`device_id`, `group_id`),
    KEY `idx_device_group_group` (`group_id`),
    CONSTRAINT `fk_device_access_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_device_access_group` FOREIGN KEY (`group_id`) REFERENCES {{user_groups}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per report: what the machine looked like at that moment. This is
-- what the charts read, and what retention prunes.
CREATE TABLE {{device_metrics}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    `captured_at` DATETIME NOT NULL,
    `cpu_percent` DECIMAL(5,2) NULL,
    `memory_used_bytes` BIGINT UNSIGNED NULL,
    `memory_total_bytes` BIGINT UNSIGNED NULL,
    `swap_used_bytes` BIGINT UNSIGNED NULL,
    `disk_used_bytes` BIGINT UNSIGNED NULL,
    `disk_total_bytes` BIGINT UNSIGNED NULL,
    `load1` DECIMAL(8,2) NULL,
    `load5` DECIMAL(8,2) NULL,
    `load15` DECIMAL(8,2) NULL,
    `process_count` MEDIUMINT UNSIGNED NULL,
    `uptime_seconds` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_device_metrics` (`device_id`, `captured_at`),
    CONSTRAINT `fk_device_metrics_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The four lists a report carries. Each is replaced wholesale every time,
-- because a package that was uninstalled should disappear rather than linger.
CREATE TABLE {{device_disks}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    `mount` VARCHAR(190) NOT NULL,
    `source` VARCHAR(190) NULL COMMENT 'Block device or volume label',
    `filesystem` VARCHAR(40) NULL,
    `total_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `used_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_mount` (`device_id`, `mount`),
    CONSTRAINT `fk_device_disks_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{device_updates}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(190) NOT NULL,
    `current_version` VARCHAR(120) NULL,
    `available_version` VARCHAR(120) NULL,
    `source` VARCHAR(80) NULL COMMENT 'Repository, or the Windows update category',
    `is_security` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_device_updates` (`device_id`, `is_security`),
    KEY `idx_device_updates_name` (`name`),
    CONSTRAINT `fk_device_updates_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{device_packages}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(190) NOT NULL,
    `version` VARCHAR(120) NULL,
    `publisher` VARCHAR(190) NULL,
    `source` VARCHAR(40) NULL COMMENT 'dpkg, rpm, pacman, msi, appx ...',
    PRIMARY KEY (`id`),
    KEY `idx_device_packages` (`device_id`, `name`),
    -- Answering "who still runs version 1.2 of this?" is the reason the whole
    -- list is worth keeping, so name is indexed across every machine.
    KEY `idx_device_packages_name` (`name`, `version`),
    CONSTRAINT `fk_device_packages_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{device_services}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(190) NOT NULL,
    `display_name` VARCHAR(190) NULL,
    `state` VARCHAR(24) NULL COMMENT 'running, stopped, failed ...',
    `startup` VARCHAR(24) NULL COMMENT 'enabled, disabled, manual ...',
    PRIMARY KEY (`id`),
    KEY `idx_device_services` (`device_id`, `state`),
    CONSTRAINT `fk_device_services_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{device_ports}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    `protocol` VARCHAR(8) NOT NULL DEFAULT 'tcp',
    `address` VARCHAR(45) NULL,
    `port` INT UNSIGNED NOT NULL,
    `process` VARCHAR(190) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_device_ports` (`device_id`, `port`),
    CONSTRAINT `fk_device_ports_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Work an administrator has queued for a machine.
--
-- The command column holds a name, never a command line: the agent has a fixed
-- list of things it knows how to do and refuses anything not on it. Whatever
-- ends up in here, the worst it can ask for is one of those.
CREATE TABLE {{device_commands}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `device_id` INT UNSIGNED NOT NULL,
    `command` VARCHAR(40) NOT NULL,
    `status` ENUM('queued','claimed','done','failed','expired','cancelled') NOT NULL DEFAULT 'queued',
    `requested_by` INT UNSIGNED NULL,
    `requested_at` DATETIME NOT NULL,
    -- A command nobody collected must not sit waiting forever: a machine that
    -- comes back after a month should not immediately start rebooting.
    `expires_at` DATETIME NOT NULL,
    `claimed_at` DATETIME NULL,
    `finished_at` DATETIME NULL,
    `exit_code` INT NULL,
    `output` TEXT NULL,
    `error` VARCHAR(500) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_commands_uuid` (`uuid`),
    KEY `idx_device_commands` (`device_id`, `status`),
    CONSTRAINT `fk_device_commands_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_device_commands_user` FOREIGN KEY (`requested_by`) REFERENCES {{users}} (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What happened to a machine, in words. Went quiet, came back, wants a reboot,
-- picked up security updates. The device page reads it as a timeline.
CREATE TABLE {{device_events}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(40) NOT NULL,
    `severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `summary` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_device_events` (`device_id`, `created_at`),
    CONSTRAINT `fk_device_events_device` FOREIGN KEY (`device_id`) REFERENCES {{devices}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

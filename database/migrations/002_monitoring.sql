-- Monitors, their results, the rollups the charts read from, and incidents.

CREATE TABLE {{monitors}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `type` ENUM('http','endpoint','ping','port') NOT NULL DEFAULT 'http',
    `target` VARCHAR(500) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `interval_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `timeout_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    `retries` TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `degraded_ms` INT UNSIGNED NULL,
    `config` JSON NULL,
    `tags` VARCHAR(255) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_monitors_uuid` (`uuid`),
    KEY `idx_monitors_enabled` (`enabled`),
    KEY `idx_monitors_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sharing: a monitor can be handed to several groups, each with its own level.
CREATE TABLE {{monitor_group_access}} (
    `monitor_id` INT UNSIGNED NOT NULL,
    `group_id` INT UNSIGNED NOT NULL,
    `access` ENUM('view','edit') NOT NULL DEFAULT 'view',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`monitor_id`, `group_id`),
    KEY `idx_mga_group` (`group_id`),
    CONSTRAINT `fk_mga_monitor` FOREIGN KEY (`monitor_id`) REFERENCES {{monitors}} (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mga_group` FOREIGN KEY (`group_id`) REFERENCES {{user_groups}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per monitor holding everything the dashboard needs, so the live
-- endpoint never touches the raw checks table.
CREATE TABLE {{monitor_status}} (
    `monitor_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pending','up','degraded','down','paused') NOT NULL DEFAULT 'pending',
    `status_since` DATETIME NULL,
    `last_check_at` DATETIME NULL,
    `next_check_at` DATETIME NULL,
    `last_response_ms` INT UNSIGNED NULL,
    `last_http_code` SMALLINT UNSIGNED NULL,
    `last_error` VARCHAR(500) NULL,
    `consecutive_failures` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `consecutive_successes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `current_incident_id` INT UNSIGNED NULL,
    `cert_expires_at` DATETIME NULL,
    `cert_issuer` VARCHAR(190) NULL,
    `uptime_24h` FLOAT NULL,
    `uptime_7d` FLOAT NULL,
    `uptime_30d` FLOAT NULL,
    `avg_ms_24h` INT UNSIGNED NULL,
    `p95_ms_24h` INT UNSIGNED NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`monitor_id`),
    KEY `idx_status_due` (`next_check_at`),
    KEY `idx_status_state` (`status`),
    CONSTRAINT `fk_status_monitor` FOREIGN KEY (`monitor_id`) REFERENCES {{monitors}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{checks}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `monitor_id` INT UNSIGNED NOT NULL,
    `checked_at` DATETIME NOT NULL,
    `status` ENUM('up','degraded','down') NOT NULL,
    `response_ms` INT UNSIGNED NULL,
    `connect_ms` INT UNSIGNED NULL,
    `http_code` SMALLINT UNSIGNED NULL,
    `error_code` VARCHAR(48) NULL,
    `error_message` VARCHAR(500) NULL,
    `attempt` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `meta` JSON NULL,
    PRIMARY KEY (`id`),
    KEY `idx_checks_monitor_time` (`monitor_id`, `checked_at`),
    KEY `idx_checks_time` (`checked_at`),
    CONSTRAINT `fk_checks_monitor` FOREIGN KEY (`monitor_id`) REFERENCES {{monitors}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{stats_minute}} (
    `monitor_id` INT UNSIGNED NOT NULL,
    `bucket` DATETIME NOT NULL,
    `ok_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `degraded_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `fail_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `avg_ms` INT UNSIGNED NULL,
    `min_ms` INT UNSIGNED NULL,
    `max_ms` INT UNSIGNED NULL,
    `p95_ms` INT UNSIGNED NULL,
    PRIMARY KEY (`monitor_id`, `bucket`),
    KEY `idx_stats_minute_bucket` (`bucket`),
    CONSTRAINT `fk_stats_minute_monitor` FOREIGN KEY (`monitor_id`) REFERENCES {{monitors}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{stats_hour}} (
    `monitor_id` INT UNSIGNED NOT NULL,
    `bucket` DATETIME NOT NULL,
    `ok_count` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `degraded_count` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `fail_count` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `avg_ms` INT UNSIGNED NULL,
    `min_ms` INT UNSIGNED NULL,
    `max_ms` INT UNSIGNED NULL,
    `p95_ms` INT UNSIGNED NULL,
    PRIMARY KEY (`monitor_id`, `bucket`),
    KEY `idx_stats_hour_bucket` (`bucket`),
    CONSTRAINT `fk_stats_hour_monitor` FOREIGN KEY (`monitor_id`) REFERENCES {{monitors}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{stats_day}} (
    `monitor_id` INT UNSIGNED NOT NULL,
    `bucket` DATE NOT NULL,
    `ok_count` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `degraded_count` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `fail_count` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `avg_ms` INT UNSIGNED NULL,
    `min_ms` INT UNSIGNED NULL,
    `max_ms` INT UNSIGNED NULL,
    `p95_ms` INT UNSIGNED NULL,
    `downtime_seconds` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`monitor_id`, `bucket`),
    CONSTRAINT `fk_stats_day_monitor` FOREIGN KEY (`monitor_id`) REFERENCES {{monitors}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{incidents}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `monitor_id` INT UNSIGNED NOT NULL,
    `status` ENUM('open','resolved') NOT NULL DEFAULT 'open',
    `severity` ENUM('down','degraded') NOT NULL DEFAULT 'down',
    `started_at` DATETIME NOT NULL,
    `resolved_at` DATETIME NULL,
    `duration_seconds` INT UNSIGNED NULL,
    `cause` VARCHAR(64) NULL,
    `last_error` VARCHAR(500) NULL,
    `failed_checks` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `acknowledged_by` INT UNSIGNED NULL,
    `acknowledged_at` DATETIME NULL,
    `notified_at` DATETIME NULL,
    `resend_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_incidents_monitor` (`monitor_id`, `started_at`),
    KEY `idx_incidents_status` (`status`),
    CONSTRAINT `fk_incidents_monitor` FOREIGN KEY (`monitor_id`) REFERENCES {{monitors}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification plumbing. Created now so stage two adds behaviour, not tables.
CREATE TABLE {{notification_channels}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `type` ENUM('email') NOT NULL DEFAULT 'email',
    `config` JSON NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_channels_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{monitor_notification_settings}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `monitor_id` INT UNSIGNED NOT NULL,
    `channel_id` INT UNSIGNED NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `notify_down` TINYINT(1) NOT NULL DEFAULT 1,
    `notify_up` TINYINT(1) NOT NULL DEFAULT 1,
    `notify_degraded` TINYINT(1) NOT NULL DEFAULT 0,
    `notify_cert_expiry` TINYINT(1) NOT NULL DEFAULT 1,
    `cert_expiry_days` SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    `failure_threshold` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `resend_after_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `quiet_hours_start` TIME NULL,
    `quiet_hours_end` TIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_monitor_channel` (`monitor_id`, `channel_id`),
    CONSTRAINT `fk_mns_monitor` FOREIGN KEY (`monitor_id`) REFERENCES {{monitors}} (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mns_channel` FOREIGN KEY (`channel_id`) REFERENCES {{notification_channels}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{notification_log}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `monitor_id` INT UNSIGNED NULL,
    `incident_id` INT UNSIGNED NULL,
    `channel_id` INT UNSIGNED NULL,
    `event` VARCHAR(32) NOT NULL,
    `recipient` VARCHAR(190) NULL,
    `status` ENUM('sent','failed','skipped') NOT NULL,
    `error` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_notiflog_monitor` (`monitor_id`, `created_at`),
    KEY `idx_notiflog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

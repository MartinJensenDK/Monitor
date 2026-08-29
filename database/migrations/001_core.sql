-- Core identity: users, the groups that scope access, and site plumbing.

CREATE TABLE {{users}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `role` ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
    `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
    `locale` VARCHAR(8) NOT NULL DEFAULT 'en',
    `theme` VARCHAR(8) NOT NULL DEFAULT 'system',
    `auth_provider` ENUM('local','entra') NOT NULL DEFAULT 'local',
    `external_id` VARCHAR(64) NULL,
    `synced_at` DATETIME NULL,
    `last_login_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_external` (`external_id`),
    KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{remember_tokens}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_remember_user` (`user_id`),
    CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES {{users}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{password_resets}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_reset_user` (`user_id`),
    CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES {{users}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{login_attempts}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier` VARCHAR(190) NOT NULL,
    `ip` VARCHAR(45) NOT NULL,
    `succeeded` TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_attempts_identifier` (`identifier`, `attempted_at`),
    KEY `idx_attempts_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Groups decide which monitors a user can reach. `source` marks whether the
-- group is maintained here or mirrored from Microsoft Entra ID.
CREATE TABLE {{user_groups}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL,
    `source` ENUM('local','entra') NOT NULL DEFAULT 'local',
    `external_id` VARCHAR(64) NULL,
    `synced_at` DATETIME NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_groups_name` (`name`),
    UNIQUE KEY `uq_groups_external` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{group_user}} (
    `group_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `source` ENUM('local','entra') NOT NULL DEFAULT 'local',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`group_id`, `user_id`),
    KEY `idx_group_user_user` (`user_id`),
    CONSTRAINT `fk_group_user_group` FOREIGN KEY (`group_id`) REFERENCES {{user_groups}} (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_group_user_user` FOREIGN KEY (`user_id`) REFERENCES {{users}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reserved for the Entra stage: an Entra group can grant a role.
CREATE TABLE {{group_role_map}} (
    `group_id` INT UNSIGNED NOT NULL,
    `role` ENUM('admin','editor','viewer') NOT NULL,
    PRIMARY KEY (`group_id`),
    CONSTRAINT `fk_role_map_group` FOREIGN KEY (`group_id`) REFERENCES {{user_groups}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{settings}} (
    `key` VARCHAR(64) NOT NULL,
    `value` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {{audit_log}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL,
    `user_label` VARCHAR(190) NULL,
    `action` VARCHAR(64) NOT NULL,
    `entity` VARCHAR(48) NULL,
    `entity_id` INT UNSIGNED NULL,
    `summary` VARCHAR(255) NULL,
    `meta` JSON NULL,
    `ip` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_audit_created` (`created_at`),
    KEY `idx_audit_entity` (`entity`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

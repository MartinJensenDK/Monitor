-- Profile photos, mirrored from Entra ID along with the rest of the account.
--
-- The picture lives in its own table rather than in a column on users: user
-- rows are read with SELECT * on nearly every request, and dragging a photo
-- through the session lookup would cost far more than it saves. What users
-- does carry is the timestamp -- enough to know a photo exists and to version
-- its URL, without touching the bytes.

ALTER TABLE {{users}}
    ADD COLUMN `photo_updated_at` DATETIME NULL COMMENT 'When the mirrored photo last changed; NULL means there is none' AFTER `synced_at`;

CREATE TABLE {{user_photos}} (
    `user_id` INT UNSIGNED NOT NULL,
    -- Microsoft's ETag for the picture. Sent back on the next sync so an
    -- unchanged photo answers 304 and costs nothing to download.
    `etag` VARCHAR(190) NULL,
    `content_type` VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
    -- NULL is a real answer: we asked, and this person has no photo. Storing
    -- that stops the sync asking again every hour.
    `bytes` MEDIUMBLOB NULL,
    `checked_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_user_photos_user` FOREIGN KEY (`user_id`) REFERENCES {{users}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

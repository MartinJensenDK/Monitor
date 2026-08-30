<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

/**
 * The mirrored profile photos.
 *
 * Everything a directory hands over is treated as untrusted bytes: the picture
 * is decoded, scaled to the size the interface actually shows, and re-encoded
 * before it is stored. What comes back out is therefore always an image this
 * server made, never a file a directory uploaded.
 */
final class UserPhotos
{
    /** The avatar is 30px, so this covers a retina screen with room to spare. */
    public const SIZE = 120;

    /** Anything larger than this is refused before it is decoded. */
    private const MAX_SOURCE_BYTES = 4194304;

    /** How long a photo is trusted before the sync asks Microsoft again. */
    public const TTL = 86400;

    /** @return array{user_id:int,etag:?string,content_type:string,bytes:?string,checked_at:string,updated_at:string}|null */
    public static function find(int $userId): ?array
    {
        /** @var array{user_id:int,etag:?string,content_type:string,bytes:?string,checked_at:string,updated_at:string}|null $row */
        $row = Db::selectOne('SELECT * FROM {{user_photos}} WHERE `user_id` = ? LIMIT 1', [$userId]);

        return $row;
    }

    /** The ETag last stored for this person, for the next conditional request. */
    public static function etag(int $userId): string
    {
        $row = self::find($userId);

        return (string) ($row['etag'] ?? '');
    }

    /**
     * Whether the photo is old enough to be worth asking about again. Someone
     * who has never been checked always is.
     */
    public static function due(int $userId, int $ttl = self::TTL): bool
    {
        $checked = Db::value('SELECT `checked_at` FROM {{user_photos}} WHERE `user_id` = ? LIMIT 1', [$userId]);
        if (!is_string($checked) || $checked === '') {
            return true;
        }

        return (strtotime($checked . ' UTC') ?: 0) < time() - $ttl;
    }

    /**
     * Store a picture. Returns false when the bytes could not be read as an
     * image, which is left to the caller to record rather than to throw.
     */
    public static function store(int $userId, string $bytes, string $etag, string $now): bool
    {
        $image = self::normalise($bytes);
        if ($image === null) {
            // Bytes we cannot decode are recorded as "no photo" rather than
            // left pending: the person has no picture as far as this interface
            // is concerned, and saying so stops the sync fetching the same
            // broken file every hour.
            self::clear($userId, $now);

            return false;
        }

        self::upsert($userId, [
            'etag' => $etag !== '' ? $etag : null,
            'content_type' => $image['type'],
            'bytes' => $image['bytes'],
            'checked_at' => $now,
            'updated_at' => $now,
        ]);

        Db::update('users', ['photo_updated_at' => $now], ['id' => $userId]);

        return true;
    }

    /** The directory says there is no photo, or there no longer is one. */
    public static function clear(int $userId, string $now): void
    {
        self::upsert($userId, [
            'etag' => null,
            'content_type' => 'image/jpeg',
            'bytes' => null,
            'checked_at' => $now,
            'updated_at' => $now,
        ]);

        Db::update('users', ['photo_updated_at' => null], ['id' => $userId]);
    }

    /**
     * Unchanged since last time: nothing to write but the date we asked.
     *
     * Deliberately does nothing when there is no row yet. MySQL reports zero
     * affected rows both for "no such row" and for "the value was already
     * that", so an existence check cannot be read out of the update — and a
     * person we have never managed to fetch should simply be tried again on
     * the next sync, not recorded as having no picture.
     */
    public static function touch(int $userId, string $now): void
    {
        Db::execute('UPDATE {{user_photos}} SET `checked_at` = ? WHERE `user_id` = ?', [$now, $userId]);
    }

    /** @param array<string,string|null> $fields */
    private static function upsert(int $userId, array $fields): void
    {
        $existing = Db::value('SELECT `user_id` FROM {{user_photos}} WHERE `user_id` = ? LIMIT 1', [$userId]);

        if ($existing === null || $existing === false) {
            Db::insert('user_photos', ['user_id' => $userId] + $fields);

            return;
        }

        Db::update('user_photos', $fields, ['user_id' => $userId]);
    }

    /**
     * Decode, scale to SIZE, and re-encode. Without GD the picture is passed
     * through as it came, so an installation missing the extension still shows
     * photos rather than none at all.
     *
     * @return array{bytes:string,type:string}|null
     */
    private static function normalise(string $bytes): ?array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_SOURCE_BYTES) {
            return null;
        }

        if (!function_exists('imagecreatefromstring')) {
            return self::looksLikeImage($bytes) ? ['bytes' => $bytes, 'type' => 'image/jpeg'] : null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $longest = max($width, $height);

        if ($longest > self::SIZE) {
            $scaled = imagescale(
                $source,
                (int) round($width * self::SIZE / $longest),
                (int) round($height * self::SIZE / $longest)
            );

            if ($scaled !== false) {
                imagedestroy($source);
                $source = $scaled;
            }
        }

        ob_start();
        $written = imagejpeg($source, null, 82);
        $encoded = (string) ob_get_clean();
        imagedestroy($source);

        if (!$written || $encoded === '') {
            return null;
        }

        return ['bytes' => $encoded, 'type' => 'image/jpeg'];
    }

    /** The magic numbers of the formats a directory can hand out. */
    private static function looksLikeImage(string $bytes): bool
    {
        return str_starts_with($bytes, "\xFF\xD8\xFF")            // JPEG
            || str_starts_with($bytes, "\x89PNG\r\n\x1A\n")       // PNG
            || str_starts_with($bytes, 'GIF8');                   // GIF
    }
}

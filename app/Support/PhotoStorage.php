<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for where store & visit photos are stored and how
 * their public URLs are built.
 *
 * Photos must never live on the private "local" disk (storage/app/private),
 * because that disk is not web accessible. When object storage (s3) is not the
 * configured default we fall back to the "public" disk so files are always
 * reachable through the /storage symlink.
 */
class PhotoStorage
{
    /**
     * The disk used to store and serve photos. Never returns "local".
     */
    public static function disk(): string
    {
        $default = config('filesystems.default');

        if ($default === 's3') {
            return 's3';
        }

        // Any explicitly configured cloud disk other than the local/public ones.
        if ($default && ! in_array($default, ['local', 'public'], true)) {
            return $default;
        }

        return 'public';
    }

    /**
     * Build the public URL for a stored photo path.
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // Already a fully qualified URL (e.g. legacy/migrated data)
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (self::disk() === 's3') {
            return Storage::disk('s3')->url($path);
        }

        if (request()) {
            return request()->schemeAndHttpHost().'/storage/'.ltrim($path, '/');
        }

        return Storage::disk('public')->url($path);
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
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
     * Write photo contents to the photo disk and fail loudly on error.
     *
     * The S3 disk is configured with `throw => false`, so a failed upload
     * (e.g. an unreachable/mistyped AWS_ENDPOINT) only returns false instead
     * of raising. Without this guard the caller would keep the DB `photo_path`
     * pointing at an object that was never written, i.e. "upload succeeds but
     * the file never lands in object storage".
     *
     * @return string The stored path, for convenient chaining.
     */
    public static function put(string $path, string $contents, ?string $disk = null): string
    {
        $disk ??= self::disk();

        $stored = Storage::disk($disk)->put($path, $contents, 'public');

        if ($stored === false) {
            $endpoint = config("filesystems.disks.{$disk}.endpoint") ?? '-';
            Log::error("PhotoStorage: unable to write [{$path}] to disk [{$disk}] (endpoint: {$endpoint}). Check object storage credentials and endpoint.");

            throw new \RuntimeException('Gagal menyimpan foto ke object storage. Periksa konfigurasi penyimpanan.');
        }

        return $path;
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

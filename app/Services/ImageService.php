<?php

namespace App\Services;

use App\Support\PhotoStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    /**
     * Compress an uploaded image (or raw binary string) and store it.
     * Uses GD: auto-EXIF rotation, downscale to maxDim×maxDim, JPEG quality 70.
     * Defaults to the disk PhotoStorage uses to build public URLs, so the file
     * always lives on the same disk the app advertises.
     *
     * @param  UploadedFile|string  $file   Uploaded file or raw binary
     * @param  string               $folder Folder inside the disk (e.g. 'stores', 'visits')
     * @param  string|null          $disk   Storage disk — null means use PhotoStorage::disk()
     * @param  int                  $maxDim Max width/height in pixels (default 1200)
     * @param  int                  $quality JPEG quality 1–100 (default 70)
     * @return string|null          Relative path stored, or null on empty input
     */
    public function compressAndStore(
        UploadedFile|string $file,
        string $folder = 'images',
        ?string $disk = null,
        int $maxDim = 1200,
        int $quality = 70,
    ): ?string {
        $disk ??= PhotoStorage::disk();
        $fileName = $folder.'/'.Str::uuid().'.jpg';

        $binary = $file instanceof UploadedFile ? file_get_contents($file->getRealPath()) : $file;
        if (empty($binary)) {
            return null;
        }

        // Only the compression is best-effort; storing must never fail silently.
        $payload = null;

        try {
            // Create GD image from binary string
            $source = @imagecreatefromstring($binary);
            if ($source) {
                // Correct EXIF orientation (phone cameras)
                if ($file instanceof UploadedFile && function_exists('exif_read_data')) {
                    try {
                        $exif = @exif_read_data($file->getRealPath());
                        if (! empty($exif['Orientation'])) {
                            $source = match ($exif['Orientation']) {
                                3 => imagerotate($source, 180, 0),
                                6 => imagerotate($source, -90, 0),
                                8 => imagerotate($source, 90, 0),
                                default => $source,
                            };
                        }
                    } catch (\Throwable) {
                        // Ignore EXIF errors
                    }
                }

                $origWidth  = imagesx($source);
                $origHeight = imagesy($source);

                if ($origWidth > $maxDim || $origHeight > $maxDim) {
                    if ($origWidth >= $origHeight) {
                        $newWidth  = $maxDim;
                        $newHeight = (int) round(($origHeight / $origWidth) * $maxDim);
                    } else {
                        $newHeight = $maxDim;
                        $newWidth  = (int) round(($origWidth / $origHeight) * $maxDim);
                    }

                    $resized = imagecreatetruecolor($newWidth, $newHeight);
                    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
                    imagedestroy($source);
                    $source = $resized;
                }

                ob_start();
                imagejpeg($source, null, $quality);
                $payload = ob_get_clean();
                imagedestroy($source);
            }
        } catch (\Throwable $e) {
            Log::warning("ImageService compression error ({$folder}): ".$e->getMessage());
        }

        // Could not decode/compress (e.g. unsupported format): store the original bytes as-is.
        if ($payload === null) {
            if ($file instanceof UploadedFile) {
                return $file->store($folder, $disk);
            }

            $payload = $binary;
        }

        return PhotoStorage::put($fileName, $payload, $disk);
    }

    /**
     * Delete an image from a disk.
     *
     * The deletion is attempted directly instead of checking existence first,
     * because some S3-compatible providers (e.g. Neva Objects) answer HEAD
     * requests in a way that makes `exists()` unreliable. Deleting a missing
     * object is a no-op, so this is safe. Errors are logged and ignored.
     */
    public function delete(string $path, ?string $disk = null): void
    {
        if (! $path) {
            return;
        }

        $disk ??= PhotoStorage::disk();

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            Log::warning("ImageService::delete failed on disk [{$disk}]: " . $e->getMessage());
        }
    }
}
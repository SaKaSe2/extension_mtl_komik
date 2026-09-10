<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Backblaze B2 Storage Service
 *
 * Cache gambar komik yang sudah diterjemahkan ke Backblaze B2 (S3-compatible).
 * Setiap gambar diberi key unik berdasarkan hash URL aslinya.
 * Kalau cache sudah ada, backend langsung return signed URL tanpa proses ulang.
 */
class BackblazeStorageService
{
    protected bool $enabled;
    protected string $disk = 'b2';

    // Berapa jam signed URL berlaku (default 24 jam)
    protected int $signedUrlExpiryHours;

    public function __construct()
    {
        // Hanya aktif jika kredensial B2 sudah dikonfigurasi
        $this->enabled = !empty(config('filesystems.disks.b2.key'))
            && !empty(config('filesystems.disks.b2.secret'))
            && !empty(config('filesystems.disks.b2.bucket'));

        $this->signedUrlExpiryHours = (int) env('B2_SIGNED_URL_EXPIRY_HOURS', 24);
    }

    /**
     * Cek apakah service siap dipakai.
     */
    public function isAvailable(): bool
    {
        return $this->enabled;
    }

    /**
     * Buat cache key unik dari URL gambar asli.
     * Format: translated/{hash}.webp
     */
    public function makeCacheKey(string $imageUrl): string
    {
        $hash = md5($imageUrl);
        return "translated/{$hash}.webp";
    }

    /**
     * Cek apakah gambar sudah ada di cache B2.
     */
    public function exists(string $imageUrl): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return Storage::disk($this->disk)->exists($this->makeCacheKey($imageUrl));
        } catch (\Throwable $e) {
            Log::warning('B2: Failed to check cache existence', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Ambil signed URL untuk gambar yang sudah ada di B2.
     * URL berlaku sesuai $signedUrlExpiryHours.
     * Return null kalau tidak ada atau gagal.
     */
    public function getSignedUrl(string $imageUrl): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $key = $this->makeCacheKey($imageUrl);
            if (!Storage::disk($this->disk)->exists($key)) {
                return null;
            }

            return Storage::disk($this->disk)->temporaryUrl(
                $key,
                now()->addHours($this->signedUrlExpiryHours)
            );
        } catch (\Throwable $e) {
            Log::warning('B2: Failed to generate signed URL', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Upload gambar base64 ke B2 dan return signed URL hasil upload.
     * Return null kalau gagal (tidak perlu throw, proses bisa lanjut tanpa B2).
     */
    public function uploadBase64(string $imageUrl, string $base64Data): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            // Pisahkan header data URL dari konten binary
            $binary = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $base64Data));
            if (empty($binary)) {
                return null;
            }

            $key = $this->makeCacheKey($imageUrl);

            Storage::disk($this->disk)->put($key, $binary, [
                'ContentType' => 'image/webp',
            ]);

            Log::debug('B2: Image cached', ['key' => $key]);

            // Return signed URL agar langsung bisa dipakai ekstensi
            return Storage::disk($this->disk)->temporaryUrl(
                $key,
                now()->addHours($this->signedUrlExpiryHours)
            );
        } catch (\Throwable $e) {
            Log::warning('B2: Failed to upload image', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

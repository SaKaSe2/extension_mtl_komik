<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BackblazeStorageService;
use App\Services\GoogleVisionOcrService;
use App\Services\ImageProcessingService;
use App\Services\OcrService;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExtensionController extends Controller
{
    protected OcrService $ocrService;
    protected GoogleVisionOcrService $googleVisionOcrService;
    protected TranslationService $translationService;
    protected ImageProcessingService $imageProcessingService;
    protected BackblazeStorageService $backblazeStorage;

    public function __construct(
        OcrService $ocrService,
        GoogleVisionOcrService $googleVisionOcrService,
        TranslationService $translationService,
        ImageProcessingService $imageProcessingService,
        BackblazeStorageService $backblazeStorage
    ) {
        $this->ocrService = $ocrService;
        $this->googleVisionOcrService = $googleVisionOcrService;
        $this->translationService = $translationService;
        $this->imageProcessingService = $imageProcessingService;
        $this->backblazeStorage = $backblazeStorage;
    }

    /**
     * Check extension API health and services status.
     */
    public function health(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'KomikoID MTL Extension API is running.',
                'services' => [
                    'huggingface_ocr' => config('ocr.use_huggingface_ocr', true),
                    'google_vision_ocr' => $this->googleVisionOcrService->isAvailable(),
                    'translation_service' => true,
                ],
                'supported_languages' => [
                    'target' => ['id', 'en', 'es', 'pt'],
                    'source' => ['auto', 'ja', 'ko', 'zh', 'en'],
                ],
                'version' => '1.0.0',
            ]);
        } catch (\Throwable $e) {
            Log::error('Health check error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Translate an entire comic page image.
     * Supports image replacement (returns base64 rendered image) and text blocks.
     */
    public function translatePage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'nullable|image|max:15360', // Max 15MB
            'image_base64' => 'nullable|string',
            'image_url' => 'nullable|url',
            'target_language' => 'nullable|string|max:10',
            'source_language' => 'nullable|string|max:10',
            'return_base64' => 'nullable|boolean',
        ]);

        $targetLanguage = $request->input('target_language', 'id');
        $returnBase64 = $request->boolean('return_base64', true);
        $force = $request->boolean('force', false);

        // Ensure temp directory exists
        $tempDir = storage_path('app/public/temp_extension');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempImagePath = null;
        $imageHash = null;

        try {
            // 1. Obtain image from Upload, Base64, or URL
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $imageHash = md5_file($file->getRealPath());
                $tempImagePath = $tempDir . '/' . uniqid('input_') . '.' . ($file->getClientOriginalExtension() ?: 'jpg');
                copy($file->getRealPath(), $tempImagePath);
            } elseif ($request->filled('image_base64')) {
                $base64Data = $request->input('image_base64');
                // Remove data uri scheme if present
                if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                    $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
                }
                $decoded = base64_decode($base64Data);
                if (!$decoded) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Format base64 gambar tidak valid.',
                    ], 422);
                }
                $imageHash = md5($decoded);
                $tempImagePath = $tempDir . '/' . uniqid('input_') . '.jpg';
                file_put_contents($tempImagePath, $decoded);
            } elseif ($request->filled('image_url')) {
                $imageUrl = $request->input('image_url');
                $imageHash = md5($imageUrl);

                // Cek B2 persistent cache lebih dulu (hemat proses)
                if (!$force && $this->backblazeStorage->isAvailable()) {
                    $b2SignedUrl = $this->backblazeStorage->getSignedUrl($imageUrl);
                    if ($b2SignedUrl !== null) {
                        return response()->json([
                            'success' => true,
                            'cached' => true,
                            'source' => 'b2',
                            'data' => [
                                'translated_image_url' => $b2SignedUrl,
                                'blocks_count' => 0,
                                'blocks' => [],
                            ],
                        ]);
                    }
                }

                // Cek Laravel in-memory/Redis cache
                $cacheKey = "ext_trans_{$imageHash}_{$targetLanguage}";
                if (!$force && Cache::has($cacheKey)) {
                    return response()->json([
                        'success' => true,
                        'cached' => true,
                        'source' => 'cache',
                        'data' => Cache::get($cacheKey),
                    ]);
                }

                // Download image with timeout
                $response = Http::timeout(20)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                    ->get($imageUrl);

                if (!$response->successful()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal mengunduh gambar dari URL yang diberikan.',
                    ], 422);
                }

                $tempImagePath = $tempDir . '/' . uniqid('input_') . '.jpg';
                file_put_contents($tempImagePath, $response->body());
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Gambar harus disediakan melalui image, image_base64, atau image_url.',
                ], 422);
            }

            // Check cache by image hash
            $cacheKey = "ext_trans_{$imageHash}_{$targetLanguage}";
            if (!$force && Cache::has($cacheKey)) {
                $this->cleanupFile($tempImagePath);
                return response()->json([
                    'success' => true,
                    'cached' => true,
                    'data' => Cache::get($cacheKey),
                ]);
            }

            // 2. Perform OCR Detection
            $textBlocks = [];
            if ($this->googleVisionOcrService->isAvailable()) {
                $textBlocks = $this->googleVisionOcrService->detectText($tempImagePath);
            }

            // Fallback to local OCR if Google Vision returned nothing
            if (empty($textBlocks)) {
                $relativeTempPath = 'temp_extension/' . basename($tempImagePath);
                $textBlocks = $this->ocrService->extractText($relativeTempPath);
            }

            // Baca dimensi gambar asli untuk validasi ukuran bounding box
            $imageInfo = @getimagesize($tempImagePath);
            $imgWidth = $imageInfo ? (int) $imageInfo[0] : 0;
            $imgHeight = $imageInfo ? (int) $imageInfo[1] : 0;

            // Filter bounding box: buang kotak yang merusak artwork dan buang duplikat tumpang tindih
            $validBlocks = [];
            foreach ($textBlocks as $block) {
                if ($this->isValidComicTextBlock($block, $imgWidth, $imgHeight)) {
                    $validBlocks[] = $block;
                }
            }
            $textBlocks = $this->removeOverlappingBlocks($validBlocks);

            if (empty($textBlocks)) {
                // No valid text found in comic page, return original
                $originalBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tempImagePath));
                $this->cleanupFile($tempImagePath);
                return response()->json([
                    'success' => true,
                    'cached' => false,
                    'data' => [
                        'translated_image' => $originalBase64,
                        'blocks_count' => 0,
                        'blocks' => [],
                        'message' => 'Tidak ada teks yang terdeteksi pada gambar ini.',
                    ],
                ]);
            }

            // 3. Translate Detected Text Blocks
            $translatedTexts = [];
            foreach ($textBlocks as $index => $block) {
                $originalText = trim($block['text'] ?? '');
                if (empty($originalText) || mb_strlen($originalText) < 2) {
                    continue;
                }

                if ($index > 0) {
                    usleep(100000); // 100ms throttle
                }

                $translated = $this->translationService->translate($originalText, $targetLanguage);
                if ($translated !== null && !empty(trim($translated))) {
                    $translatedTexts[] = [
                        'text' => $translated,
                        'original' => $originalText,
                        'x' => $block['x'] ?? 0,
                        'y' => $block['y'] ?? 0,
                        'width' => $block['width'] ?? 0,
                        'height' => $block['height'] ?? 0,
                        'confidence' => $block['confidence'] ?? 85,
                    ];
                }
            }

            // 4. Inpaint / Erase text and render translated text on image (Image Replacement)
            $renderedImagePath = null;
            $translatedImageBase64 = null;

            if (!empty($translatedTexts)) {
                // Hanya inpaint area teks yang valid dan berhasil diterjemahkan agar artwork aman
                $renderedImagePath = $this->imageProcessingService->processDirectImage(
                    $tempImagePath,
                    $translatedTexts,
                    $translatedTexts
                );

                if ($renderedImagePath && file_exists($renderedImagePath)) {
                    $translatedImageBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($renderedImagePath));
                    $this->cleanupFile($renderedImagePath);
                }
            }

            // Fallback to original image if processing failed
            if (!$translatedImageBase64 && file_exists($tempImagePath)) {
                $translatedImageBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($tempImagePath));
            }

            // Upload hasil ke Backblaze B2 untuk persistent cache (fire and forget)
            $b2CachedUrl = null;
            if ($translatedImageBase64 && isset($imageUrl) && $this->backblazeStorage->isAvailable()) {
                $b2CachedUrl = $this->backblazeStorage->uploadBase64($imageUrl, $translatedImageBase64);
            }

            $responseData = [
                'translated_image' => $translatedImageBase64,
                'blocks_count' => count($translatedTexts),
                'blocks' => $translatedTexts,
            ];

            // Cache result in Laravel cache for 24 hours (sebagai fallback)
            Cache::put($cacheKey, $responseData, now()->addHours(24));

            // Cleanup input temp file
            $this->cleanupFile($tempImagePath);

            $result = [
                'success' => true,
                'cached' => false,
                'data' => $responseData,
            ];

            // Sertakan URL B2 kalau berhasil di-cache (opsional, untuk debugging)
            if ($b2CachedUrl) {
                $result['b2_cached'] = true;
            }

            return response()->json($result);

        } catch (\Exception $e) {
            $this->cleanupFile($tempImagePath);
            Log::error('Extension translate error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses terjemahan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validasi bounding box OCR agar tidak merusak artwork komik:
     * - Teks speech bubble tidak boleh melebihi 50% lebar gambar.
     * - Teks tidak boleh melebihi 35% tinggi slice gambar.
     * - Teks harus mengandung minimal 2 karakter alfanumerik.
     */
    protected function isValidComicTextBlock(array $block, int $imgWidth, int $imgHeight): bool
    {
        $text = trim($block['text'] ?? '');
        $width = (int) ($block['width'] ?? 0);
        $height = (int) ($block['height'] ?? 0);
        $confidence = (float) ($block['confidence'] ?? 85);

        // Minimal mengandung minimal 2 karakter alfanumerik (bukan simbol acak atau artefak noise)
        $cleanText = preg_replace('/[^\p{L}\p{N}]/u', '', $text);
        if (mb_strlen($cleanText) < 2) {
            return false;
        }

        // Filter confidence score jika tersedia
        if ($confidence > 0 && $confidence < 30) {
            return false;
        }

        // Filter batasan ukuran fisik kotak terhadap gambar
        if ($imgWidth > 0 && $imgHeight > 0) {
            // Kotak tidak boleh melebihi 50% lebar gambar (speech bubble normal <= 50%)
            if ($width > ($imgWidth * 0.50)) {
                return false;
            }
            // Kotak tidak boleh melebihi 35% tinggi slice gambar
            if ($height > ($imgHeight * 0.35)) {
                return false;
            }
            // Abaikan kotak yang terlalu kecil (noise/bintik)
            if ($width < 15 || $height < 10) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hapus bounding box yang tumpang tindih (overlap) tinggi
     */
    protected function removeOverlappingBlocks(array $blocks): array
    {
        if (count($blocks) <= 1) {
            return $blocks;
        }

        // Urutkan berdasarkan confidence tertinggi dan panjang teks
        usort($blocks, function ($a, $b) {
            $confA = (float) ($a['confidence'] ?? 0);
            $confB = (float) ($b['confidence'] ?? 0);
            if (abs($confA - $confB) > 5) {
                return $confB <=> $confA;
            }
            return mb_strlen($b['text'] ?? '') <=> mb_strlen($a['text'] ?? '');
        });

        $kept = [];
        foreach ($blocks as $block) {
            $bx1 = (int) ($block['x'] ?? 0);
            $by1 = (int) ($block['y'] ?? 0);
            $bw1 = (int) ($block['width'] ?? 0);
            $bh1 = (int) ($block['height'] ?? 0);
            $area1 = $bw1 * $bh1;
            if ($area1 <= 0) continue;

            $overlaps = false;
            foreach ($kept as $k) {
                $kx1 = (int) ($k['x'] ?? 0);
                $ky1 = (int) ($k['y'] ?? 0);
                $kw1 = (int) ($k['width'] ?? 0);
                $kh1 = (int) ($k['height'] ?? 0);
                $areaK = $kw1 * $kh1;

                // Hitung irisan kotak
                $ix1 = max($bx1, $kx1);
                $iy1 = max($by1, $ky1);
                $ix2 = min($bx1 + $bw1, $kx1 + $kw1);
                $iy2 = min($by1 + $bh1, $ky1 + $kh1);

                $iw = max(0, $ix2 - $ix1);
                $ih = max(0, $iy2 - $iy1);
                $intersectArea = $iw * $ih;

                $minArea = min($area1, $areaK);
                if ($minArea > 0 && ($intersectArea / $minArea) > 0.45) {
                    $overlaps = true;
                    break;
                }
            }

            if (!$overlaps) {
                $kept[] = $block;
            }
        }

        return $kept;
    }

    /**
     * Helper to safely remove temp file.
     */
    protected function cleanupFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
}

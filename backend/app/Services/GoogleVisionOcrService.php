<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Cloud Vision OCR Service
 * 
 * Deteksi teks dari gambar komik menggunakan Google Cloud Vision API.
 * Gratis 1000 request/bulan. Mendukung semua bahasa CJK (Jepang, Korea, China).
 * 
 * @see https://cloud.google.com/vision/docs/ocr
 */
class GoogleVisionOcrService
{
    protected string $apiKey;
    protected bool $enabled;
    protected int $timeout;
    protected string $apiUrl = 'https://vision.googleapis.com/v1/images:annotate';

    public function __construct()
    {
        $this->apiKey = config('google_vision.api_key', '');
        $this->enabled = config('google_vision.enabled', true);
        $this->timeout = config('google_vision.timeout', 30);
    }

    /**
     * Check apakah service tersedia (API key dikonfigurasi).
     */
    public function isAvailable(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }

    /**
     * Deteksi teks dari gambar dan return posisi bounding box.
     *
     * @param string $imagePath Absolute path ke file gambar
     * @return array Array of text blocks dengan posisi [text, x, y, width, height, confidence]
     */
    public function detectText(string $imagePath): array
    {
        if (!$this->isAvailable()) {
            Log::debug('GoogleVision: Service not available (no API key)');
            return [];
        }

        if (!file_exists($imagePath)) {
            Log::error('GoogleVision: Image file not found', ['path' => $imagePath]);
            return [];
        }

        try {
            // Encode gambar ke base64
            $imageContent = base64_encode(file_get_contents($imagePath));

            // Kirim request ke Google Vision API
            $response = Http::timeout($this->timeout)
                ->post("{$this->apiUrl}?key={$this->apiKey}", [
                    'requests' => [
                        [
                            'image' => [
                                'content' => $imageContent,
                            ],
                            'features' => [
                                [
                                    'type' => 'TEXT_DETECTION',
                                    'maxResults' => 50,
                                ],
                            ],
                            'imageContext' => [
                                'languageHints' => ['ko', 'ja', 'zh', 'en'],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('GoogleVision: API request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return [];
            }

            $data = $response->json();

            return $this->parseResponse($data, $imagePath);

        } catch (\Exception $e) {
            Log::error('GoogleVision: Exception', [
                'error' => $e->getMessage(),
                'image' => basename($imagePath),
            ]);
            return [];
        }
    }

    /**
     * Deteksi teks dari URL gambar (Cloudinary, etc).
     *
     * @param string $imageUrl URL publik gambar
     * @return array Array of text blocks
     */
    public function detectTextFromUrl(string $imageUrl): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->apiUrl}?key={$this->apiKey}", [
                    'requests' => [
                        [
                            'image' => [
                                'source' => [
                                    'imageUri' => $imageUrl,
                                ],
                            ],
                            'features' => [
                                [
                                    'type' => 'TEXT_DETECTION',
                                    'maxResults' => 50,
                                ],
                            ],
                            'imageContext' => [
                                'languageHints' => ['ko', 'ja', 'zh', 'en'],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('GoogleVision: URL request failed', [
                    'status' => $response->status(),
                    'url' => $imageUrl,
                ]);
                return [];
            }

            return $this->parseResponse($response->json(), $imageUrl);

        } catch (\Exception $e) {
            Log::error('GoogleVision: URL exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Parse response dari Google Vision API menjadi format text blocks standar.
     */
    protected function parseResponse(array $data, string $source): array
    {
        $annotations = $data['responses'][0]['textAnnotations'] ?? [];

        if (empty($annotations)) {
            Log::info('GoogleVision: No text detected', ['source' => basename($source)]);
            return [];
        }

        $textBlocks = [];

        // Skip index 0 (full page text), mulai dari index 1 (individual words/phrases)
        // Tapi kita perlu group words yang berdekatan menjadi blocks
        $words = array_slice($annotations, 1);

        if (empty($words)) {
            return [];
        }

        // Group words yang berdekatan secara horizontal menjadi baris
        $lineGroups = $this->groupWordsIntoBlocks($words);
        
        // Merge baris-baris yang berdekatan secara vertikal (speech bubble multi-line)
        $mergedGroups = $this->mergeVerticalBlocks($lineGroups);

        foreach ($mergedGroups as $index => $group) {
            $textBlocks[] = [
                'id' => $index,
                'text' => $group['text'],
                'x' => $group['x'],
                'y' => $group['y'],
                'width' => $group['width'],
                'height' => $group['height'],
                'confidence' => 85,
            ];
        }

        Log::info('GoogleVision: Text detected', [
            'source' => basename($source),
            'line_groups' => count($lineGroups),
            'merged_blocks' => count($textBlocks),
            'preview' => array_map(fn($b) => mb_substr($b['text'], 0, 30), array_slice($textBlocks, 0, 3)),
        ]);

        return $textBlocks;
    }

    /**
     * Group individual words dari Vision API menjadi baris berdasarkan proximity horizontal.
     */
    protected function groupWordsIntoBlocks(array $words): array
    {
        if (empty($words)) {
            return [];
        }

        $wordData = [];
        foreach ($words as $word) {
            $vertices = $word['boundingPoly']['vertices'] ?? [];
            if (count($vertices) < 4) {
                continue;
            }

            $x = $vertices[0]['x'] ?? 0;
            $y = $vertices[0]['y'] ?? 0;
            $x2 = $vertices[2]['x'] ?? 0;
            $y2 = $vertices[2]['y'] ?? 0;

            $wordData[] = [
                'text' => $word['description'] ?? '',
                'x' => $x,
                'y' => $y,
                'width' => max(1, $x2 - $x),
                'height' => max(1, $y2 - $y),
                'centerY' => ($y + $y2) / 2,
                'grouped' => false,
            ];
        }

        usort($wordData, fn($a, $b) => $a['y'] - $b['y']);

        $groups = [];
        foreach ($wordData as &$word) {
            if ($word['grouped']) {
                continue;
            }

            $group = [
                'text' => $word['text'],
                'x' => $word['x'],
                'y' => $word['y'],
                'x2' => $word['x'] + $word['width'],
                'y2' => $word['y'] + $word['height'],
            ];
            $word['grouped'] = true;

            $lineThreshold = $word['height'] * 0.6;

            foreach ($wordData as &$otherWord) {
                if ($otherWord['grouped']) {
                    continue;
                }

                if (abs($otherWord['centerY'] - $word['centerY']) < $lineThreshold) {
                    $group['text'] .= ' ' . $otherWord['text'];
                    $group['x'] = min($group['x'], $otherWord['x']);
                    $group['y'] = min($group['y'], $otherWord['y']);
                    $group['x2'] = max($group['x2'], $otherWord['x'] + $otherWord['width']);
                    $group['y2'] = max($group['y2'], $otherWord['y'] + $otherWord['height']);
                    $otherWord['grouped'] = true;
                }
            }

            $groups[] = [
                'text' => trim($group['text']),
                'x' => $group['x'],
                'y' => $group['y'],
                'width' => max(1, $group['x2'] - $group['x']),
                'height' => max(1, $group['y2'] - $group['y']),
            ];
        }

        return $groups;
    }

    /**
     * Merge baris-baris teks yang berdekatan secara vertikal menjadi satu blok.
     * Menggabungkan multi-line text dalam speech bubble agar konteks terjemahan utuh.
     */
    protected function mergeVerticalBlocks(array $blocks): array
    {
        if (count($blocks) <= 1) {
            return $blocks;
        }

        // Sort by Y position
        usort($blocks, fn($a, $b) => $a['y'] - $b['y']);

        $merged = [];
        $used = array_fill(0, count($blocks), false);

        for ($i = 0; $i < count($blocks); $i++) {
            if ($used[$i]) {
                continue;
            }

            $current = $blocks[$i];
            $used[$i] = true;

            // Cari blok lain yang berdekatan secara vertikal
            for ($j = $i + 1; $j < count($blocks); $j++) {
                if ($used[$j]) {
                    continue;
                }

                $next = $blocks[$j];

                // Hitung jarak vertikal antara bottom blok saat ini dan top blok berikutnya
                $currentBottom = $current['y'] + $current['height'];
                $verticalGap = $next['y'] - $currentBottom;
                $avgLineHeight = ($current['height'] + $next['height']) / 2;

                // Hitung overlap horizontal (blok harus sejajar secara horizontal)
                $overlapX1 = max($current['x'], $next['x']);
                $overlapX2 = min($current['x'] + $current['width'], $next['x'] + $next['width']);
                $horizontalOverlap = max(0, $overlapX2 - $overlapX1);
                $minWidth = min($current['width'], $next['width']);
                $overlapRatio = $minWidth > 0 ? $horizontalOverlap / $minWidth : 0;

                // Merge jika: jarak vertikal dekat DAN ada overlap horizontal signifikan
                if ($verticalGap < $avgLineHeight * 1.5 && $overlapRatio > 0.3) {
                    // Gabungkan teks dengan newline
                    $current['text'] .= ' ' . $next['text'];
                    $current['x'] = min($current['x'], $next['x']);
                    $current['y'] = min($current['y'], $next['y']);
                    $newX2 = max($current['x'] + $current['width'], $next['x'] + $next['width']);
                    $newY2 = max($current['y'] + $current['height'], $next['y'] + $next['height']);
                    $current['width'] = $newX2 - $current['x'];
                    $current['height'] = $newY2 - $current['y'];
                    $used[$j] = true;
                }
            }

            $merged[] = $current;
        }

        return $merged;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class ImageProcessingService
{
    protected ImageManager $manager;
    protected ?InpaintingService $inpaintingService;
    protected string $fontPath;
    protected string $boldFontPath;
    protected int $defaultFontSize;
    protected string $fontColor;
    protected int $padding;
    protected array $availableFonts = [];

    public function __construct(?InpaintingService $inpaintingService = null)
    {
        $this->manager = new ImageManager(new GdDriver());
        $this->inpaintingService = $inpaintingService ?? app(InpaintingService::class);
        
        // Load available fonts
        $this->availableFonts = $this->loadAvailableFonts();
        
        // Get default font from multi-font config
        $defaultFontKey = config('image.default_font', 'malgun');
        $fonts = config('image.fonts', []);
        
        // First try the .env font path
        $envFontPath = config('image.font_path');
        if ($envFontPath) {
            // Convert relative path to absolute
            if (!str_starts_with($envFontPath, 'C:') && !str_starts_with($envFontPath, '/')) {
                $envFontPath = base_path($envFontPath);
            }
            if (file_exists($envFontPath)) {
                $this->fontPath = $envFontPath;
            }
        }
        
        // If no font set yet, try config fonts or cross-platform fallbacks
        if (!isset($this->fontPath) || !file_exists($this->fontPath)) {
            if (isset($fonts[$defaultFontKey]) && file_exists($fonts[$defaultFontKey]['path'])) {
                $this->fontPath = $fonts[$defaultFontKey]['path'];
            } else {
                $available = $this->getFirstAvailableFont();
                if ($available && file_exists($available)) {
                    $this->fontPath = $available;
                } else {
                    $regularCandidates = [
                        resource_path('fonts/Poppins-Bold.ttf'),
                        'C:/Windows/Fonts/malgun.ttf',
                        'C:/Windows/Fonts/arial.ttf',
                        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                    ];
                    foreach ($regularCandidates as $cand) {
                        if (file_exists($cand)) {
                            $this->fontPath = $cand;
                            break;
                        }
                    }
                    $this->fontPath = $this->fontPath ?? 'C:/Windows/Fonts/arial.ttf';
                }
            }
        }
        
        // Paksa font bold untuk konsistensi tampilan di semua panel komik
        $boldCandidates = [
            'C:/Windows/Fonts/malgunbd.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            resource_path('fonts/Poppins-Bold.ttf'),
            storage_path('app/fonts/Poppins-Bold.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ];
        $foundBold = null;
        foreach ($boldCandidates as $cand) {
            if (file_exists($cand)) {
                $foundBold = $cand;
                break;
            }
        }
        $this->boldFontPath = $foundBold ?? $this->fontPath;
        
        $this->defaultFontSize = config('image.default_font_size', 14);
        $this->fontColor = config('image.font_color', '#000000');
        $this->padding = config('image.padding', 4);
        
        Log::debug('ImageProcessingService initialized', [
            'font' => $this->fontPath,
            'bold_font' => $this->boldFontPath,
            'available_fonts' => count($this->availableFonts),
        ]);
    }
    
    /**
     * Load available fonts from config and verify they exist.
     */
    protected function loadAvailableFonts(): array
    {
        $fonts = config('image.fonts', []);
        $available = [];
        
        foreach ($fonts as $key => $font) {
            if (file_exists($font['path'])) {
                $available[$key] = $font;
            }
        }
        
        return $available;
    }
    
    /**
     * Get the first available font path.
     */
    protected function getFirstAvailableFont(): ?string
    {
        foreach ($this->availableFonts as $font) {
            return $font['path'];
        }
        return null;
    }
    
    /**
     * Get font path for a specific style or language.
     */
    public function getFontForLanguage(string $langCode = 'id'): string
    {
        // Map Indonesian to fonts that support it well
        $langCodeMap = [
            'id' => 'en', // Indonesian uses same fonts as English
            'en' => 'en',
            'ko' => 'ko',
            'ja' => 'ja',
            'zh' => 'zh',
        ];
        
        $targetLang = $langCodeMap[$langCode] ?? 'en';
        
        // Find a font that supports this language
        foreach ($this->availableFonts as $font) {
            if (in_array($targetLang, $font['languages'] ?? [])) {
                return $font['path'];
            }
        }
        
        return $this->fontPath;
    }
    
    /**
     * Get list of available fonts for UI selection.
     */
    public function getAvailableFonts(): array
    {
        return $this->availableFonts;
    }

    /**
     * Process a chapter page: remove original text and overlay translation.
     * 
     * @param ChapterPage $page The page to process
     * @param array $translatedTexts Array of translated text blocks
     * @return string|null Path to the translated image
     */
    public function processPage(ChapterPage $page, array $translatedTexts): ?string
    {
        $originalPath = Storage::disk('public')->path($page->original_image);
        
        if (!file_exists($originalPath)) {
            Log::error('Image processing: Original image not found', ['path' => $originalPath]);
            return null;
        }

        try {
            // Get original text positions for text removal
            $originalTexts = $page->original_text ?? [];
            
            Log::info('ProcessPage starting', [
                'page_id' => $page->id,
                'original_path' => $originalPath,
                'original_texts_count' => count($originalTexts),
                'inpainting_service_available' => $this->inpaintingService ? $this->inpaintingService->isAvailable() : false,
            ]);
            
            // Try AI inpainting first (LaMa model)
            $inpaintedPath = $this->tryAIInpainting($originalPath, $originalTexts);
            
            Log::info('AI Inpainting result', [
                'inpainted_path' => $inpaintedPath,
                'file_exists' => $inpaintedPath ? file_exists($inpaintedPath) : false,
            ]);
            
            if ($inpaintedPath && file_exists($inpaintedPath)) {
                // AI inpainting succeeded, load the inpainted image
                $image = $this->manager->read($inpaintedPath);
                Log::info('Using AI inpainting for text removal', ['path' => $inpaintedPath]);
            } else {
                // Fallback to basic pixel replacement (legacy method)
                $image = $this->manager->read($originalPath);
                Log::info('Using basic pixel replacement for text removal (AI inpainting unavailable)');
                
                foreach ($originalTexts as $textBlock) {
                    $this->removeTextBlock($image, $textBlock);
                }
            }
            
            // Overlay translated text
            foreach ($translatedTexts as $textBlock) {
                $this->overlayTextBlock($image, $textBlock);
            }
            
            // Generate output path
            $translatedPath = $this->generateTranslatedPath($page);
            $fullTranslatedPath = Storage::disk('public')->path($translatedPath);
            
            // Ensure directory exists
            $directory = dirname($fullTranslatedPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            // Save the image
            $image->save($fullTranslatedPath, quality: 90);
            
            // Clean up inpainted temp file if it was created
            if ($inpaintedPath && $inpaintedPath !== $originalPath && file_exists($inpaintedPath)) {
                @unlink($inpaintedPath);
            }
            
            return $translatedPath;
        } catch (\Exception $e) {
            Log::error('Image processing failed', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Process an image directly from a file path: remove original text and overlay translation.
     * 
     * @param string $originalPath Absolute path to source image
     * @param array $translatedTexts Array of translated text blocks with positions
     * @param array $originalTexts Array of original text blocks for text removal
     * @param string|null $outputPath Optional destination path. If null, a temp path will be generated.
     * @return string|null Absolute path to the translated image
     */
    public function processDirectImage(string $originalPath, array $translatedTexts, array $originalTexts, ?string $outputPath = null): ?string
    {
        if (!file_exists($originalPath)) {
            Log::error('Image processing direct: Original image not found', ['path' => $originalPath]);
            return null;
        }

        try {
            $image = $this->manager->read($originalPath);

            // Pisahkan blok: speech bubble (bersihkan instan via solid fill) vs background kompleks
            $complexBlocks = [];
            foreach ($originalTexts as $textBlock) {
                $bx = (int) ($textBlock['x'] ?? 0);
                $by = (int) ($textBlock['y'] ?? 0);
                $bw = (int) ($textBlock['width'] ?? 0);
                $bh = (int) ($textBlock['height'] ?? 0);

                if ($bw <= 0 || $bh <= 0) {
                    continue;
                }

                if ($this->detectSpeechBubble($image, $bx, $by, $bw, $bh)) {
                    // Pembersihan cepat instan (<1ms) untuk speech bubble
                    $this->cleanSpeechBubbleFast($image, $bx, $by, $bw, $bh);
                } else {
                    $complexBlocks[] = $textBlock;
                }
            }

            // Hanya panggil model AI inpainting jika ada akselerasi GPU (CUDA) dan background kompleks
            $useGpuInpainting = config('image.inpainting.device') === 'cuda';
            if (!empty($complexBlocks) && $useGpuInpainting && $this->inpaintingService && $this->inpaintingService->isAvailable()) {
                $tempCleanPath = storage_path('app/public/temp_extension/clean_' . uniqid() . '.jpg');
                $image->save($tempCleanPath, quality: 95);
                $inpaintedPath = $this->tryAIInpainting($tempCleanPath, $complexBlocks);
                if ($inpaintedPath && file_exists($inpaintedPath) && $inpaintedPath !== $tempCleanPath) {
                    $image = $this->manager->read($inpaintedPath);
                    @unlink($inpaintedPath);
                }
                @unlink($tempCleanPath);
            } elseif (!empty($complexBlocks)) {
                // Pembersihan selektif piksel teks pada background kompleks untuk melindungi artwork
                foreach ($complexBlocks as $textBlock) {
                    $this->removeTextBlock($image, $textBlock);
                }
            }

            // Overlay translated text
            foreach ($translatedTexts as $textBlock) {
                $this->overlayTextBlock($image, $textBlock);
            }

            if (!$outputPath) {
                $tempDir = storage_path('app/public/temp_extension');
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $outputPath = $tempDir . '/translated_' . uniqid() . '.jpg';
            } else {
                $dir = dirname($outputPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            $image->save($outputPath, quality: 90);

            if (isset($inpaintedPath) && $inpaintedPath && $inpaintedPath !== $originalPath && file_exists($inpaintedPath)) {
                @unlink($inpaintedPath);
            }

            return $outputPath;
        } catch (\Exception $e) {
            Log::error('Direct image processing failed', [
                'path' => $originalPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Try to use AI inpainting for text removal.
     * Returns the path to the inpainted image, or null if AI inpainting is unavailable.
     */
    protected function tryAIInpainting(string $imagePath, array $textBlocks): ?string
    {
        if (!$this->inpaintingService || !$this->inpaintingService->isAvailable()) {
            return null;
        }
        
        if (empty($textBlocks)) {
            return null;
        }
        
        try {
            return $this->inpaintingService->inpaint($imagePath, $textBlocks);
        } catch (\Exception $e) {
            Log::warning('AI inpainting failed, falling back to basic method', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Hapus teks dari area dengan solid fill warna background.
     * Deteksi warna background otomatis (putih untuk bubble, gelap untuk panel hitam).
     * Selalu gunakan solid fill - lebih bersih dari pixel-by-pixel selection.
     */
    protected function removeTextBlock($image, array $textBlock): void
    {
        $x = (int) ($textBlock['x'] ?? 0);
        $y = (int) ($textBlock['y'] ?? 0);
        $width = (int) ($textBlock['width'] ?? 0);
        $height = (int) ($textBlock['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        $imageWidth  = $image->width();
        $imageHeight = $image->height();

        // Selalu gunakan solid fill dengan padding cukup besar
        $this->cleanSpeechBubbleFast($image, $x, $y, $width, $height);
    }
    
    /**
     * Selectively remove only text pixels (dark pixels) from a region.
     * This preserves the background while only erasing text characters.
     * 
     * @param object $image The image object
     * @param int $x Starting X coordinate
     * @param int $y Starting Y coordinate  
     * @param int $width Width of region
     * @param int $height Height of region
     * @param bool $isSpeechBubble Whether this is a speech bubble (use white replacement)
     */
    protected function removeTextPixelsSelective($image, int $x, int $y, int $width, int $height, bool $isSpeechBubble): void
    {
        $imageWidth = $image->width();
        $imageHeight = $image->height();
        
        // First, analyze the region to determine text vs background
        $brightPixels = [];
        $darkPixels = [];
        $allPixels = [];
        
        // Sample pixels to understand the color distribution
        $sampleStep = max(1, min($width, $height) / 20);
        for ($sy = $y; $sy < $y + $height; $sy += $sampleStep) {
            for ($sx = $x; $sx < $x + $width; $sx += $sampleStep) {
                $px = max(0, min($imageWidth - 1, (int)$sx));
                $py = max(0, min($imageHeight - 1, (int)$sy));
                
                try {
                    $color = $image->pickColor($px, $py);
                    $r = $color->red()->toInt();
                    $g = $color->green()->toInt();
                    $b = $color->blue()->toInt();
                    $brightness = ($r + $g + $b) / 3;
                    
                    $allPixels[] = ['r' => $r, 'g' => $g, 'b' => $b, 'brightness' => $brightness];
                    
                    if ($brightness > 180) {
                        $brightPixels[] = ['r' => $r, 'g' => $g, 'b' => $b];
                    } elseif ($brightness < 100) {
                        $darkPixels[] = ['r' => $r, 'g' => $g, 'b' => $b];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
        
        // Determine the text threshold based on pixel distribution
        // Text is usually the minority of pixels (dark on light or light on dark)
        $totalSamples = count($allPixels);
        $darkRatio = count($darkPixels) / max(1, $totalSamples);
        $brightRatio = count($brightPixels) / max(1, $totalSamples);
        
        // Determine if text is dark-on-light or light-on-dark
        $textIsDark = $darkRatio < $brightRatio;
        
        // Calculate background color from the majority pixels
        if ($isSpeechBubble || $textIsDark) {
            // Text is dark, background is bright (most common for speech bubbles)
            $bgColor = $this->getAverageColor($brightPixels, ['r' => 255, 'g' => 255, 'b' => 255]);
            $textThresholdLow = 0;
            // Wider threshold to catch colored/outlined text, not just pure black
            $textThresholdHigh = $isSpeechBubble ? 80 : 100;
        } else {
            // Text is bright, background is dark (inverted text)
            $bgColor = $this->getAverageColor($darkPixels, ['r' => 30, 'g' => 30, 'b' => 30]);
            $textThresholdLow = 180;
            $textThresholdHigh = 255;
        }
        
        // For each pixel in the region, check if it's text and replace if so
        for ($py = $y; $py < $y + $height; $py++) {
            for ($px = $x; $px < $x + $width; $px++) {
                $pixelX = max(0, min($imageWidth - 1, $px));
                $pixelY = max(0, min($imageHeight - 1, $py));
                
                try {
                    $color = $image->pickColor($pixelX, $pixelY);
                    $r = $color->red()->toInt();
                    $g = $color->green()->toInt();
                    $b = $color->blue()->toInt();
                    $brightness = ($r + $g + $b) / 3;
                    
                    // Check if this pixel is text
                    // Text criteria: 1) Dark enough, 2) Low saturation (grayscale-ish)
                    $isTextPixel = false;
                    if ($textIsDark) {
                        // Calculate saturation - text is typically grayscale (black)
                        // Saturation = (max-min)/max where max/min are RGB max/min
                        $maxC = max($r, $g, $b);
                        $minC = min($r, $g, $b);
                        $saturation = ($maxC > 0) ? (($maxC - $minC) / $maxC) : 0;
                        
                        // Consider as text if: dark AND relatively low saturation
                        $isTextPixel = $brightness < $textThresholdHigh && $saturation < 0.4;
                    } else {
                        $isTextPixel = $brightness > $textThresholdLow;
                    }
                    
                    if ($isTextPixel) {
                        // Replace this pixel with background color
                        // Use local sampling for better blending
                        $localBg = $this->getLocalBackgroundColor($image, $pixelX, $pixelY, $textIsDark, $textThresholdHigh, $textThresholdLow);
                        
                        if ($localBg) {
                            // Draw single pixel with local background color
                            $hexColor = sprintf('#%02X%02X%02X', $localBg['r'], $localBg['g'], $localBg['b']);
                            $image->drawRectangle($pixelX, $pixelY, function ($draw) use ($hexColor) {
                                $draw->size(1, 1);
                                $draw->background($hexColor);
                            });
                        } else {
                            // Fallback to general background color
                            $hexColor = sprintf('#%02X%02X%02X', $bgColor['r'], $bgColor['g'], $bgColor['b']);
                            $image->drawRectangle($pixelX, $pixelY, function ($draw) use ($hexColor) {
                                $draw->size(1, 1);
                                $draw->background($hexColor);
                            });
                        }
                    }
                    // Non-text pixels are left unchanged
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
    }
    
    /**
     * Get the local background color by sampling non-text pixels in a radius.
     */
    protected function getLocalBackgroundColor($image, int $centerX, int $centerY, bool $textIsDark, int $thresholdHigh, int $thresholdLow): ?array
    {
        $imageWidth = $image->width();
        $imageHeight = $image->height();
        $radius = 5;
        $nonTextPixels = [];
        
        for ($dy = -$radius; $dy <= $radius; $dy++) {
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                if ($dx === 0 && $dy === 0) continue;
                
                $px = $centerX + $dx;
                $py = $centerY + $dy;
                
                if ($px < 0 || $px >= $imageWidth || $py < 0 || $py >= $imageHeight) continue;
                
                try {
                    $color = $image->pickColor($px, $py);
                    $r = $color->red()->toInt();
                    $g = $color->green()->toInt();
                    $b = $color->blue()->toInt();
                    $brightness = ($r + $g + $b) / 3;
                    
                    // Check if this is a non-text pixel (background)
                    $isBackground = $textIsDark 
                        ? $brightness >= $thresholdHigh 
                        : $brightness <= $thresholdLow;
                    
                    if ($isBackground) {
                        $nonTextPixels[] = ['r' => $r, 'g' => $g, 'b' => $b];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
        
        if (empty($nonTextPixels)) {
            return null;
        }
        
        return $this->getAverageColor($nonTextPixels, null);
    }
    
    /**
     * Calculate average color from a list of color samples.
     */
    protected function getAverageColor(array $colors, ?array $default): array
    {
        if (empty($colors)) {
            return $default ?? ['r' => 255, 'g' => 255, 'b' => 255];
        }
        
        $totalR = 0;
        $totalG = 0;
        $totalB = 0;
        
        foreach ($colors as $color) {
            $totalR += $color['r'];
            $totalG += $color['g'];
            $totalB += $color['b'];
        }
        
        $count = count($colors);
        return [
            'r' => (int)($totalR / $count),
            'g' => (int)($totalG / $count),
            'b' => (int)($totalB / $count)
        ];
    }
    
    /**
     * Detect if a region is likely a speech bubble (predominantly white/light).
     */
    protected function detectSpeechBubble($image, int $x, int $y, int $width, int $height): bool
    {
        $samples = [];
        $sampleCount = 12;
        
        // Sample pixels within the region
        for ($i = 0; $i < $sampleCount; $i++) {
            $px = $x + rand(0, max(0, $width - 1));
            $py = $y + rand(0, max(0, $height - 1));
            
            $px = max(0, min($image->width() - 1, $px));
            $py = max(0, min($image->height() - 1, $py));
            
            try {
                $color = $image->pickColor($px, $py);
                $brightness = ($color->red()->toInt() + $color->green()->toInt() + $color->blue()->toInt()) / 3;
                $samples[] = $brightness;
            } catch (\Exception $e) {
                continue;
            }
        }
        
        if (empty($samples)) {
            return true; // Default to speech bubble treatment
        }
        
        $avgBrightness = array_sum($samples) / count($samples);
        $brightCount = count(array_filter($samples, fn($b) => $b > 200));
        
        // If average brightness > 190 or 60%+ of samples are bright
        return $avgBrightness > 190 || ($brightCount / count($samples)) > 0.6;
    }

    /**
     * Bersihkan balon percakapan dengan cepat (solid fill) menggunakan warna background balon.
     * Berjalan instan (<1ms) tanpa memanggil script Python LaMa di CPU.
     * Padding diperbesar agar bekas teks lama benar-benar tertutup sempurna.
     */
    protected function cleanSpeechBubbleFast($image, int $x, int $y, int $width, int $height): void
    {
        $imgWidth = $image->width();
        $imgHeight = $image->height();

        // Sampel warna dari area yang lebih luas di sekitar teks (bukan tepian sempit)
        $samples = [];
        $sampleMargin = 8; // Lebih jauh dari tepi teks untuk dapat warna balon murni

        // Sampel baris di atas dan bawah teks
        for ($i = 0; $i <= 8; $i++) {
            $sx = $x + (int) ($width * $i / 8);
            $sx = max(0, min($imgWidth - 1, $sx));
            $syTop    = max(0, $y - $sampleMargin);
            $syBottom = min($imgHeight - 1, $y + $height + $sampleMargin);

            try {
                $cTop = $image->pickColor($sx, $syTop);
                $cBot = $image->pickColor($sx, $syBottom);
                $samples[] = ['r' => $cTop->red()->toInt(), 'g' => $cTop->green()->toInt(), 'b' => $cTop->blue()->toInt()];
                $samples[] = ['r' => $cBot->red()->toInt(), 'g' => $cBot->green()->toInt(), 'b' => $cBot->blue()->toInt()];
            } catch (\Exception $e) {}
        }

        // Sampel kolom kiri dan kanan teks
        for ($i = 0; $i <= 4; $i++) {
            $sy = $y + (int) ($height * $i / 4);
            $sy = max(0, min($imgHeight - 1, $sy));
            $sxLeft  = max(0, $x - $sampleMargin);
            $sxRight = min($imgWidth - 1, $x + $width + $sampleMargin);

            try {
                $cLeft  = $image->pickColor($sxLeft, $sy);
                $cRight = $image->pickColor($sxRight, $sy);
                $samples[] = ['r' => $cLeft->red()->toInt(),  'g' => $cLeft->green()->toInt(),  'b' => $cLeft->blue()->toInt()];
                $samples[] = ['r' => $cRight->red()->toInt(), 'g' => $cRight->green()->toInt(), 'b' => $cRight->blue()->toInt()];
            } catch (\Exception $e) {}
        }

        // Ambil hanya piksel terang (hindari outline hitam balon)
        $brightSamples = array_filter($samples, function ($c) {
            return (($c['r'] + $c['g'] + $c['b']) / 3) > 175;
        });

        if (!empty($brightSamples)) {
            $bgColor = $this->getAverageColor(array_values($brightSamples), ['r' => 255, 'g' => 255, 'b' => 255]);
        } else {
            $bgColor = ['r' => 255, 'g' => 255, 'b' => 255]; // Standar putih balon komik
        }

        $hexColor = sprintf('#%02X%02X%02X', $bgColor['r'], $bgColor['g'], $bgColor['b']);

        // Padding besar (6px) agar semua sisa piksel huruf lama tertutup sempurna
        $pad = 6;
        $fillX = max(0, $x - $pad);
        $fillY = max(0, $y - $pad);
        $fillW = min($width + $pad * 2, $imgWidth - $fillX);
        $fillH = min($height + $pad * 2, $imgHeight - $fillY);

        $image->drawRectangle($fillX, $fillY, function ($draw) use ($fillW, $fillH, $hexColor) {
            $draw->size($fillW, $fillH);
            $draw->background($hexColor);
        });
    }
    
    /**
     * Inpaint a region using surrounding pixel colors for gradient fill.
     * This creates a more natural blend with complex backgrounds.
     */
    protected function inpaintRegion($image, int $x, int $y, int $width, int $height): void
    {
        $imageWidth = $image->width();
        $imageHeight = $image->height();
        
        // Sample colors from edges (outside the text region)
        $edgeColors = [
            'top' => [],
            'bottom' => [],
            'left' => [],
            'right' => []
        ];
        
        $sampleMargin = 3;
        $samplesPerEdge = 5;
        
        // Sample top edge
        for ($i = 0; $i < $samplesPerEdge; $i++) {
            $px = $x + (int)($width * $i / ($samplesPerEdge - 1));
            $py = max(0, $y - $sampleMargin);
            $px = max(0, min($imageWidth - 1, $px));
            try {
                $color = $image->pickColor($px, $py);
                $edgeColors['top'][] = [
                    'r' => $color->red()->toInt(),
                    'g' => $color->green()->toInt(),
                    'b' => $color->blue()->toInt()
                ];
            } catch (\Exception $e) {}
        }
        
        // Sample bottom edge
        for ($i = 0; $i < $samplesPerEdge; $i++) {
            $px = $x + (int)($width * $i / ($samplesPerEdge - 1));
            $py = min($imageHeight - 1, $y + $height + $sampleMargin);
            $px = max(0, min($imageWidth - 1, $px));
            try {
                $color = $image->pickColor($px, $py);
                $edgeColors['bottom'][] = [
                    'r' => $color->red()->toInt(),
                    'g' => $color->green()->toInt(),
                    'b' => $color->blue()->toInt()
                ];
            } catch (\Exception $e) {}
        }
        
        // Sample left edge
        for ($i = 0; $i < $samplesPerEdge; $i++) {
            $px = max(0, $x - $sampleMargin);
            $py = $y + (int)($height * $i / ($samplesPerEdge - 1));
            $py = max(0, min($imageHeight - 1, $py));
            try {
                $color = $image->pickColor($px, $py);
                $edgeColors['left'][] = [
                    'r' => $color->red()->toInt(),
                    'g' => $color->green()->toInt(),
                    'b' => $color->blue()->toInt()
                ];
            } catch (\Exception $e) {}
        }
        
        // Sample right edge
        for ($i = 0; $i < $samplesPerEdge; $i++) {
            $px = min($imageWidth - 1, $x + $width + $sampleMargin);
            $py = $y + (int)($height * $i / ($samplesPerEdge - 1));
            $py = max(0, min($imageHeight - 1, $py));
            try {
                $color = $image->pickColor($px, $py);
                $edgeColors['right'][] = [
                    'r' => $color->red()->toInt(),
                    'g' => $color->green()->toInt(),
                    'b' => $color->blue()->toInt()
                ];
            } catch (\Exception $e) {}
        }
        
        // Calculate average color for each edge
        $avgColors = [];
        foreach ($edgeColors as $edge => $colors) {
            if (!empty($colors)) {
                $avgColors[$edge] = [
                    'r' => (int)(array_sum(array_column($colors, 'r')) / count($colors)),
                    'g' => (int)(array_sum(array_column($colors, 'g')) / count($colors)),
                    'b' => (int)(array_sum(array_column($colors, 'b')) / count($colors))
                ];
            }
        }
        
        // If we have edge colors, do gradient fill
        if (!empty($avgColors)) {
            // Use horizontal gradient (left to right) as primary, with vertical blend
            $this->fillWithGradient($image, $x, $y, $width, $height, $avgColors);
        } else {
            // Fallback to simple estimated background
            $bgColor = $this->estimateBackgroundColor($image, $x, $y, $width, $height);
            $image->drawRectangle($x, $y, function ($draw) use ($width, $height, $bgColor) {
                $draw->size($width, $height);
                $draw->background($bgColor);
            });
        }
    }
    
    /**
     * Fill region with gradient based on edge colors.
     */
    protected function fillWithGradient($image, int $x, int $y, int $width, int $height, array $edgeColors): void
    {
        // Get corner colors by averaging adjacent edges
        $topLeft = $this->blendColors(
            $edgeColors['top'][0] ?? $edgeColors['left'][0] ?? ['r' => 255, 'g' => 255, 'b' => 255],
            $edgeColors['left'][0] ?? $edgeColors['top'][0] ?? ['r' => 255, 'g' => 255, 'b' => 255]
        );
        $topRight = $this->blendColors(
            $edgeColors['top'][count($edgeColors['top'] ?? []) - 1] ?? $edgeColors['right'][0] ?? ['r' => 255, 'g' => 255, 'b' => 255],
            $edgeColors['right'][0] ?? $edgeColors['top'][count($edgeColors['top'] ?? []) - 1] ?? ['r' => 255, 'g' => 255, 'b' => 255]
        );
        $bottomLeft = $this->blendColors(
            $edgeColors['bottom'][0] ?? $edgeColors['left'][count($edgeColors['left'] ?? []) - 1] ?? ['r' => 255, 'g' => 255, 'b' => 255],
            $edgeColors['left'][count($edgeColors['left'] ?? []) - 1] ?? $edgeColors['bottom'][0] ?? ['r' => 255, 'g' => 255, 'b' => 255]
        );
        $bottomRight = $this->blendColors(
            $edgeColors['bottom'][count($edgeColors['bottom'] ?? []) - 1] ?? $edgeColors['right'][count($edgeColors['right'] ?? []) - 1] ?? ['r' => 255, 'g' => 255, 'b' => 255],
            $edgeColors['right'][count($edgeColors['right'] ?? []) - 1] ?? $edgeColors['bottom'][count($edgeColors['bottom'] ?? []) - 1] ?? ['r' => 255, 'g' => 255, 'b' => 255]
        );
        
        // For performance, fill in horizontal strips with interpolated colors
        $stripHeight = max(1, (int)($height / 10)); // 10 strips
        
        for ($stripY = 0; $stripY < $height; $stripY += $stripHeight) {
            $verticalRatio = $stripY / max(1, $height - 1);
            
            // Interpolate left and right colors for this strip
            $leftColor = $this->interpolateColor($topLeft, $bottomLeft, $verticalRatio);
            $rightColor = $this->interpolateColor($topRight, $bottomRight, $verticalRatio);
            
            // For simplicity, use the average color for this strip
            $stripColor = $this->blendColors($leftColor, $rightColor);
            $hexColor = sprintf('#%02X%02X%02X', $stripColor['r'], $stripColor['g'], $stripColor['b']);
            
            $currentStripHeight = min($stripHeight, $height - $stripY);
            
            $image->drawRectangle($x, $y + $stripY, function ($draw) use ($width, $currentStripHeight, $hexColor) {
                $draw->size($width, $currentStripHeight);
                $draw->background($hexColor);
            });
        }
    }
    
    /**
     * Blend two colors by averaging.
     */
    protected function blendColors(array $color1, array $color2): array
    {
        return [
            'r' => (int)(($color1['r'] + $color2['r']) / 2),
            'g' => (int)(($color1['g'] + $color2['g']) / 2),
            'b' => (int)(($color1['b'] + $color2['b']) / 2)
        ];
    }
    
    /**
     * Interpolate between two colors.
     */
    protected function interpolateColor(array $color1, array $color2, float $ratio): array
    {
        return [
            'r' => (int)($color1['r'] + ($color2['r'] - $color1['r']) * $ratio),
            'g' => (int)($color1['g'] + ($color2['g'] - $color1['g']) * $ratio),
            'b' => (int)($color1['b'] + ($color2['b'] - $color1['b']) * $ratio)
        ];
    }

    /**
     * Overlay translated text on the image.
     * Matches the original text's size, position, and fills the same area.
     */
    protected function overlayTextBlock($image, array $textBlock): void
    {
        $text = $textBlock['text'] ?? '';
        $x = (int) ($textBlock['x'] ?? 0);
        $y = (int) ($textBlock['y'] ?? 0);
        $width = (int) ($textBlock['width'] ?? 100);
        $height = (int) ($textBlock['height'] ?? 20);
        
        Log::debug('overlayTextBlock called', [
            'text' => mb_substr($text, 0, 30),
            'position' => ['x' => $x, 'y' => $y, 'w' => $width, 'h' => $height],
        ]);
        
        if (empty($text) || $width <= 0 || $height <= 0) {
            return;
        }

        // Sanitasi teks: ganti karakter yang tidak dikenal font (penyebab kotak/box) dengan alternatif aman
        $text = $this->sanitizeTextForFont($text);

        if (empty(trim($text))) {
            return;
        }


        // Detect optimal text color based on background brightness
        $textColor = $this->detectTextColorFromBackground($image, $x, $y, $width, $height);
        
        // Calculate font size based on original text area height
        // Use the height as primary guide for font size (matching original)
        $fontSize = $this->calculateFontSizeFromHeight($text, $width, $height);
        
        // Wrap text to fit width if needed
        $wrappedText = $this->wrapTextForArea($text, $width - 4, $fontSize);
        $lineCount = substr_count($wrappedText, "\n") + 1;
        
        // Calculate text dimensions for centering
        $lineHeight = $fontSize * 1.15;
        $totalTextHeight = $lineCount * $lineHeight;
        
        // Center text vertically and horizontally within the block
        $textY = $y + ($height / 2) - ($totalTextHeight / 2) + ($fontSize * 0.85);
        $textY = max($y + 2, $textY);
        $textX = $x + ($width / 2);

        Log::debug('Text styling', [
            'color' => $textColor,
            'fontSize' => $fontSize,
            'lines' => $lineCount,
        ]);

        // Draw text with bold simulation (draw multiple times with offsets for thickness)
        $this->drawBoldText($image, $wrappedText, (int)$textX, (int)$textY, $fontSize, $textColor);
    }
    
    /**
     * Draw bold text menggunakan font bold yang sebenarnya (malgunbd.ttf).
     * Semua teks terjemahan dipaksa bold agar konsisten dengan gaya font komik.
     */
    protected function drawBoldText($image, string $text, int $x, int $y, int $fontSize, string $color): void
    {
        // Prioritaskan bold font agar tampilan seragam di semua panel
        $fontFile = file_exists($this->boldFontPath) ? $this->boldFontPath : (
            file_exists($this->fontPath) ? $this->fontPath : null
        );
        
        if ($fontFile) {
            $image->text($text, $x, $y, function ($font) use ($fontSize, $color, $fontFile) {
                $font->file($fontFile);
                $font->size($fontSize);
                $font->color($color);
                $font->align('center');
                $font->valign('top');
            });
        } else {
            // Fallback GD default (tanpa file font kustom)
            $image->text($text, $x, $y, function ($font) use ($fontSize, $color) {
                $font->size($fontSize);
                $font->color($color);
                $font->align('center');
                $font->valign('top');
            });
        }
    }
    
    /**
     * Sanitasi teks agar karakter yang tidak didukung font tidak muncul sebagai kotak.
     * Font Malgun Gothic mendukung Latin dan Hangul, tapi beberapa Unicode symbol tidak ada.
     */
    protected function sanitizeTextForFont(string $text): string
    {
        // Ganti tanda minus/dash berbeda ke hyphen standar
        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2212}", "\u{2010}"], '-', $text);
        
        // Ganti tanda kutip curly ke straight quote
        $text = str_replace(["\u{2018}", "\u{2019}"], "'", $text);
        $text = str_replace(["\u{201C}", "\u{201D}"], '"', $text);
        
        // Ganti ellipsis Unicode ke tiga titik biasa
        $text = str_replace("\u{2026}", '...', $text);
        
        // Hapus karakter kontrol dan karakter di luar Basic Multilingual Plane
        // yang kemungkinan besar tidak ada di font (penyebab kotak)
        $text = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);
        
        // Hapus emoji dan symbol khusus yang tidak ada di font komik standar
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);
        
        return trim($text);
    }

    /**
     * Detect optimal text color based on background brightness.
     * Returns black for light backgrounds, white for dark backgrounds.
     */
    protected function detectTextColorFromBackground($image, int $x, int $y, int $width, int $height): string
    {
        try {
            $imgWidth = $image->width();
            $imgHeight = $image->height();
            
            // Sample center point of the text area
            $centerX = max(0, min($imgWidth - 1, $x + $width / 2));
            $centerY = max(0, min($imgHeight - 1, $y + $height / 2));
            
            $pixel = $image->pickColor((int)$centerX, (int)$centerY);
            
            if ($pixel) {
                $r = $pixel->red()->toInt();
                $g = $pixel->green()->toInt();
                $b = $pixel->blue()->toInt();
                
                // Calculate perceived brightness
                $brightness = (0.299 * $r + 0.587 * $g + 0.114 * $b);
                
                // Use black text on light backgrounds (>128), white on dark
                return $brightness > 128 ? '#000000' : '#ffffff';
            }
        } catch (\Exception $e) {
            Log::warning('Color detection failed', ['error' => $e->getMessage()]);
        }
        
        return '#000000'; // Default to black
    }
    
    /**
     * Hitung font size secara dinamis agar mengisi area bounding box seoptimal mungkin.
     * Menggunakan binary search untuk mencari font size terbesar yang masih muat di area.
     * Panel besar = font lebih besar, panel kecil = font lebih kecil, proporsional otomatis.
     */
    protected function calculateFontSizeFromHeight(string $text, int $width, int $height): int
    {
        if ($width <= 0 || $height <= 0 || empty($text)) {
            return 13;
        }

        $textLength = mb_strlen($text);
        $hasAsianChars = (bool) preg_match('/[\x{AC00}-\x{D7AF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text);

        // Rasio lebar per karakter (Latin lebih sempit dari CJK)
        $charWidthRatio = $hasAsianChars ? 0.85 : 0.60;
        // Rasio tinggi per baris (font size * line height)
        $lineHeightRatio = 1.35;

        // Binary search: cari font size terbesar yang masih muat di (width x height)
        $low  = 9;
        $high = 42; // Maksimum 42px untuk panel besar
        $best = 12;

        for ($i = 0; $i < 12; $i++) {
            $mid = (int) (($low + $high) / 2);

            // Estimasi chars per baris dan jumlah baris untuk ukuran ini
            $charsPerLine  = max(1, (int) ($width / ($mid * $charWidthRatio)));
            $estimatedLines = max(1, (int) ceil($textLength / $charsPerLine));
            $totalHeight   = $estimatedLines * $mid * $lineHeightRatio;

            if ($totalHeight <= $height * 0.88) {
                // Masih muat - coba ukuran lebih besar
                $best = $mid;
                $low  = $mid + 1;
            } else {
                // Tidak muat - coba ukuran lebih kecil
                $high = $mid - 1;
            }
        }

        // Clamp: min 10px agar tetap terbaca, max 38px agar tidak mencolok
        return max(10, min(38, $best));
    }

    
    /**
     * Calculate optimal font size to fill the available area.
     * Tries to match the original text's visual presence.
     */
    protected function calculateOptimalFontSize(string $text, int $maxWidth, int $maxHeight): int
    {
        $textLength = mb_strlen($text);
        
        // Start with a font size based on height - increased multiplier for bigger text
        $maxFontSizeByHeight = (int) ($maxHeight * 1.3);
        
        // Estimate chars per line at this font size (rough approximation)
        // Average char width is about 0.6 * fontSize for CJK, 0.5 for Latin
        $hasAsianChars = preg_match('/[\x{AC00}-\x{D7AF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text);
        $charWidthRatio = $hasAsianChars ? 0.6 : 0.5;
        
        // Calculate font size that would fit text width
        $charsPerLine = max(1, (int)($maxWidth / ($maxFontSizeByHeight * $charWidthRatio)));
        $estimatedLines = ceil($textLength / $charsPerLine);
        
        // Adjust font size based on how many lines we need
        $maxFontSizeByLines = (int) ($maxHeight / max(1, $estimatedLines) / 1.2);
        
        // Use the smaller of the two to ensure fit
        $fontSize = min($maxFontSizeByHeight, $maxFontSizeByLines);
        
        // Clamp to reasonable range - increased minimum for readability
        return max(18, min(80, $fontSize));
    }
    
    /**
     * Wrap text to fit within a given area, optimizing for readability.
     */
    protected function wrapTextForArea(string $text, int $maxWidth, int $fontSize): string
    {
        // Estimate char width (increased ratio for better accuracy)
        $hasAsianChars = preg_match('/[\x{AC00}-\x{D7AF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text);
        $charWidthRatio = $hasAsianChars ? 0.6 : 0.55;
        $charWidth = $fontSize * $charWidthRatio;
        
        $maxCharsPerLine = max(1, (int)($maxWidth / $charWidth));
        
        // For very short text, no wrapping needed
        if (mb_strlen($text) <= $maxCharsPerLine) {
            return $text;
        }
        
        // Word wrap for Latin text, character wrap for Asian
        if ($hasAsianChars) {
            // Character-based wrapping for Asian text
            return wordwrap($text, $maxCharsPerLine, "\n", true);
        } else {
            // Word-based wrapping for Latin text
            return wordwrap($text, $maxCharsPerLine, "\n", false);
        }
    }

    /**
     * Estimate background color by sampling around the text region.
     * Uses multiple sampling points and median calculation for better accuracy.
     */
    protected function estimateBackgroundColor($image, int $x, int $y, int $width, int $height): string
    {
        $imageWidth = $image->width();
        $imageHeight = $image->height();
        
        $samples = [];
        $margin = 3; // Distance from the text region to sample
        
        // Generate more sampling points around the perimeter of the text region
        $samplePoints = [];
        
        // Top edge - 4 points
        for ($i = 0; $i < 4; $i++) {
            $samplePoints[] = [$x + ($width * $i / 3), $y - $margin];
        }
        
        // Bottom edge - 4 points
        for ($i = 0; $i < 4; $i++) {
            $samplePoints[] = [$x + ($width * $i / 3), $y + $height + $margin];
        }
        
        // Left edge - 3 points (excluding corners)
        for ($i = 1; $i < 3; $i++) {
            $samplePoints[] = [$x - $margin, $y + ($height * $i / 3)];
        }
        
        // Right edge - 3 points (excluding corners)
        for ($i = 1; $i < 3; $i++) {
            $samplePoints[] = [$x + $width + $margin, $y + ($height * $i / 3)];
        }
        
        // Corner sampling (further out for more background)
        $cornerMargin = 5;
        $samplePoints[] = [$x - $cornerMargin, $y - $cornerMargin];
        $samplePoints[] = [$x + $width + $cornerMargin, $y - $cornerMargin];
        $samplePoints[] = [$x - $cornerMargin, $y + $height + $cornerMargin];
        $samplePoints[] = [$x + $width + $cornerMargin, $y + $height + $cornerMargin];
        
        foreach ($samplePoints as $point) {
            $px = max(0, min($imageWidth - 1, (int)$point[0]));
            $py = max(0, min($imageHeight - 1, (int)$point[1]));
            
            try {
                $color = $image->pickColor($px, $py);
                $r = $color->red()->toInt();
                $g = $color->green()->toInt();
                $b = $color->blue()->toInt();
                
                $samples[] = [
                    'r' => $r,
                    'g' => $g,
                    'b' => $b,
                    'brightness' => ($r + $g + $b) / 3, // For later analysis
                ];
            } catch (\Exception $e) {
                // Skip if color picking fails
            }
        }
        
        if (empty($samples)) {
            return '#FFFFFF'; // Default to white (common for speech bubbles)
        }
        
        // Check if this is likely a speech bubble (most samples are bright/white)
        $brightCount = 0;
        foreach ($samples as $sample) {
            if ($sample['brightness'] > 230) {
                $brightCount++;
            }
        }
        
        // If more than 70% of samples are very bright, it's likely a white speech bubble
        if ($brightCount / count($samples) > 0.7) {
            return '#FFFFFF';
        }
        
        // Use median instead of average (more robust to outliers like text pixels)
        $rValues = array_column($samples, 'r');
        $gValues = array_column($samples, 'g');
        $bValues = array_column($samples, 'b');
        
        sort($rValues);
        sort($gValues);
        sort($bValues);
        
        $mid = (int)(count($samples) / 2);
        $medR = $rValues[$mid];
        $medG = $gValues[$mid];
        $medB = $bValues[$mid];
        
        return sprintf('#%02X%02X%02X', $medR, $medG, $medB);
    }

    /**
     * Calculate optimal font size to fit text in given dimensions.
     */
    protected function calculateFontSize(string $text, int $width, int $height): int
    {
        $textLength = mb_strlen($text);
        
        // Estimate characters per line based on width
        $charsPerLine = max(1, (int) ($width / ($this->defaultFontSize * 0.6)));
        $lines = ceil($textLength / $charsPerLine);
        
        // Calculate font size based on available height
        $availableHeight = $height - ($this->padding * 2);
        $fontSize = (int) ($availableHeight / max(1, $lines));
        
        // Clamp font size to reasonable bounds
        return max(8, min($fontSize, $this->defaultFontSize * 2));
    }

    /**
     * Wrap text to fit within a given width.
     */
    protected function wrapText(string $text, int $width, int $fontSize): string
    {
        $charsPerLine = max(1, (int) ($width / ($fontSize * 0.6)));
        
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';
        
        foreach ($words as $word) {
            if (mb_strlen($currentLine . ' ' . $word) <= $charsPerLine) {
                $currentLine .= ($currentLine ? ' ' : '') . $word;
            } else {
                if ($currentLine) {
                    $lines[] = $currentLine;
                }
                // Handle very long words
                if (mb_strlen($word) > $charsPerLine) {
                    while (mb_strlen($word) > $charsPerLine) {
                        $lines[] = mb_substr($word, 0, $charsPerLine);
                        $word = mb_substr($word, $charsPerLine);
                    }
                }
                $currentLine = $word;
            }
        }
        
        if ($currentLine) {
            $lines[] = $currentLine;
        }
        
        return implode("\n", $lines);
    }

    /**
     * Generate path for translated image.
     */
    protected function generateTranslatedPath(ChapterPage $page): string
    {
        $originalPath = $page->original_image;
        
        // Normalize path separators to forward slashes
        $normalizedPath = str_replace('\\', '/', $originalPath);
        $pathInfo = pathinfo($normalizedPath);
        
        // Replace 'original' with 'translated/id' in path
        $translatedDir = str_replace('/original', '/translated/id', $pathInfo['dirname']);
        
        // If original wasn't found (path doesn't contain /original), append translated folder
        if ($translatedDir === $pathInfo['dirname']) {
            $translatedDir = $pathInfo['dirname'] . '/translated/id';
        }
        
        $translatedPath = "{$translatedDir}/{$pathInfo['basename']}";
        
        Log::debug('Generated translated path', [
            'original_path' => $originalPath,
            'translated_path' => $translatedPath,
        ]);
        
        return $translatedPath;
    }

    /**
     * Remove text from image and return clean image path.
     * 
     * @param string $imagePath Path relative to storage/app/public
     * @param array $textPositions Array of text positions to remove
     * @return string|null Path to cleaned image
     */
    public function removeTextFromImage(string $imagePath, array $textPositions): ?string
    {
        $fullPath = Storage::disk('public')->path($imagePath);
        
        if (!file_exists($fullPath)) {
            return null;
        }

        try {
            $image = $this->manager->read($fullPath);
            
            foreach ($textPositions as $textBlock) {
                $this->removeTextBlock($image, $textBlock);
            }
            
            // Save to temp location
            $pathInfo = pathinfo($imagePath);
            $cleanPath = "{$pathInfo['dirname']}/clean_{$pathInfo['basename']}";
            $fullCleanPath = Storage::disk('public')->path($cleanPath);
            
            // Ensure directory exists
            $directory = dirname($fullCleanPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            $image->save($fullCleanPath, quality: 90);
            
            return $cleanPath;
        } catch (\Exception $e) {
            Log::error('Remove text from image failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Overlay text on image.
     * 
     * @param string $imagePath Path relative to storage/app/public
     * @param array $textBlocks Array of text blocks with positions
     * @return string|null Path to overlaid image
     */
    public function overlayTextOnImage(string $imagePath, array $textBlocks): ?string
    {
        $fullPath = Storage::disk('public')->path($imagePath);
        
        if (!file_exists($fullPath)) {
            return null;
        }

        try {
            $image = $this->manager->read($fullPath);
            
            foreach ($textBlocks as $textBlock) {
                $this->overlayTextBlock($image, $textBlock);
            }
            
            // Save to output location
            $pathInfo = pathinfo($imagePath);
            $outputPath = "{$pathInfo['dirname']}/overlay_{$pathInfo['basename']}";
            $fullOutputPath = Storage::disk('public')->path($outputPath);
            
            $image->save($fullOutputPath, quality: 90);
            
            return $outputPath;
        } catch (\Exception $e) {
            Log::error('Overlay text on image failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get image dimensions.
     */
    public function getImageDimensions(string $imagePath): ?array
    {
        $fullPath = Storage::disk('public')->path($imagePath);
        
        if (!file_exists($fullPath)) {
            return null;
        }

        try {
            $image = $this->manager->read($fullPath);
            return [
                'width' => $image->width(),
                'height' => $image->height(),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}

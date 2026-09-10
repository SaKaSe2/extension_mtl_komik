<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use thiagoalessio\TesseractOCR\TesseractOCR;
use App\Services\GoogleVisionOcrService;

class OcrService
{
    protected string $tesseractPath;      // Raw path for TesseractOCR library
    protected string $tesseractPathQuoted; // Quoted path for shell_exec calls
    protected ?string $tessdataDir = null; // Custom tessdata directory
    protected string $language;
    protected int $dpi;
    protected array $availableLanguages = [];

    public function __construct()
    {
        $rawPath = config('ocr.tesseract_path', 'tesseract');
        
        // Store raw path for TesseractOCR library (it handles quoting internally)
        $this->tesseractPath = $rawPath;
        
        // Quoted path for shell_exec commands (spaces in path)
        $this->tesseractPathQuoted = strpos($rawPath, ' ') !== false && !str_starts_with($rawPath, '"')
            ? '"' . $rawPath . '"'
            : $rawPath;
        
        // Custom tessdata directory (for language packs in non-default location)
        $tessdataPrefix = config('ocr.tessdata_prefix');
        if ($tessdataPrefix && is_dir($tessdataPrefix)) {
            $this->tessdataDir = $tessdataPrefix;
        }
        
        // Get available languages from Tesseract
        $this->availableLanguages = $this->detectAvailableLanguages();
        
        // Filter configured languages to only use available ones
        $configuredLanguages = explode('+', config('ocr.default_language', 'jpn+kor+chi_sim+chi_tra+eng'));
        $validLanguages = array_intersect($configuredLanguages, $this->availableLanguages);
        
        // Fallback to 'eng' if no configured languages are available
        if (empty($validLanguages)) {
            $validLanguages = in_array('eng', $this->availableLanguages) ? ['eng'] : [];
        }
        
        $this->language = !empty($validLanguages) ? implode('+', $validLanguages) : 'eng';
        $this->dpi = config('ocr.dpi', 300);
        
        // Log info about languages being used
        Log::info('OCR Service initialized', [
            'tesseract_path' => $this->tesseractPath,
            'tessdata_dir' => $this->tessdataDir,
            'languages' => $this->language,
            'available' => $this->availableLanguages,
        ]);
        
        // Log warning if some languages are not available
        $missingLanguages = array_diff($configuredLanguages, $this->availableLanguages);
        if (!empty($missingLanguages)) {
            Log::warning('OCR: Some language packs are not installed', [
                'missing' => $missingLanguages,
                'available' => $this->availableLanguages,
                'using' => $this->language,
            ]);
        }
    }
    
    /**
     * Build command with tessdata dir if needed.
     */
    protected function buildCommand(string $args = ''): string
    {
        $cmd = $this->tesseractPathQuoted;
        if ($this->tessdataDir) {
            $cmd .= ' --tessdata-dir "' . $this->tessdataDir . '"';
        }
        if ($args) {
            $cmd .= ' ' . $args;
        }
        return $cmd;
    }
    
    /**
     * Configure TesseractOCR instance with custom tessdata path if needed.
     */
    protected function configureOcr(TesseractOCR $ocr): TesseractOCR
    {
        $ocr->executable($this->tesseractPath);
        
        // Set custom tessdata directory if configured
        if ($this->tessdataDir) {
            $ocr->tessdataDir($this->tessdataDir);
        }
        
        return $ocr->lang($this->language)->dpi($this->dpi);
    }
    
    /**
     * Detect available Tesseract languages.
     */
    protected function detectAvailableLanguages(): array
    {
        try {
            // Use buildCommand to include tessdata-dir if set
            $output = shell_exec($this->buildCommand('--list-langs 2>&1'));
            if (!$output) {
                return [];
            }
            
            $lines = explode("\n", $output);
            $languages = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                // Skip header line, empty lines, and path entries
                if (!empty($line) && 
                    !str_contains($line, 'List of') && 
                    !str_contains($line, ':') &&
                    !str_contains($line, '/') &&
                    !str_contains($line, '\\')) {
                    $languages[] = $line;
                }
            }
            
            return $languages;
        } catch (\Exception $e) {
            Log::warning('Failed to detect Tesseract languages', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Extract text from an image file.
     * Uses PaddleOCR (if available) for better CJK detection, falls back to Tesseract.
     * 
     * @param string $imagePath Path relative to storage/app/public
     * @return array Array of text blocks with positions
     */
    public function extractText(string $imagePath): array
    {
        $fullPath = Storage::disk('public')->path($imagePath);

        if (!file_exists($fullPath)) {
            Log::error('OCR: Image file not found', ['path' => $fullPath]);
            return [];
        }

        // Priority 1: Hugging Face ZeroGPU OCR (Cloud A10G GPU, free)
        if (config('ocr.use_huggingface_ocr', true)) {
            $hfUrl = config('ocr.huggingface_ocr_url', 'https://rikza2-komiko-translator.hf.space');
            $hfResult = $this->extractTextWithHuggingFace($fullPath, $hfUrl);
            if (!empty($hfResult)) {
                Log::info('OCR: Hugging Face ZeroGPU OCR succeeded', ['blocks' => count($hfResult)]);
                return $hfResult;
            }
            Log::warning('OCR: Hugging Face OCR returned no results or failed');
        }

        // Priority 2: PaddleOCR (hanya jika diaktifkan dan URL dikonfigurasi)
        $paddleOcrUrl = config('ocr.paddle_ocr_url');
        if (config('ocr.use_paddle_ocr', false) && !empty($paddleOcrUrl)) {
            $paddleResult = $this->extractTextWithPaddleOcr($fullPath, $paddleOcrUrl);
            if (!empty($paddleResult)) {
                Log::info('OCR: PaddleOCR succeeded', ['blocks' => count($paddleResult)]);
                return $paddleResult;
            }
        }

        // Priority 3: Google Cloud Vision (cloud-based, no install needed)
        $googleVision = app(GoogleVisionOcrService::class);
        if ($googleVision->isAvailable()) {
            $visionResult = $googleVision->detectText($fullPath);
            if (!empty($visionResult)) {
                Log::info('OCR: Google Vision succeeded', ['blocks' => count($visionResult)]);
                return $visionResult;
            }
            Log::warning('OCR: Google Vision returned no results');
        }

        // Priority 4: Tesseract (needs local install)
        return $this->extractTextWithTesseract($fullPath);
    }

    /**
     * Extract text using Hugging Face ZeroGPU Gradio API.
     */
    protected function extractTextWithHuggingFace(string $fullPath, string $apiUrl): array
    {
        $baseUrl = rtrim($apiUrl, '/');
        Log::info('HuggingFace OCR: Starting request', [
            'image' => basename($fullPath),
            'url' => $baseUrl,
        ]);

        try {
            $headers = [];
            $token = config('ocr.huggingface_token');
            if (!empty($token)) {
                $headers['Authorization'] = 'Bearer ' . trim($token);
            }

            // Step 1: Upload image file to Gradio upload endpoint
            $uploadResponse = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders($headers)
                ->attach('files', file_get_contents($fullPath), basename($fullPath))
                ->post("{$baseUrl}/gradio_api/upload");

            if (!$uploadResponse->successful()) {
                Log::warning('HuggingFace OCR: Upload failed', [
                    'status' => $uploadResponse->status(),
                    'body' => substr($uploadResponse->body(), 0, 300),
                ]);
                return [];
            }

            $uploadedFiles = $uploadResponse->json();
            if (empty($uploadedFiles[0])) {
                Log::warning('HuggingFace OCR: Empty upload response');
                return [];
            }

            $uploadedPath = $uploadedFiles[0];

            // Step 2: Trigger process_comic endpoint
            $callResponse = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders($headers)
                ->post("{$baseUrl}/gradio_api/call/process_comic", [
                    'data' => [
                        [
                            'path' => $uploadedPath,
                            'meta' => ['_type' => 'gradio.FileData'],
                            'orig_name' => basename($fullPath),
                        ]
                    ]
                ]);

            if (!$callResponse->successful()) {
                Log::warning('HuggingFace OCR: Predict call failed', ['status' => $callResponse->status()]);
                return [];
            }

            $callData = $callResponse->json();
            $eventId = $callData['event_id'] ?? null;
            if (!$eventId) {
                Log::warning('HuggingFace OCR: No event_id received');
                return [];
            }

            // Step 3: Fetch event stream result
            $resultResponse = \Illuminate\Support\Facades\Http::timeout(60)
                ->withHeaders($headers)
                ->get("{$baseUrl}/gradio_api/call/process_comic/{$eventId}");

            if (!$resultResponse->successful()) {
                Log::warning('HuggingFace OCR: Failed to fetch event result', ['status' => $resultResponse->status()]);
                return [];
            }

            $body = $resultResponse->body();
            $textBlocks = [];
            $lines = explode("\n", $body);
            foreach ($lines as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $jsonStr = substr($line, 6);
                    $eventData = json_decode($jsonStr, true);
                    if (is_array($eventData) && isset($eventData[2]) && is_array($eventData[2])) {
                        foreach ($eventData[2] as $index => $block) {
                            $textBlocks[] = [
                                'id' => $index,
                                'text' => $block['text'] ?? '',
                                'x' => $block['x'] ?? 0,
                                'y' => $block['y'] ?? 0,
                                'width' => $block['width'] ?? 0,
                                'height' => $block['height'] ?? 0,
                                'confidence' => $block['confidence'] ?? 0,
                            ];
                        }
                    }
                }
            }

            Log::info('HuggingFace OCR: Successfully detected blocks', ['count' => count($textBlocks)]);
            return $textBlocks;

        } catch (\Exception $e) {
            Log::warning('HuggingFace OCR: Exception occurred', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Extract text using PaddleOCR microservice.
     */
    protected function extractTextWithPaddleOcr(string $fullPath, string $apiUrl): array
    {
        Log::info('PaddleOCR: Starting OCR request', [
            'image' => basename($fullPath),
            'url' => "{$apiUrl}/ocr/multi",
        ]);
        
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->attach('image', file_get_contents($fullPath), basename($fullPath))
                ->post("{$apiUrl}/ocr/multi");
            
            Log::debug('PaddleOCR: Response received', [
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);
            
            if (!$response->successful()) {
                Log::warning('PaddleOCR: Request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return [];
            }
            
            $data = $response->json();
            
            if (!($data['success'] ?? false)) {
                Log::warning('PaddleOCR: API returned error', ['error' => $data['error'] ?? 'Unknown']);
                return [];
            }
            
            Log::info('PaddleOCR: OCR successful', ['blocks' => count($data['text_blocks'] ?? [])]);
            
            $textBlocks = [];
            foreach ($data['text_blocks'] ?? [] as $index => $block) {
                $textBlocks[] = [
                    'id' => $index,
                    'text' => $block['text'] ?? '',
                    'x' => $block['x'] ?? 0,
                    'y' => $block['y'] ?? 0,
                    'width' => $block['width'] ?? 0,
                    'height' => $block['height'] ?? 0,
                    'confidence' => $block['confidence'] ?? 0,
                ];
            }
            
            return $textBlocks;
            
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('PaddleOCR: Connection failed - is server running?', ['error' => $e->getMessage()]);
            return [];
        } catch (\Exception $e) {
            Log::warning('PaddleOCR: Exception', ['error' => $e->getMessage(), 'type' => get_class($e)]);
            return [];
        }
    }

    /**
     * Extract text using Tesseract OCR (fallback).
     */
    protected function extractTextWithTesseract(string $fullPath): array
    {
        $allTextBlocks = [];

        // Try multiple PSM modes to catch more text
        // PSM 3: Fully automatic page segmentation (default)
        // PSM 6: Assume a single uniform block of text
        // PSM 11: Sparse text - find as much text as possible
        // PSM 12: Sparse text with OSD (orientation and script detection)
        // PSM modes for different text layouts:
        // PSM 3: Fully automatic page segmentation
        // PSM 4: Assume a single column of text of variable sizes
        // PSM 6: Assume a single uniform block of text
        // PSM 8: Treat the image as a single word (good for bubbles)
        // PSM 11: Sparse text - find as much text as possible
        // PSM 12: Sparse text with OSD
        $psmModes = [11, 4, 3, 6, 8]; // Multiple modes for better Korean detection

        foreach ($psmModes as $psm) {
            try {
                $ocr = new TesseractOCR($fullPath);
                $this->configureOcr($ocr)
                    ->psm($psm)
                    ->configFile('hocr');

                $hocrOutput = $ocr->run();
                $blocks = $this->parseHocr($hocrOutput, $fullPath);
                
                // Merge blocks, avoiding duplicates based on position
                foreach ($blocks as $block) {
                    if (!$this->isDuplicateBlock($block, $allTextBlocks)) {
                        $allTextBlocks[] = $block;
                    }
                }
                
                Log::debug('OCR pass completed', [
                    'psm' => $psm,
                    'blocks_found' => count($blocks),
                    'total_blocks' => count($allTextBlocks)
                ]);
                
            } catch (\Exception $e) {
                Log::warning('OCR pass failed', [
                    'psm' => $psm,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }
        
        // Sort blocks by position (top to bottom, left to right)
        usort($allTextBlocks, function($a, $b) {
            $yDiff = ($a['y'] ?? 0) - ($b['y'] ?? 0);
            if (abs($yDiff) > 20) {
                return $yDiff;
            }
            return ($a['x'] ?? 0) - ($b['x'] ?? 0);
        });
        
        // Reassign IDs
        foreach ($allTextBlocks as $index => &$block) {
            $block['id'] = $index;
        }
        
        Log::info('OCR extraction completed', [
            'path' => basename($fullPath),
            'total_blocks' => count($allTextBlocks)
        ]);

        return $allTextBlocks;
    }
    
    /**
     * Check if a text block is a duplicate of an existing block.
     */
    protected function isDuplicateBlock(array $newBlock, array $existingBlocks): bool
    {
        $newText = trim($newBlock['text'] ?? '');
        $newX = $newBlock['x'] ?? 0;
        $newY = $newBlock['y'] ?? 0;
        
        // For Asian languages (Korean, Japanese, Chinese), even 1 character can be meaningful
        // Don't skip short text - let it through
        if (empty($newText)) {
            return true; // Only skip completely empty text
        }
        
        foreach ($existingBlocks as $existing) {
            $existingText = trim($existing['text'] ?? '');
            $existingX = $existing['x'] ?? 0;
            $existingY = $existing['y'] ?? 0;
            
            // Check if positions are close (within 50 pixels for more tolerance)
            $positionClose = abs($newX - $existingX) < 50 && abs($newY - $existingY) < 50;
            
            // Check if text is similar (one contains the other or same)
            $textSimilar = $newText === $existingText ||
                           strpos($existingText, $newText) !== false ||
                           strpos($newText, $existingText) !== false;
            
            if ($positionClose && $textSimilar) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Quick text extraction without position data.
     * 
     * @param string $imagePath Path relative to storage/app/public
     * @return string Extracted text
     */
    public function extractTextSimple(string $imagePath): string
    {
        $fullPath = Storage::disk('public')->path($imagePath);

        if (!file_exists($fullPath)) {
            Log::error('OCR: Image file not found', ['path' => $fullPath]);
            return '';
        }

        try {
            $ocr = new TesseractOCR($fullPath);
            $this->configureOcr($ocr)->psm(6);

            return trim($ocr->run());
        } catch (\Exception $e) {
            Log::error('OCR simple extraction failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Extract text blocks with bounding boxes.
     * Returns structured data for each text bubble/block found.
     * 
     * @param string $imagePath Path relative to storage/app/public
     * @return array Array of text blocks with bounding boxes
     */
    public function extractTextBlocks(string $imagePath): array
    {
        $fullPath = Storage::disk('public')->path($imagePath);

        if (!file_exists($fullPath)) {
            Log::error('OCR: Image file not found', ['path' => $fullPath]);
            return [];
        }

        try {
            $ocr = new TesseractOCR($fullPath);
            $this->configureOcr($ocr)
                ->psm(11) // Sparse text - find as much text as possible
                ->configFile('tsv'); // Tab-separated output with position

            $tsvOutput = $ocr->run();
            
            return $this->parseTsv($tsvOutput);
        } catch (\Exception $e) {
            Log::error('OCR text blocks extraction failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Parse hOCR output to extract text positions.
     */
    protected function parseHocr(string $hocrOutput, string $imagePath): array
    {
        $textBlocks = [];
        
        $minConfidence = (float) config('ocr.min_confidence', 30);
        
        // Parse the hOCR HTML to extract text and bounding boxes
        if (preg_match_all('/<span[^>]+class=[\'"]ocr_line[\'"][^>]*title=[\'"]bbox (\d+) (\d+) (\d+) (\d+)[^"\']*[\'"][^>]*>(.+?)<\/span>/is', $hocrOutput, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                $text = strip_tags($match[5]);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                $text = trim($text);
                
                if (empty($text)) {
                    continue;
                }

                $confidence = $this->extractConfidence($match[0]);
                if ($confidence > 0 && $confidence < $minConfidence) {
                    continue;
                }

                $textBlocks[] = [
                    'id' => $index,
                    'text' => $text,
                    'x' => (int) $match[1],
                    'y' => (int) $match[2],
                    'width' => (int) $match[3] - (int) $match[1],
                    'height' => (int) $match[4] - (int) $match[2],
                    'confidence' => $confidence,
                ];
            }
        }

        // Alternative: parse word-level if no lines found
        if (empty($textBlocks)) {
            if (preg_match_all('/<span[^>]+class=[\'"]ocrx_word[\'"][^>]*title=[\'"]bbox (\d+) (\d+) (\d+) (\d+)[^"\']*[\'"][^>]*>(.+?)<\/span>/is', $hocrOutput, $matches, PREG_SET_ORDER)) {
                $currentBlock = null;
                
                foreach ($matches as $index => $match) {
                    $text = strip_tags($match[5]);
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                    $text = trim($text);
                    
                    if (empty($text)) {
                        continue;
                    }

                    // Group nearby words into blocks
                    $x = (int) $match[1];
                    $y = (int) $match[2];
                    $width = (int) $match[3] - $x;
                    $height = (int) $match[4] - $y;

                    if ($currentBlock && abs($y - $currentBlock['y']) < 20) {
                        // Same line, extend the block
                        $currentBlock['text'] .= ' ' . $text;
                        $currentBlock['width'] = max($currentBlock['width'], $x + $width - $currentBlock['x']);
                    } else {
                        // New block
                        if ($currentBlock) {
                            $textBlocks[] = $currentBlock;
                        }
                        $currentBlock = [
                            'id' => count($textBlocks),
                            'text' => $text,
                            'x' => $x,
                            'y' => $y,
                            'width' => $width,
                            'height' => $height,
                            'confidence' => $this->extractConfidence($match[0]),
                        ];
                    }
                }
                
                if ($currentBlock) {
                    $textBlocks[] = $currentBlock;
                }
            }
        }

        return $textBlocks;
    }

    /**
     * Parse TSV output to extract text blocks.
     */
    protected function parseTsv(string $tsvOutput): array
    {
        $lines = explode("\n", $tsvOutput);
        $textBlocks = [];
        $currentBlock = null;
        $headers = null;

        foreach ($lines as $line) {
            $columns = explode("\t", $line);
            
            if ($headers === null) {
                $headers = $columns;
                continue;
            }

            if (count($columns) < 12) {
                continue;
            }

            $data = array_combine($headers, $columns);
            $text = trim($data['text'] ?? '');
            $confidence = (float) ($data['conf'] ?? 0);

            if (empty($text) || $text === '-1' || $confidence < 30) {
                continue;
            }

            $x = (int) ($data['left'] ?? 0);
            $y = (int) ($data['top'] ?? 0);
            $width = (int) ($data['width'] ?? 0);
            $height = (int) ($data['height'] ?? 0);
            $blockNum = (int) ($data['block_num'] ?? 0);

            // Group by block number
            if ($currentBlock && $currentBlock['block_num'] === $blockNum) {
                $currentBlock['text'] .= ' ' . $text;
                $newRight = $x + $width;
                $newBottom = $y + $height;
                $currentBlock['width'] = max($newRight - $currentBlock['x'], $currentBlock['width']);
                $currentBlock['height'] = max($newBottom - $currentBlock['y'], $currentBlock['height']);
            } else {
                if ($currentBlock && !empty($currentBlock['text'])) {
                    unset($currentBlock['block_num']);
                    $textBlocks[] = $currentBlock;
                }
                $currentBlock = [
                    'id' => count($textBlocks),
                    'text' => $text,
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height,
                    'confidence' => $confidence,
                    'block_num' => $blockNum,
                ];
            }
        }

        if ($currentBlock && !empty($currentBlock['text'])) {
            unset($currentBlock['block_num']);
            $textBlocks[] = $currentBlock;
        }

        return $textBlocks;
    }

    /**
     * Extract confidence score from hOCR element.
     */
    protected function extractConfidence(string $element): float
    {
        if (preg_match_all('/x_wconf (\d+)/', $element, $matches)) {
            if (!empty($matches[1])) {
                return (float) (array_sum($matches[1]) / count($matches[1]));
            }
        }
        return 0.0;
    }

    /**
     * Check if Tesseract is available and configured.
     */
    public function isAvailable(): bool
    {
        try {
            $output = shell_exec($this->tesseractPathQuoted . ' --version 2>&1');
            return stripos($output, 'tesseract') !== false;
        } catch (\Exception $e) {
            Log::warning('Tesseract check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get available languages in Tesseract.
     */
    public function getAvailableLanguages(): array
    {
        try {
            $output = shell_exec($this->tesseractPathQuoted . ' --list-langs 2>&1');
            $lines = explode("\n", $output);
            $languages = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_contains($line, 'List of')) {
                    $languages[] = $line;
                }
            }
            
            return $languages;
        } catch (\Exception $e) {
            return [];
        }
    }
}

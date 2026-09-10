<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service for AI-powered image inpainting using LaMa model.
 * Removes text from images while preserving background naturally.
 */
class InpaintingService
{
    protected string $pythonPath;
    protected string $scriptPath;
    protected string $device;
    protected int $maskDilation;
    protected bool $enabled;

    public function __construct()
    {
        $this->pythonPath = config('image.inpainting.python_path', 'python');
        $this->scriptPath = base_path('scripts/lama_inpaint.py');
        $this->device = config('image.inpainting.device', 'cpu');
        $this->maskDilation = config('image.inpainting.mask_dilation', 5);
        $this->enabled = config('image.inpainting.enabled', true);
    }

    /**
     * Check if inpainting service is available.
     */
    public function isAvailable(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Check if Python script exists
        if (!file_exists($this->scriptPath)) {
            Log::warning('Inpainting script not found', ['path' => $this->scriptPath]);
            return false;
        }

        // Check if Python is available
        $pythonCheck = shell_exec($this->pythonPath . ' --version 2>&1');
        if (stripos($pythonCheck, 'python') === false) {
            Log::warning('Python not found', ['path' => $this->pythonPath]);
            return false;
        }

        return true;
    }

    /**
     * Check if IOPaint/LaMa is installed.
     */
    public function isIOPaintInstalled(): bool
    {
        $result = shell_exec($this->pythonPath . ' -c "import iopaint; print(\'ok\')" 2>&1');
        return trim($result) === 'ok';
    }

    /**
     * Inpaint text regions in an image using AI.
     *
     * @param string $imagePath Full path to the image
     * @param array $textBlocks Array of text blocks with x, y, width, height
     * @return string|null Path to the inpainted image, or null on failure
     */
    public function inpaint(string $imagePath, array $textBlocks): ?string
    {
        if (!$this->isAvailable()) {
            Log::warning('Inpainting service not available');
            return null;
        }

        if (empty($textBlocks)) {
            Log::debug('No text blocks to inpaint');
            return $imagePath; // Return original path
        }

        // Pengaman dimensi (anti-rusak artwork): Jangan inpaint area > 50% lebar atau > 35% tinggi
        $imageSize = @getimagesize($imagePath);
        $imgWidth = $imageSize ? (int) $imageSize[0] : 0;
        $imgHeight = $imageSize ? (int) $imageSize[1] : 0;

        $safeBlocks = [];
        foreach ($textBlocks as $block) {
            $w = (int) ($block['width'] ?? 0);
            $h = (int) ($block['height'] ?? 0);
            if ($w <= 0 || $h <= 0) {
                continue;
            }
            if ($imgWidth > 0 && $w > ($imgWidth * 0.50)) {
                Log::warning('Inpainting: skipping oversized block', ['width' => $w, 'maxWidth' => $imgWidth * 0.50]);
                continue;
            }
            if ($imgHeight > 0 && $h > ($imgHeight * 0.35)) {
                Log::warning('Inpainting: skipping oversized block', ['height' => $h, 'maxHeight' => $imgHeight * 0.35]);
                continue;
            }
            $safeBlocks[] = $block;
        }

        if (empty($safeBlocks)) {
            Log::debug('No safe text blocks to inpaint after size filter');
            return $imagePath;
        }

        $textBlocks = $safeBlocks;

        // Prepare text blocks JSON
        $blocksJson = json_encode(array_map(function ($block) {
            return [
                'x' => (int) ($block['x'] ?? 0),
                'y' => (int) ($block['y'] ?? 0),
                'width' => (int) ($block['width'] ?? 0),
                'height' => (int) ($block['height'] ?? 0),
            ];
        }, $textBlocks));

        // Write JSON to temp file to avoid shell escaping issues on Windows
        $tempJsonFile = tempnam(sys_get_temp_dir(), 'inpaint_blocks_');
        file_put_contents($tempJsonFile, $blocksJson);

        // Build command using temp file for JSON
        $command = sprintf(
            '%s %s %s %s %s %d 2>&1',
            escapeshellarg($this->pythonPath),
            escapeshellarg($this->scriptPath),
            escapeshellarg($imagePath),
            escapeshellarg('@' . $tempJsonFile),  // @ prefix tells script to read from file
            escapeshellarg($this->device),
            $this->maskDilation
        );

        Log::debug('Running inpainting command', [
            'command' => $command,
            'text_blocks_count' => count($textBlocks),
            'json_file' => $tempJsonFile,
            'json_content' => $blocksJson,
        ]);

        // Execute
        $output = shell_exec($command);
        
        // Clean up temp file
        @unlink($tempJsonFile);
        
        if (empty($output)) {
            Log::error('Inpainting command returned empty output');
            return null;
        }

        // Parse JSON result
        $result = json_decode(trim($output), true);

        if (!$result) {
            Log::error('Failed to parse inpainting output', ['output' => $output]);
            return null;
        }

        if (!($result['success'] ?? false)) {
            Log::error('Inpainting failed', [
                'error' => $result['error'] ?? 'Unknown error',
                'install_hint' => $result['install_hint'] ?? null
            ]);
            return null;
        }

        $outputPath = $result['output_path'] ?? null;
        
        if ($outputPath && file_exists($outputPath)) {
            Log::info('Inpainting completed successfully', [
                'input' => $imagePath,
                'output' => $outputPath
            ]);
            return $outputPath;
        }

        Log::error('Inpainting output file not found', ['expected_path' => $outputPath]);
        return null;
    }

    /**
     * Inpaint an image using storage path (relative to storage/app/public).
     *
     * @param string $storagePath Path relative to storage/app/public
     * @param array $textBlocks Array of text blocks
     * @return string|null New storage path to the inpainted image
     */
    public function inpaintFromStorage(string $storagePath, array $textBlocks): ?string
    {
        $fullPath = Storage::disk('public')->path($storagePath);

        if (!file_exists($fullPath)) {
            Log::error('Image not found for inpainting', ['path' => $fullPath]);
            return null;
        }

        $outputPath = $this->inpaint($fullPath, $textBlocks);

        if (!$outputPath) {
            return null;
        }

        // Convert absolute path back to storage relative path
        $publicPath = Storage::disk('public')->path('');
        if (str_starts_with($outputPath, $publicPath)) {
            return substr($outputPath, strlen($publicPath));
        }

        return $outputPath;
    }

    /**
     * Generate an inpainted image path from the original path.
     */
    public function generateInpaintedPath(string $originalPath): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_inpainted.' . ($pathInfo['extension'] ?? 'jpg');
    }

    /**
     * Get installation instructions for IOPaint.
     */
    public function getInstallInstructions(): string
    {
        return <<<INSTRUCTIONS
To enable AI-powered text inpainting, install IOPaint:

1. Install Python 3.8 or higher
2. Run: pip install iopaint
3. Set INPAINTING_ENABLED=true in your .env file

The LaMa model (~200MB) will be downloaded automatically on first use.

For GPU acceleration (much faster):
- Install CUDA and PyTorch with CUDA support
- Set INPAINTING_DEVICE=cuda in your .env file
INSTRUCTIONS;
    }
}

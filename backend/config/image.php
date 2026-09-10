<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Font Path
    |--------------------------------------------------------------------------
    |
    | Path to the font file used for text overlay. 
    | Recommended: Noto Sans CJK for Asian language support.
    |
    */
    'font_path' => env('IMAGE_FONT_PATH', resource_path('fonts/Poppins-Bold.ttf')),

    /*
    |--------------------------------------------------------------------------
    | Available Fonts
    |--------------------------------------------------------------------------
    |
    | List of available fonts for text overlay. Supports bundled Poppins-Bold,
    | Debian/Ubuntu system fonts (DejaVu, Liberation), and local fallbacks.
    |
    */
    'fonts' => [
        'poppins' => [
            'name' => 'Poppins Bold',
            'path' => resource_path('fonts/Poppins-Bold.ttf'),
            'languages' => ['id', 'en', 'es', 'pt'],
            'style' => 'sans-serif',
        ],
        'dejavu' => [
            'name' => 'DejaVu Sans',
            'path' => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            'languages' => ['id', 'en', 'es', 'pt', 'ja', 'ko', 'zh'],
            'style' => 'sans-serif',
        ],
        'liberation' => [
            'name' => 'Liberation Sans',
            'path' => '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            'languages' => ['id', 'en', 'es', 'pt'],
            'style' => 'sans-serif',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Font Key
    |--------------------------------------------------------------------------
    |
    | The key from the fonts array to use as default.
    |
    */
    'default_font' => env('IMAGE_DEFAULT_FONT', 'poppins'),

    /*
    |--------------------------------------------------------------------------
    | Default Font Size
    |--------------------------------------------------------------------------
    |
    | Default font size in pixels for text overlay.
    |
    */
    'default_font_size' => env('IMAGE_FONT_SIZE', 14),

    /*
    |--------------------------------------------------------------------------
    | Font Color
    |--------------------------------------------------------------------------
    |
    | Default font color in hex format for text overlay.
    |
    */
    'font_color' => env('IMAGE_FONT_COLOR', '#000000'),

    /*
    |--------------------------------------------------------------------------
    | Padding
    |--------------------------------------------------------------------------
    |
    | Padding in pixels around text blocks.
    |
    */
    'padding' => env('IMAGE_PADDING', 4),

    /*
    |--------------------------------------------------------------------------
    | Image Quality
    |--------------------------------------------------------------------------
    |
    | Quality setting for saved images (1-100).
    |
    */
    'quality' => env('IMAGE_QUALITY', 90),

    /*
    |--------------------------------------------------------------------------
    | Max Image Size
    |--------------------------------------------------------------------------
    |
    | Maximum dimension (width or height) for processed images.
    | Images larger than this will be resized.
    |
    */
    'max_size' => env('IMAGE_MAX_SIZE', 2000),

    /*
    |--------------------------------------------------------------------------
    | AI Inpainting Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for LaMa-based AI text removal/inpainting.
    | Requires Python 3.8+ and IOPaint: pip install iopaint
    |
    */
    'inpainting' => [
        // Enable/disable AI inpainting (falls back to basic pixel replacement)
        'enabled' => env('INPAINTING_ENABLED', true),
        
        // Path to Python executable
        'python_path' => env('PYTHON_PATH', 'python'),
        
        // Device for inference: 'cpu' or 'cuda' (GPU)
        'device' => env('INPAINTING_DEVICE', 'cpu'),
        
        // Pixels to expand mask around detected text (ensures full coverage)
        'mask_dilation' => env('MASK_DILATION', 5),
    ],
];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tesseract OCR Path
    |--------------------------------------------------------------------------
    |
    | Path to the Tesseract OCR executable. On Windows, this might be
    | something like 'C:\Program Files\Tesseract-OCR\tesseract.exe'
    | On Linux/Mac, it's usually just 'tesseract' if it's in PATH.
    |
    */
    'tesseract_path' => env('TESSERACT_PATH', 'tesseract'),

    /*
    |--------------------------------------------------------------------------
    | Tessdata Prefix Path
    |--------------------------------------------------------------------------
    |
    | Path to the directory containing traineddata files. Set this if your
    | language packs are in a custom location (not the default tessdata folder).
    |
    */
    'tessdata_prefix' => env('TESSDATA_PREFIX', null),

    /*
    |--------------------------------------------------------------------------
    | Default Language
    |--------------------------------------------------------------------------
    |
    | Default language(s) for OCR. Use + to combine multiple languages.
    | Common comic languages: jpn (Japanese), kor (Korean), 
    | chi_sim (Simplified Chinese), chi_tra (Traditional Chinese), eng (English)
    |
    */
    'default_language' => env('TESSERACT_LANG', 'jpn+kor+chi_sim+chi_tra+eng'),

    /*
    |--------------------------------------------------------------------------
    | DPI Setting
    |--------------------------------------------------------------------------
    |
    | DPI (dots per inch) setting for OCR. Higher values may improve
    | accuracy but increase processing time.
    |
    */
    'dpi' => env('TESSERACT_DPI', 300),

    /*
    |--------------------------------------------------------------------------
    | Minimum Confidence
    |--------------------------------------------------------------------------
    |
    | Minimum confidence score (0-100) for accepting OCR results.
    | Lower values include more text but may have more errors.
    |
    */
    'min_confidence' => env('TESSERACT_MIN_CONFIDENCE', 30),

    /*
    |--------------------------------------------------------------------------
    | Hugging Face ZeroGPU OCR Settings (Cloud GPU - Free)
    |--------------------------------------------------------------------------
    */
    'use_huggingface_ocr' => env('USE_HUGGINGFACE_OCR', true),
    'huggingface_ocr_url' => env('HUGGINGFACE_OCR_URL', 'https://rikza2-komiko-translator.hf.space'),
    'huggingface_token' => env('HUGGINGFACE_TOKEN', null),

    /*
    |--------------------------------------------------------------------------
    | PaddleOCR Settings (Primary OCR - Better for CJK)
    |--------------------------------------------------------------------------
    |
    | PaddleOCR provides superior accuracy for Korean, Japanese, and Chinese
    | text detection in manga. Falls back to Tesseract if unavailable.
    |
    */
    'use_paddle_ocr' => env('USE_PADDLE_OCR', false),
    'paddle_ocr_url' => env('PADDLE_OCR_URL', null),
];


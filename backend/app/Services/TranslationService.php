<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    protected ?string $apiKey = '';
    protected ?string $baseUrl = '';
    protected ?string $model = '';
    protected int $timeout = 60;

    public function __construct()
    {
        $this->apiKey = config('kolosal.api_key') ?? '';
        $this->baseUrl = config('kolosal.base_url', 'https://api.kolosal.ai/v1') ?? 'https://api.kolosal.ai/v1';
        $this->model = config('kolosal.model', 'Claude Sonnet 4.5') ?? 'Claude Sonnet 4.5';
        $this->timeout = (int) config('kolosal.timeout', 60);
    }

    /**
     * Translate text to target language with cloud fallbacks.
     * Priority: Groq AI (GPT-120B) -> Google Translate -> MyMemory
     */
    public function translate(string $text, string $targetLanguage = 'id'): ?string
    {
        if (empty($text) || strlen(trim($text)) < 2) {
            return null;
        }

        // Koreksi otomatis salah baca font komik OCR (misalnya HLNTER -> HUNTER, SLNG -> SUNG, rM -> I'M)
        $text = $this->cleanComicOcrText($text);

        // 1. Groq AI (GPT-OSS 120B) - Utama jika API key dikonfigurasi
        if (!empty(config('groq.api_key'))) {
            $result = $this->translateWithGroq($text, $targetLanguage);
            if ($result !== null) {
                return $this->postProcessTerminology($result);
            }
        }

        // 2. Google Translate (Fast & active Chrome extension endpoint)
        $result = $this->translateWithGoogle($text, $targetLanguage);
        if ($result !== null) {
            return $this->postProcessTerminology($result);
        }

        // 3. Fallback to MyMemory
        $result = $this->translateWithMyMemory($text, $targetLanguage);
        if ($result !== null) {
            return $this->postProcessTerminology($result);
        }

        return null;
    }


    /**
     * Translate using Groq API (openai/gpt-oss-120b model).
     */
    protected function translateWithGroq(string $text, string $targetLanguage = 'id'): ?string
    {
        $apiKey = config('groq.api_key');
        $baseUrl = config('groq.base_url', 'https://api.groq.com/openai/v1');
        $model = config('groq.model', 'openai/gpt-oss-120b');
        $timeout = (int) config('groq.timeout', 20);

        if (empty($apiKey)) {
            return null;
        }

        $targetLangName = $this->getLanguageName($targetLanguage);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout($timeout)
            ->post($baseUrl . '/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Kamu adalah penerjemah komik/manhwa Korea-Jepang profesional ke Bahasa Indonesia. "
                            . "Teks diekstrak via OCR komik dan mungkin ada salah baca font (contoh: HLNTER=HUNTER, SLNG=SUNG, rM=I'M, OLT=OUT, dll). "
                            . "Koreksi salah baca OCR secara otomatis sesuai konteks manhwa, lalu terjemahkan ke Bahasa {$targetLangName} yang luwes, santai, dan alami. "
                            . "WAJIB PERTAHANKAN istilah ini dalam bahasa aslinya (JANGAN terjemahkan): "
                            . "Hunter, Dungeon, Guild, Gate, Mana, Awakened, S-Rank, A-Rank, B-Rank, C-Rank, D-Rank, E-Rank, "
                            . "Boss, Raid, Monster, Item, Skill, Quest, Shadow, System, Player, Party, Solo, Level, Stat, "
                            . "HP, MP, STR, AGI, INT, VIT, DEX, LUK, Status, Window, Interface. "
                            . "HANYA kembalikan hasil terjemahan langsung. Tanpa tanda kutip, tanpa penjelasan, tanpa kata pembuka/penutup."
                    ],
                    [
                        'role' => 'user',
                        'content' => $text
                    ]
                ],
                'temperature' => 0.3,
                'max_completion_tokens' => 2000, // Reasoning model butuh lebih banyak token (thinking + output)
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $result = trim($data['choices'][0]['message']['content'] ?? '');
                $result = trim($result, "\"'` \t\n\r\0\x0B");

                if (!empty($result) && $this->isValidTranslation($result, $text)) {
                    Log::info('Groq translation success', [
                        'model' => $model,
                        'original' => mb_substr($text, 0, 30),
                        'translated' => mb_substr($result, 0, 30),
                    ]);
                    return $result;
                }
            } else {
                $err = $response->json()['error']['message'] ?? $response->body();
                Log::warning('Groq translation failed, falling back', [
                    'status' => $response->status(),
                    'error' => $err,
                    'model' => $model,
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Groq translation exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    
    /**
     * Translate using Kolosal AI with retry logic.
     */
    protected function translateWithKolosal(string $text, string $targetLanguage, int $maxRetries = 3): ?string
    {
        $languageName = $this->getLanguageName($targetLanguage);
        $lastError = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Increase delay between retries (1s, 2s, 4s)
                if ($attempt > 1) {
                    $delay = pow(2, $attempt - 1);
                    Log::debug("Kolosal retry attempt {$attempt}, waiting {$delay}s");
                    sleep($delay);
                }
                
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->baseUrl . '/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "Terjemahkan teks komik ke Bahasa Indonesia. HANYA balas dengan hasil terjemahan saja, tanpa penjelasan apapun. Jika tidak bisa diterjemahkan, balas dengan teks kosong."
                        ],
                        [
                            'role' => 'user',
                            'content' => "Terjemahkan: " . $text
                        ]
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 200,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $result = $data['choices'][0]['message']['content'] ?? null;
                    
                    // Validate result - reject if it looks like AI explanation
                    if ($result && $this->isValidTranslation($result, $text)) {
                        return trim($result);
                    }
                    
                    Log::warning('Translation rejected as invalid', [
                        'original' => $text,
                        'result' => $result,
                    ]);
                    return null;
                }

                $lastError = "HTTP " . $response->status();
                Log::warning("Kolosal attempt {$attempt} failed", ['status' => $response->status()]);
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::warning("Kolosal attempt {$attempt} exception", ['error' => substr($e->getMessage(), 0, 100)]);
            }
        }
        
        Log::error('Kolosal AI failed after all retries', [
            'text' => substr($text, 0, 50),
            'last_error' => $lastError,
        ]);
        
        return null;
    }
    
    /**
     * Primary translation using Google Translate extension API (fast, reliable, no 429).
     */
    protected function translateWithGoogle(string $text, string $targetLanguage = 'id'): ?string
    {
        try {
            $url = 'https://clients5.google.com/translate_a/t';
            
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
                ])
                ->get($url, [
                    'client' => 'dict-chrome-ex',
                    'sl' => 'auto',
                    'tl' => $targetLanguage,
                    'q' => $text,
                ]);
            
            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data) && isset($data[0][0])) {
                    $translated = trim($data[0][0]);
                    if (!empty($translated)) {
                        return $translated;
                    }
                }
            }
            
            return null;
        } catch (\Exception $e) {
            Log::warning('Google Translate exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Fallback translation using MyMemory API.
     */
    protected function translateWithMyMemory(string $text, string $targetLanguage = 'id'): ?string
    {
        try {
            $url = 'https://api.mymemory.translated.net/get';
            $response = Http::timeout(10)->get($url, [
                'q' => $text,
                'langpair' => "auto|{$targetLanguage}",
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $translated = $data['responseData']['translatedText'] ?? null;
                if (!empty($translated) && !str_contains($translated, 'MYMEMORY WARNING')) {
                    return trim($translated);
                }
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Validate that translation result is not AI explanation/prompt text.
     */
    protected function isValidTranslation(string $result, string $original): bool
    {
        $result = trim($result);
        
        // Reject if empty or too short
        if (empty($result) || strlen($result) < 1) {
            return false;
        }
        
        // Reject if result is too long compared to original (likely explanation)
        if (strlen($result) > strlen($original) * 5 && strlen($result) > 100) {
            return false;
        }
        
        // Reject common AI explanation patterns
        $invalidPatterns = [
            'saya siap',
            'saya akan',
            'saya membantu',
            'menerjemahkan teks',
            'manga/manhwa',
            'onomatopoeia',
            'efek suara',
            'tidak ada teks',
            'tidak dapat diterjemahkan',
            'maaf',
            'berikut',
            'terjemahan:',
            'artinya:',
            'if you',
            'please let me',
            'i can help',
            'translation:',
        ];
        
        $lowerResult = mb_strtolower($result);
        foreach ($invalidPatterns as $pattern) {
            if (str_contains($lowerResult, $pattern)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Translate multiple text blocks in batch.
     */
    public function translateBatch(array $texts, string $targetLanguage = 'id'): array
    {
        $results = [];
        
        foreach ($texts as $key => $text) {
            // Rate limit: 1 request per second
            if ($key > 0) {
                sleep(1);
            }
            
            $translated = $this->translate($text, $targetLanguage);
            $results[$key] = $translated ?? $text; // Fallback to original if translation fails
        }

        return $results;
    }

    /**
     * Koreksi otomatis salah baca font komik yang sering terjadi pada OCR
     */
    public function cleanComicOcrText(string $text): string
    {
        $fixes = [
            '/\bHLNTER\b/i' => 'HUNTER',
            '/\bHLNTERS\b/i' => 'HUNTERS',
            '/\bSLNG\b/i' => 'SUNG',
            '/\bDLNGEON\b/i' => 'DUNGEON',
            '/\bDLNGEONS\b/i' => 'DUNGEONS',
            '/\bGLILD\b/i' => 'GUILD',
            '/\bGLILDS\b/i' => 'GUILDS',
            '/\bJLST\b/i' => 'JUST',
            '/\bMLST\b/i' => 'MUST',
            '/\bBLT\b/i' => 'BUT',
            '/\bOLT\b/i' => 'OUT',
            '/\bYOL\b/i' => 'YOU',
            '/\bYOLR\b/i' => 'YOUR',
            '/\bTRLE\b/i' => 'TRUE',
            '/\bFLLL\b/i' => 'FULL',
            '/\bRLN\b/i' => 'RUN',
            '/\bGLN\b/i' => 'GUN',
            '/\bABOLT\b/i' => 'ABOUT',
            '/\bBECALNWSE\b/i' => 'BECAUSE',
            '/\bBECALSE\b/i' => 'BECAUSE',
            '/\brM\b/' => "I'M",
            '/\bTM\b/' => "I'M",
            '/\blM\b/' => "I'M",
        ];

        $text = preg_replace(array_keys($fixes), array_values($fixes), $text);

        // Pola umum kata bahasa Inggris dengan konsonan + LN + konsonan (misal HLNT -> HUNT)
        $text = preg_replace('/\b([BCDFGHJKLMNPQRSTVWXYZ])LN([BCDFGHJKLMNPQRSTVWXYZ]+)\b/i', '${1}UN${2}', $text);

        return $text;
    }

    /**
     * Koreksi hasil terjemahan yang salah menerjemahkan istilah komik.
     * Google/MyMemory sering terjemahkan "Hunter" -> "Pemburu", "Dungeon" -> "Penjara", dll.
     * Method ini mengembalikan istilah ke bahasa aslinya setelah terjemahan.
     */
    public function postProcessTerminology(string $result): string
    {
        // Pasangan: [terjemahan salah] => [istilah yang benar]
        // Case-insensitive matching, tapi output sesuai dengan case aslinya
        $corrections = [
            // Hunter - paling sering salah diterjemah
            '/\bpemburu\b/i'                     => 'Hunter',
            '/\bpara pemburu\b/i'                => 'para Hunter',
            // Dungeon - sering jadi "penjara", "gua", "sarang"
            '/\bpenjara bawah tanah\b/i'         => 'Dungeon',
            '/\bgua bawah tanah\b/i'             => 'Dungeon',
            '/\bruang bawah tanah\b/i'           => 'Dungeon',
            '/\bsarang monster\b/i'              => 'Dungeon',
            '/\bpenjara\b/i'                     => 'Dungeon', // Dalam konteks komik ini selalu Dungeon
            // Gate - khusus di konteks "Gate" komik, bukan pintu biasa
            '/\bcelah dimensi\b/i'               => 'Gate',
            '/\bportal dimensi\b/i'              => 'Gate',
            '/\bportal ajaib\b/i'                => 'Gate',
            // Guild - sering jadi "serikat" atau "perkumpulan"
            '/\bserikat hunter\b/i'              => 'Hunter Guild',
            '/\basosiasi hunter\b/i'             => 'Hunter Association',
            // Rank - Google sering ubah "E-Rank" ke "Peringkat E" dll
            '/\bperingkat[- ]?s\b/i'             => 'S-Rank',
            '/\bperingkat[- ]?a\b/i'             => 'A-Rank',
            '/\bperingkat[- ]?b\b/i'             => 'B-Rank',
            '/\bperingkat[- ]?c\b/i'             => 'C-Rank',
            '/\bperingkat[- ]?d\b/i'             => 'D-Rank',
            '/\bperingkat[- ]?e\b/i'             => 'E-Rank',
            '/\btingkatan[- ]?e\b/i'             => 'E-Rank',
            '/\btingkatan[- ]?s\b/i'             => 'S-Rank',
            '/\btier terendah\b/i'               => 'E-Rank',
            '/\btingkat terendah\b/i'            => 'E-Rank',
            // Mana - jarang salah tapi kadang jadi "energi magis"
            '/\benergi magis\b/i'                => 'Mana',
            '/\btenaga sihir\b/i'                => 'Mana',
            '/\bkekuatan magis\b/i'              => 'Mana',
            // Awakened
            '/\bterbangunkan\b/i'                => 'Awakened',
        ];

        foreach ($corrections as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $result);
        }

        return $result;
    }


    /**
     * Get full language name from code.
     */
    protected function getLanguageName(string $code): string
    {
        $languages = config('kolosal.supported_languages', [
            'id' => 'Indonesian',
        ]);

        return $languages[$code] ?? 'Indonesian';
    }

    /**
     * Check if the API is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->baseUrl);
    }
}

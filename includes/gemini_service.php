<?php
/**
 * CUBOIDPILOT / CAI — GOOGLE GEMINI 3.8 FLASH AI SERVICE
 * Centralized, multi-tenant resilient AI reasoning engine.
 * Handles grounded conversational responses, structured intent classification,
 * product recommendations, qualification, and workflow branch routing.
 */

require_once __DIR__ . '/../config/db.php';

class GeminiService {
    private static ?string $apiKey = null;
    private static string $model = 'gemini-flash-lite-latest';
    private static string $apiEndpoint = 'https://generativelanguage.googleapis.com/v1beta/models';

    public static function getApiKey(): string {
        if (self::$apiKey !== null) {
            return self::$apiKey;
        }
        if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            self::$apiKey = GEMINI_API_KEY;
        } else {
            self::$apiKey = getenv('GEMINI_API_KEY') ?: '';
        }
        return self::$apiKey;
    }

    public static function getModel(): string {
        if (defined('GEMINI_MODEL') && !empty(GEMINI_MODEL)) {
            return GEMINI_MODEL;
        }
        return getenv('GEMINI_MODEL') ?: self::$model;
    }

    private static ?string $groqApiKey = null;
    private static array $groqModels = ['openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'qwen/qwen3.8-27b'];

    public static function getGroqApiKey(): string {
        if (self::$groqApiKey !== null) {
            return self::$groqApiKey;
        }
        if (defined('GROQ_API_KEY') && !empty(GROQ_API_KEY) && GROQ_API_KEY !== 'YOUR_GROQ_API_KEY_HERE') {
            self::$groqApiKey = GROQ_API_KEY;
        } else {
            self::$groqApiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? ($_SERVER['GROQ_API_KEY'] ?? ''));
        }
        return self::$groqApiKey;
    }

    /**
     * Executes an ultra-low latency completion via Groq Cloud API (<500ms).
     */
    public static function callGroq(array $messages, array $options = []): ?string {
        $key = self::getGroqApiKey();
        if (empty($key)) {
            return null;
        }

        $models = !empty($options['model']) ? [$options['model']] : self::$groqModels;
        $maxTokens = $options['max_tokens'] ?? 650;
        $temperature = $options['temperature'] ?? 0.25;
        $timeout = $options['timeout'] ?? 5;

        foreach ($models as $idx => $candModel) {
            if ($idx > 0) {
                usleep(150000); // 150ms backoff
            }

            $payload = [
                'model' => $candModel,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature
            ];

            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);

            $rawResp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && !empty($rawResp)) {
                $decoded = json_decode($rawResp, true);
                if (!empty($decoded['choices'][0]['message']['content'])) {
                    $text = trim($decoded['choices'][0]['message']['content']);
                    $text = preg_replace('/<think>.*?<\/think>/is', '', $text);
                    return trim($text);
                }
            } else {
                error_log("[GeminiService::callGroq Model: {$candModel}] HTTP: {$httpCode} | Error: {$curlErr} | Resp: " . substr((string)$rawResp, 0, 150));
            }
        }

        return null;
    }

    /**
     * Extracts action chips markup from text response.
     * Looks for ---ACTION_CHIPS--- followed by JSON array of chips.
     */
    public static function extractActionChips(string $text): array {
        $cleanText = $text;
        $chips = [];

        if (strpos($text, '---ACTION_CHIPS---') !== false) {
            $parts = explode('---ACTION_CHIPS---', $text, 2);
            $cleanText = trim($parts[0]);
            $chipsRaw = trim($parts[1]);

            // If there is metadata after chips
            if (strpos($chipsRaw, '---INTERNAL_METADATA---') !== false) {
                $subParts = explode('---INTERNAL_METADATA---', $chipsRaw, 2);
                $chipsRaw = trim($subParts[0]);
            }

            if (preg_match('/\[[\s\S]*?\]/', $chipsRaw, $m)) {
                $decoded = json_decode($m[0], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $c) {
                        if (!empty($c['label'])) {
                            $chips[] = [
                                'label' => trim($c['label']),
                                'text'  => !empty($c['text']) ? trim($c['text']) : trim($c['label'])
                            ];
                        }
                    }
                }
            }
        }

        return [
            'text'  => $cleanText,
            'chips' => $chips
        ];
    }

    /**
     * Executes a generateContent HTTP request to Gemini Generative Language API.
     */
    public static function callGemini(array $payload, ?string $modelOverride = null, int $timeoutSeconds = 6): ?array {
        $key = self::getApiKey();
        if (empty($key)) {
            error_log("[GeminiService] Error: GEMINI_API_KEY is not defined.");
            return null;
        }

        $model = $modelOverride ?: self::getModel();
        $candidateModels = array_unique([$model, 'gemini-flash-lite-latest', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-3.5-flash']);

        foreach ($candidateModels as $candidate) {
            $url = self::$apiEndpoint . '/' . urlencode($candidate) . ':generateContent?key=' . urlencode($key);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=UTF-8'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);

            $rawResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && !empty($rawResponse)) {
                $decoded = json_decode($rawResponse, true);
                if (is_array($decoded) && !empty($decoded['candidates'])) {
                    return $decoded;
                }
            }

            error_log("[GeminiService Model: {$candidate}] HTTP: {$httpCode} | Error: {$curlError} | Resp: " . substr((string)$rawResponse, 0, 200));
        }

        return null;
    }

    /**
     * Extracts text response and strips any internal reasoning artifacts.
     */
    public static function extractText(?array $geminiResult): string {
        if (!$geminiResult || empty($geminiResult['candidates'][0]['content']['parts'])) {
            return '';
        }

        $parts = $geminiResult['candidates'][0]['content']['parts'];
        $text = '';
        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $text .= $p['text'];
            }
        }

        // Clean internal reasoning or <think> tags if model emits them
        $text = preg_replace('/<think>.*?<\/think>/is', '', $text);
        $text = preg_replace('/```json\s*(\{.*?\})\s*```/is', '$1', $text);
        return trim($text);
    }

    /**
     * Core conversational completion with system instruction and history.
     * Uses Groq sub-second inference first, falling back to Gemini.
     */
    public static function generateResponse(string $systemPrompt, array $messages, array $options = []): string {
        // 1. Try Groq ultra-fast sub-second completion
        $groqMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages
        );
        $groqResp = self::callGroq($groqMessages, $options);
        if (!empty($groqResp)) {
            return $groqResp;
        }

        // 2. Fallback to Gemini Generative Language
        $contents = [];
        foreach ($messages as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => (string)($msg['content'] ?? '')]]
            ];
        }

        $payload = [
            'contents' => $contents,
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]]
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.35,
                'maxOutputTokens' => $options['max_tokens'] ?? 800,
                'topP' => 0.95
            ]
        ];

        $res = self::callGemini($payload, $options['model'] ?? null, $options['timeout'] ?? 6);
        return self::extractText($res);
    }

    /**
     * Classifies user intent for workflow routing, returning structured JSON.
     */
    public static function classifyIntent(string $text, array $intents, ?string $context = null): array {
        $intentListStr = json_encode($intents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $sysPrompt = "You are an accurate intent classification engine for an AI business assistant.
Your task is to classify the user's message into EXACTLY ONE of the provided allowed intent keys, or 'fallback' if none fit.
Allowed intents:
{$intentListStr}

Context:
" . ($context ?: "General conversation with prospect") . "

Respond ONLY with valid JSON in this exact structure:
{
  \"intent\": \"<one of the allowed intent keys or fallback>\",
  \"confidence\": 0.95,
  \"explanation\": \"brief 1-sentence reason\"
}";

        $groqRaw = self::callGroq([
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user', 'content' => "Classify this customer message:\n\"{$text}\""]
        ], ['temperature' => 0.1, 'max_tokens' => 200]);

        if (!empty($groqRaw) && preg_match('/\{[\s\S]*\}/', $groqRaw, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && !empty($parsed['intent'])) {
                return $parsed;
            }
        }

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "Classify this customer message:\n\"{$text}\""]]]
            ],
            'systemInstruction' => [
                'parts' => [['text' => $sysPrompt]]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 300
            ]
        ];

        $res = self::callGemini($payload);
        $raw = self::extractText($res);
        $parsed = null;
        if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $parsed = json_decode($m[0], true);
        }

        if (is_array($parsed) && !empty($parsed['intent'])) {
            return $parsed;
        }

        return [
            'intent' => 'fallback',
            'confidence' => 0.5,
            'explanation' => 'Defaulted to fallback intent.'
        ];
    }

    /**
     * Recommends matching products/courses based on user requirements.
     */
    public static function recommendProducts(array $products, string $query, array $context = []): array {
        $catalogSummary = array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'category' => $p['category'] ?? '',
                'price_inr' => (int)($p['price_inr'] ?? 0),
                'emi_available' => !empty($p['emi_available']),
                'features' => !empty($p['features']) ? (is_array($p['features']) ? $p['features'] : json_decode($p['features'], true)) : []
            ];
        }, $products);

        $catalogJson = json_encode($catalogSummary, JSON_UNESCAPED_UNICODE);
        $ctxStr = json_encode($context, JSON_UNESCAPED_UNICODE);

        $sysPrompt = "You are an enterprise AI recommendation specialist.
Analyze the customer's request and match the most relevant items from the company's verified product catalog.
Catalog:
{$catalogJson}

Customer Context:
{$ctxStr}

Respond ONLY in valid JSON format:
{
  \"recommended_product_ids\": [1, 2],
  \"match_reason\": \"conversational 1-2 sentence explanation of why these were chosen\",
  \"best_fit_id\": 1
}";

        $groqRaw = self::callGroq([
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user', 'content' => "Find matching items for: \"{$query}\""]
        ], ['temperature' => 0.2, 'max_tokens' => 300]);

        if (!empty($groqRaw) && preg_match('/\{[\s\S]*\}/', $groqRaw, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && !empty($parsed['recommended_product_ids'])) {
                return $parsed;
            }
        }

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "Find matching items for: \"{$query}\""]]]
            ],
            'systemInstruction' => [
                'parts' => [['text' => $sysPrompt]]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 350
            ]
        ];

        $res = self::callGemini($payload);
        $raw = self::extractText($res);
        $parsed = null;
        if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $parsed = json_decode($m[0], true);
        }

        if (is_array($parsed) && !empty($parsed['recommended_product_ids'])) {
            return $parsed;
        }

        // Fallback: return top 2 products
        $fallbackIds = array_slice(array_column($catalogSummary, 'id'), 0, 2);
        return [
            'recommended_product_ids' => $fallbackIds,
            'match_reason' => 'Here are our popular programs matching your interests.',
            'best_fit_id' => $fallbackIds[0] ?? null
        ];
    }

    /**
     * Evaluates a multi-branch decision node using validated JSON output.
     */
    public static function evaluateDecision(string $criteria, array $branches, string $customerMessage, array $variables = []): array {
        $branchesJson = json_encode($branches, JSON_UNESCAPED_UNICODE);
        $varsJson = json_encode($variables, JSON_UNESCAPED_UNICODE);

        $sysPrompt = "You are a deterministic AI workflow decision router.
Evaluate the customer's message and current session variables according to the decision criteria.
Select the single best output branch.
Decision Criteria:
{$criteria}

Allowed Branches (ID and description):
{$branchesJson}

Current Session Variables:
{$varsJson}

Respond ONLY in valid JSON:
{
  \"selected_branch_id\": \"<exact ID of the selected branch>\",
  \"confidence\": 0.95,
  \"rationale\": \"brief 1-sentence rationale\"
}";

        $groqRaw = self::callGroq([
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user', 'content' => "Evaluate this input:\n\"{$customerMessage}\""]
        ], ['temperature' => 0.1, 'max_tokens' => 200]);

        if (!empty($groqRaw) && preg_match('/\{[\s\S]*\}/', $groqRaw, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && !empty($parsed['selected_branch_id'])) {
                return $parsed;
            }
        }

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "Evaluate this input:\n\"{$customerMessage}\""]]]
            ],
            'systemInstruction' => [
                'parts' => [['text' => $sysPrompt]]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 250
            ]
        ];

        $res = self::callGemini($payload);
        $raw = self::extractText($res);
        $parsed = null;
        if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $parsed = json_decode($m[0], true);
        }

        if (is_array($parsed) && !empty($parsed['selected_branch_id'])) {
            return $parsed;
        }

        $fallbackId = !empty($branches) ? ($branches[count($branches) - 1]['id'] ?? 'default') : 'default';
        return [
            'selected_branch_id' => $fallbackId,
            'confidence' => 0.5,
            'rationale' => 'Default branch chosen.'
        ];
    }

    /**
     * Extracts lead qualification data (name, phone, email, budget, interest).
     */
    public static function extractLeadQualification(string $message, array $currentVars = []): array {
        $currJson = json_encode($currentVars, JSON_UNESCAPED_UNICODE);
        $sysPrompt = "You are an intelligent lead qualification extractor.
Extract any prospect details present in the message.
Current Known Variables:
{$currJson}

Respond ONLY in valid JSON:
{
  \"name\": null,
  \"phone\": null,
  \"email\": null,
  \"interest\": null,
  \"budget\": null,
  \"is_qualified\": true
}";

        $groqRaw = self::callGroq([
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user', 'content' => "Extract info from: \"{$message}\""]
        ], ['temperature' => 0.1, 'max_tokens' => 200]);

        if (!empty($groqRaw) && preg_match('/\{[\s\S]*\}/', $groqRaw, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "Extract info from: \"{$message}\""]]]
            ],
            'systemInstruction' => [
                'parts' => [['text' => $sysPrompt]]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 250
            ]
        ];

        $res = self::callGemini($payload);
        $raw = self::extractText($res);
        $parsed = null;
        if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $parsed = json_decode($m[0], true);
        }
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Generates an executive summary of a conversation for CRM.
     */
    public static function summarizeConversation(array $messages): string {
        $transcript = '';
        foreach ($messages as $m) {
            $sender = ($m['sender_type'] ?? $m['role'] ?? 'user') === 'ai' ? 'Cai' : 'Customer';
            $text = $m['message_text'] ?? $m['content'] ?? '';
            $transcript .= "{$sender}: {$text}\n";
        }

        $sysPrompt = "You are an executive CRM AI summarizer.
Summarize the customer conversation into a concise 2-3 bullet point briefing highlighting:
1. Customer interest & requirements
2. Current status & commercial intent
3. Recommended next action for the sales/support team.";

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "Conversation transcript:\n{$transcript}"]]]
            ],
            'systemInstruction' => [
                'parts' => [['text' => $sysPrompt]]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 300
            ]
        ];

        $res = self::callGemini($payload);
        return self::extractText($res);
    }
}

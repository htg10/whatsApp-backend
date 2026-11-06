<?php

namespace App\Modules\Chatbot\Services;

use App\Models\AiSetting;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates an AI reply for a chatbot using the business's own instructions as
 * context. Supports three providers over plain HTTP: Google Gemini, OpenAI and
 * Anthropic Claude. The preferred provider, whether AI is enabled at all, and
 * the API keys all come from the Super Admin's AI settings (ai_settings table),
 * falling back to .env. Returns null when AI is unavailable/misconfigured so the
 * caller can fall back gracefully.
 */
class AiReplyService
{
    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const PROVIDERS = ['gemini', 'openai', 'anthropic'];
    // Fewer history turns = smaller prompt = faster first token (lower reply latency).
    private const HISTORY_LIMIT = 6;

    private ?AiSetting $settings = null;
    private bool $settingsLoaded = false;

    public function generate(Chatbot $chatbot, Conversation $conversation, string $incoming): ?string
    {
        $chain = $this->providerChain();
        if ($chain === []) {
            Log::warning('AI reply skipped: no API key configured (set GEMINI_API_KEY or ANTHROPIC_API_KEY in .env, then php artisan config:clear)');
            return null; // no AI key configured — skip AI, let caller use fallback
        }

        $system = $this->systemPrompt($chatbot);
        $messages = $this->history($conversation, $incoming);

        // Try each configured provider in order; if one fails (e.g. an invalid or
        // expired key), fall through to the next instead of giving up silently.
        foreach ($chain as $provider) {
            try {
                $reply = $this->callText($provider, $system, $messages);
                if ($reply) {
                    Log::info('AI reply: answered via ' . $provider);
                    return $reply;
                }
                Log::warning("AI reply: {$provider} returned nothing, trying next provider if any");
            } catch (\Throwable $e) {
                Log::warning("AI reply via {$provider} failed: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * One-shot completion with a custom system prompt (used for classification,
     * e.g. hot-lead detection). Returns null when no AI key is set or the call fails.
     *
     * @param array<int, array{role:string, content:string}> $messages
     */
    public function classify(string $system, array $messages): ?string
    {
        foreach ($this->providerChain() as $provider) {
            try {
                $reply = $this->callText($provider, $system, $messages);
                if ($reply) {
                    return $reply;
                }
            } catch (\Throwable $e) {
                Log::warning("AI classify via {$provider} failed: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Look at an image or PDF the customer sent and answer about it, using the
     * business instructions as context. Returns null when AI is unavailable, the
     * media type is unsupported, or the call fails.
     */
    public function describeMedia(Chatbot $chatbot, ?string $caption, string $bytes, string $mime): ?string
    {
        $chain = $this->providerChain();
        if ($chain === []) {
            return null;
        }

        // Only images and PDFs can be understood visually.
        if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            return null;
        }

        $system = $this->systemPrompt($chatbot) . "\n\n"
            . "The customer has sent a file (an image or a PDF). Look at it carefully and respond helpfully, "
            . "in line with the business information above. If it is a bill, invoice, receipt, screenshot or photo, "
            . "you may read the visible details and answer about them. If you cannot read it clearly, say so politely "
            . "and ask the customer to resend or clarify. Never invent details that are not visible in the file.";

        $question = trim((string) $caption) !== ''
            ? trim((string) $caption)
            : 'Please look at the attached file and tell me what it is about, and help me with it.';

        foreach ($chain as $provider) {
            try {
                $reply = $this->callVision($provider, $system, $question, $bytes, $mime);
                if ($reply) {
                    return $reply;
                }
            } catch (\Throwable $e) {
                Log::warning("AI media via {$provider} failed: " . $e->getMessage());
            }
        }

        return null;
    }

    /** Dispatch a text completion to the chosen provider. */
    private function callText(string $provider, string $system, array $messages): ?string
    {
        return match ($provider) {
            'gemini' => $this->callGemini($system, $messages),
            'openai' => $this->callOpenAi($system, $messages),
            'anthropic' => $this->callAnthropic($system, $messages),
            default => null,
        };
    }

    /** Dispatch an image/PDF understanding call to the chosen provider. */
    private function callVision(string $provider, string $system, string $question, string $bytes, string $mime): ?string
    {
        return match ($provider) {
            'gemini' => $this->callGeminiVision($system, $question, $bytes, $mime),
            'openai' => $this->callOpenAiVision($system, $question, $bytes, $mime),
            'anthropic' => $this->callAnthropicVision($system, $question, $bytes, $mime),
            default => null,
        };
    }

    /**
     * Ordered list of providers to try. The preferred one (AI_PROVIDER) comes
     * first; the other follows as a fallback. Only providers whose key is set are
     * included — so a single configured key still works, and when both are set a
     * failure on one rolls over to the other.
     *
     * @return array<int, string>
     */
    private function providerChain(): array
    {
        // AI can be switched off entirely from the admin settings.
        $settings = $this->settings();
        if ($settings && ! $settings->enabled) {
            return [];
        }

        $available = [];
        foreach (self::PROVIDERS as $p) {
            if (($this->keyFor($p) ?? '') !== '') {
                $available[$p] = true;
            }
        }
        if ($available === []) {
            return [];
        }

        // Preferred provider (admin setting, else .env) first; the rest follow as
        // fallbacks so one key still works and a failure rolls over to another.
        $pref = $settings?->provider ?: (config('services.ai.provider') ?: 'gemini');
        $order = array_merge([$pref], array_values(array_diff(self::PROVIDERS, [$pref])));

        return array_values(array_filter($order, fn ($p) => isset($available[$p])));
    }

    /** Which providers currently have a key (for diagnostics). */
    public function configuredProviders(): array
    {
        return $this->providerChain();
    }

    /** The singleton AI settings row, or null if unavailable (e.g. pre-migration). */
    private function settings(): ?AiSetting
    {
        if (! $this->settingsLoaded) {
            $this->settingsLoaded = true;
            try {
                $this->settings = AiSetting::current();
            } catch (\Throwable) {
                $this->settings = null;
            }
        }

        return $this->settings;
    }

    /** API key for a provider: admin setting first, then .env. */
    private function keyFor(string $provider): ?string
    {
        $key = $this->settings()?->keyFor($provider);
        if (($key ?? '') !== '') {
            return $key;
        }

        return match ($provider) {
            'gemini' => config('services.gemini.key'),
            'openai' => config('services.openai.key'),
            'anthropic' => config('services.anthropic.key'),
            default => null,
        };
    }

    /** Model for a provider: admin setting first, then .env, then a sane default. */
    private function modelFor(string $provider): string
    {
        $model = $this->settings()?->modelFor($provider);
        if (($model ?? '') !== '') {
            return $model;
        }

        return match ($provider) {
            'gemini' => config('services.gemini.model', 'gemini-2.5-flash'),
            'openai' => config('services.openai.model', 'gpt-4o-mini'),
            'anthropic' => config('services.anthropic.model', 'claude-opus-5'),
            default => '',
        };
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    private function callGemini(string $system, array $messages): ?string
    {
        $model = $this->modelFor('gemini');
        $contents = array_map(fn ($m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $apiKey = $this->keyFor('gemini');
        $url = self::GEMINI_ENDPOINT . $model . ':generateContent?key=' . urlencode($apiKey);

        $res = Http::timeout(30)
            ->post($url, [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                // WhatsApp replies are short; a lower cap keeps generation quick.
                'generationConfig' => ['maxOutputTokens' => 400, 'temperature' => 0.7],
            ]);

        if ($res->failed()) {
            Log::warning('Gemini reply API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('candidates.0.content.parts.0.text');

        return $text ? trim($text) : null;
    }

    private function callGeminiVision(string $system, string $question, string $bytes, string $mime): ?string
    {
        $model = $this->modelFor('gemini');
        $apiKey = $this->keyFor('gemini');
        $url = self::GEMINI_ENDPOINT . $model . ':generateContent?key=' . urlencode($apiKey);

        $res = Http::timeout(45)->post($url, [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $question],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                ],
            ]],
            'generationConfig' => ['maxOutputTokens' => 700, 'temperature' => 0.5],
        ]);

        if ($res->failed()) {
            Log::warning('Gemini vision API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('candidates.0.content.parts.0.text');

        return $text ? trim($text) : null;
    }

    private function callAnthropicVision(string $system, string $question, string $bytes, string $mime): ?string
    {
        $media = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];

        $res = Http::withHeaders([
            'x-api-key' => $this->keyFor('anthropic'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(45)->post(self::ANTHROPIC_ENDPOINT, [
            'model' => $this->modelFor('anthropic'),
            'max_tokens' => 700,
            'system' => $system,
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $question], $media],
            ]],
        ]);

        if ($res->failed()) {
            Log::warning('Anthropic vision API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        foreach ($res->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text' && ! empty(trim($block['text'] ?? ''))) {
                return trim($block['text']);
            }
        }

        return null;
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    private function callAnthropic(string $system, array $messages): ?string
    {
        $res = Http::withHeaders([
            'x-api-key' => $this->keyFor('anthropic'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(30)->post(self::ANTHROPIC_ENDPOINT, [
            'model' => $this->modelFor('anthropic'),
            'max_tokens' => 600,
            'system' => $system,
            'output_config' => ['effort' => 'low'], // quick, chat-style replies
            'messages' => $messages,
        ]);

        if ($res->failed()) {
            Log::warning('AI reply API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        // Return the first text block (thinking blocks, if any, are skipped).
        foreach ($res->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'text' && ! empty(trim($block['text'] ?? ''))) {
                return trim($block['text']);
            }
        }

        return null;
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    private function callOpenAi(string $system, array $messages): ?string
    {
        $res = Http::withToken((string) $this->keyFor('openai'))
            ->timeout(30)->post(self::OPENAI_ENDPOINT, [
                'model' => $this->modelFor('openai'),
                'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
                'max_tokens' => 600,
                'temperature' => 0.7,
            ]);

        if ($res->failed()) {
            Log::warning('OpenAI reply API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('choices.0.message.content');

        return $text ? trim($text) : null;
    }

    private function callOpenAiVision(string $system, string $question, string $bytes, string $mime): ?string
    {
        // OpenAI chat vision understands images; PDFs are not accepted here.
        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);

        $res = Http::withToken((string) $this->keyFor('openai'))
            ->timeout(45)->post(self::OPENAI_ENDPOINT, [
                'model' => $this->modelFor('openai'),
                'max_tokens' => 700,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => $question],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ]],
                ],
            ]);

        if ($res->failed()) {
            Log::warning('OpenAI vision API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('choices.0.message.content');

        return $text ? trim($text) : null;
    }

    private function systemPrompt(Chatbot $chatbot): string
    {
        $business = trim((string) $chatbot->ai_instructions) ?: 'A business that uses WhatsApp to talk to its customers.';

        return implode("\n", [
            'You are the WhatsApp assistant for a business. Reply to the customer helpfully and concisely, like a friendly human agent on WhatsApp.',
            '',
            'BUSINESS INFORMATION & INSTRUCTIONS (your only source of truth):',
            $business,
            '',
            'RULES:',
            '- Answer only from the business information above. If the answer is not there or is out of scope, politely say you will connect them with a team member — do not make things up.',
            '- Never invent prices, offers, timings, or policies that are not stated above.',
            '- Reply in the same language the customer writes in (match Hindi / Hinglish / English).',
            '- Follow the tone, length, format and example replies given in the business instructions above.',
            '- Write WhatsApp-friendly plain text — emojis are fine; avoid markdown headings, tables or code blocks.',
        ]);
    }

    /**
     * Build a normalized messages array: recent turns (inbound=user,
     * outbound=assistant), consecutive same-role turns merged, starting with a
     * user turn, and always ending with the current incoming message.
     *
     * @return array<int, array{role:string, content:string}>
     */
    private function history(Conversation $conversation, string $incoming): array
    {
        $rows = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->whereIn('type', ['text'])
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $turns = [];
        foreach ($rows as $m) {
            $role = $m->direction === Message::DIRECTION_OUTBOUND ? 'assistant' : 'user';
            $text = trim((string) $m->body);
            if ($text === '') {
                continue;
            }
            if (! empty($turns) && $turns[count($turns) - 1]['role'] === $role) {
                $turns[count($turns) - 1]['content'] .= "\n" . $text;
            } else {
                $turns[] = ['role' => $role, 'content' => $text];
            }
        }

        // Ensure the very last turn is the current incoming customer message.
        if (empty($turns) || $turns[count($turns) - 1]['role'] !== 'user'
            || $turns[count($turns) - 1]['content'] !== trim($incoming)) {
            if (! empty($turns) && $turns[count($turns) - 1]['role'] === 'user') {
                $turns[count($turns) - 1]['content'] = trim($incoming);
            } else {
                $turns[] = ['role' => 'user', 'content' => trim($incoming)];
            }
        }

        // Drop any leading assistant turns — the array must start with a user turn.
        while (! empty($turns) && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return $turns;
    }
}

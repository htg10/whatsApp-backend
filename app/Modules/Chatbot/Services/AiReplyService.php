<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates an AI reply for a chatbot using the business's own instructions as
 * context. Supports two providers over plain HTTP (same pattern as the app's
 * other integrations): Google Gemini and Anthropic Claude. Returns null when AI
 * is unavailable/misconfigured so the caller can fall back gracefully.
 */
class AiReplyService
{
    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const HISTORY_LIMIT = 10;

    public function generate(Chatbot $chatbot, Conversation $conversation, string $incoming): ?string
    {
        $provider = $this->resolveProvider();
        if ($provider === null) {
            Log::warning('AI reply skipped: no API key configured (set GEMINI_API_KEY or ANTHROPIC_API_KEY in .env, then php artisan config:clear)');
            return null; // no AI key configured — skip AI, let caller use fallback
        }
        Log::info('AI reply: using provider ' . $provider);

        $system = $this->systemPrompt($chatbot);
        $messages = $this->history($conversation, $incoming);

        try {
            return $provider === 'gemini'
                ? $this->callGemini($system, $messages)
                : $this->callAnthropic($system, $messages);
        } catch (\Throwable $e) {
            Log::warning('AI reply request failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Which provider to use: explicit config, else auto-detect by available key. */
    private function resolveProvider(): ?string
    {
        $pref = config('services.ai.provider');
        if ($pref === 'gemini' && config('services.gemini.key')) {
            return 'gemini';
        }
        if ($pref === 'anthropic' && config('services.anthropic.key')) {
            return 'anthropic';
        }
        if (config('services.gemini.key')) {
            return 'gemini';
        }
        if (config('services.anthropic.key')) {
            return 'anthropic';
        }
        return null;
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    private function callGemini(string $system, array $messages): ?string
    {
        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $contents = array_map(fn ($m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $res = Http::withHeaders(['x-goog-api-key' => config('services.gemini.key')])
            ->timeout(30)
            ->post(self::GEMINI_ENDPOINT . $model . ':generateContent', [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                'generationConfig' => ['maxOutputTokens' => 600, 'temperature' => 0.7],
            ]);

        if ($res->failed()) {
            Log::warning('Gemini reply API error', ['status' => $res->status(), 'body' => $res->json('error.message')]);
            return null;
        }

        $text = $res->json('candidates.0.content.parts.0.text');

        return $text ? trim($text) : null;
    }

    /** @param array<int, array{role:string, content:string}> $messages */
    private function callAnthropic(string $system, array $messages): ?string
    {
        $res = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(30)->post(self::ANTHROPIC_ENDPOINT, [
            'model' => config('services.anthropic.model', 'claude-opus-5'),
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

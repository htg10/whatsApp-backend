<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hot-lead detection. Looks at every inbound customer message and, when the
 * customer shows buying interest, flags the contact as hot (contacts.is_hot)
 * so it appears on the Hot List.
 *
 *  1. The AI decides. It reads the recent conversation (customer + bot replies)
 *     and the business info, and judges whether the customer is a hot lead —
 *     in English, Hindi or Hinglish. This is the main path.
 *  2. Only if the AI is unavailable (no API key / request failed) a quick
 *     keyword check is used as a fallback, so nothing is missed.
 *
 * HOT_LEAD_AI=false skips the AI and uses keywords only.
 */
class HotLeadDetector
{
    /** [reason label => regex alternatives]. First match wins. */
    private const INTEREST = [
        'Asked about pricing' => 'price|prices|pricing|cost|costing|rate|rates|charges?|fees?|quote|quotation|how much|kitna|kitne|kimat|keemat|daam|dam kya|कीमत|कितना|कितने|दाम|रेट|भाव',
        'Wants a demo / call / meeting' => 'demo|free trial|trial|meeting|appointment|site visit|visit|schedule|call me|call back|callback|give me a call|call kar(?:o|na|ein)?|phone kar(?:o|na)?|कॉल',
        'Wants to buy / order / book' => 'buy|purchase|order|book|booking|book kar(?:o|na)?|lena hai|leni hai|len[ae]|kharid(?:na|ni)?|khareed(?:na|ni)?|खरीदना|लेना है|बुक',
        'Expressed interest' => 'interested|interest|i want|we want|i need|we need|looking for|require|requirement|chahiye|chahie|chahta|chahti|zarurat|ज़रूरत|जरूरत|चाहिए|रुचि|इंटरेस्ट',
        'Asked for details / brochure' => 'details|detail|more info|information|brochure|catalog|catalogue|portfolio|samples?|plans?|packages?|offers?|discount|jankari|batao|bataiye|bataye|बताइए|बताओ|जानकारी',
        'Ready to proceed / pay' => 'payment|pay now|invoice|proceed|go ahead|confirm|confirmed|let\'?s (?:do|start|proceed|go)|start now|sign ?up|register|onboard',
    ];

    /** Explicit no-interest signals — checked first, they win over everything. */
    private const NEGATIVE = 'not interested|no interest|not looking|don\'?t (?:want|need|call|message)|do not (?:want|need|call|message)|stop|unsubscribe|remove me|no thanks|no thank you|not now|nahi chahiye|nhi chahiye|interested nahi|interested nhi|mat (?:karo|bhejo|bhejna)|band karo|नहीं चाहिए|रुचि नहीं|मत भेजो|मत करो|बंद करो';

    private const AI_SYSTEM = <<<'TXT'
You screen WhatsApp conversations for a business and decide whether the CUSTOMER is a hot sales lead.

You get the business information, the recent conversation (Customer / Business lines) and the customer's latest message. Judge the latest message IN CONTEXT of the conversation.

HOT (true) when the customer shows real buying interest, for example:
- asks about price, plans, packages, availability, timelines or how the service works for them
- wants a demo, call, meeting, site visit or quotation
- wants to order, book, buy or sign up, or asks how to pay
- shares their requirement or says they are interested / "haan", "yes", "ok send" in reply to the business offering something
- asks for details, brochure, samples or portfolio in a way that shows they may buy

NOT hot (false):
- greetings, thanks, one-word acknowledgements with no offer to accept, spam, jokes
- support problems or complaints, job seekers, wrong number
- anyone who says they are not interested / do not want it / stop messaging

The customer may write in English, Hindi or Hinglish.
Reply with ONLY a JSON object and nothing else:
{"hot": true or false, "reason": "<max 12 words, why>"}
TXT;

    public function __construct(private readonly AiReplyService $ai) {}

    public function evaluate(Contact $contact, Conversation $conversation, string $type, ?string $body): void
    {
        if (! in_array($type, ['text', 'button', 'interactive'], true)) {
            return;
        }

        $body = trim((string) $body);
        if ($body === '' || $contact->is_hot || $contact->is_blocked || $contact->opted_out) {
            return; // nothing to read, or already on the hot list
        }

        $verdict = null;

        // 1) AI decides (main path).
        if (config('services.hot_lead.ai', true)) {
            $verdict = $this->byAi($conversation, $body);
        }

        // 2) Fallback only when the AI could not answer (no key / request failed).
        if ($verdict === null) {
            $verdict = $this->byKeywords($body);
            if ($verdict !== null) {
                Log::info('hot-lead: AI unavailable, used keyword fallback', ['contact' => $contact->id]);
            }
        }

        if ($verdict && $verdict['hot']) {
            $this->markHot($contact, $verdict['reason']);
        }
    }

    /**
     * @return array{hot: bool, reason: string}|null  null = inconclusive
     */
    private function byKeywords(string $body): ?array
    {
        if (preg_match($this->wrap(self::NEGATIVE), $body) === 1) {
            return ['hot' => false, 'reason' => 'Not interested'];
        }

        foreach (self::INTEREST as $label => $alternatives) {
            if (preg_match($this->wrap($alternatives), $body) === 1) {
                return ['hot' => true, 'reason' => $label . ': “' . Str::limit($body, 90) . '”'];
            }
        }

        return null;
    }

    /**
     * @return array{hot: bool, reason: string}|null  null = AI unavailable / unusable answer
     */
    private function byAi(Conversation $conversation, string $body): ?array
    {
        $recent = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('type', 'text')
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->reverse();

        $transcript = $recent
            ->map(fn (Message $m) => ($m->direction === Message::DIRECTION_OUTBOUND ? 'Business' : 'Customer')
                . ': ' . Str::limit(trim((string) $m->body), 300))
            ->implode("\n");

        $business = trim((string) Chatbot::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('is_active', true)
            ->whereNotNull('ai_instructions')
            ->value('ai_instructions'));

        $prompt = ($business !== '' ? "Business information:\n" . Str::limit($business, 1500) . "\n\n" : '')
            . "Conversation so far:\n{$transcript}\n\nLatest customer message: {$body}";

        $raw = $this->ai->classify(self::AI_SYSTEM, [['role' => 'user', 'content' => $prompt]]);

        if (! $raw || ! preg_match('/\{.*\}/s', $raw, $m)) {
            return null;
        }

        $json = json_decode($m[0], true);
        if (! is_array($json) || ! array_key_exists('hot', $json)) {
            return null;
        }

        $hot = $json['hot'] === true || $json['hot'] === 'true';
        $reason = trim((string) ($json['reason'] ?? '')) ?: 'Shows buying interest';

        Log::info('hot-lead: AI verdict', ['conversation' => $conversation->id, 'hot' => $hot, 'reason' => $reason]);

        return ['hot' => $hot, 'reason' => 'AI: ' . Str::limit($reason, 200)];
    }

    private function markHot(Contact $contact, string $reason): void
    {
        $contact->forceFill([
            'is_hot' => true,
            'hot_reason' => Str::limit($reason, 250),
            'hot_at' => now(),
            'hot_source' => 'auto',
        ])->save();

        Log::info('hot-lead: contact flagged hot', ['contact' => $contact->id, 'reason' => $reason]);
    }

    /** Whole-word, case-insensitive, unicode-safe (works for Hindi too). */
    private function wrap(string $alternatives): string
    {
        return '/(?<![\p{L}\p{N}])(?:' . $alternatives . ')(?![\p{L}\p{N}])/iu';
    }
}
<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hot-lead detection. Looks at every inbound customer message and, when the
 * customer shows buying interest (price, demo, order, "interested", "chahiye"…),
 * flags the contact as hot (contacts.is_hot) so it appears on the Hot List.
 *
 *  1. Fast keyword check (English / Hinglish / Hindi) — free, instant.
 *  2. If the keywords are inconclusive and an AI key is configured, ask the AI
 *     to judge the recent conversation (HOT_LEAD_AI=false turns this off).
 *
 * An explicit "not interested / stop" never flags a contact.
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

A hot lead is a customer who shows real buying interest: asks about price/plans/availability, wants a demo, call, meeting or visit, wants to order/book/sign up, shares requirements, or says they are interested.
NOT hot: greetings, thanks, one-word acknowledgements, spam, complaints, support questions, or anyone who says they are not interested.

The customer may write in English, Hindi or Hinglish.
Reply with ONLY a JSON object, no other text:
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

        $verdict = $this->byKeywords($body);

        if ($verdict === null && config('services.hot_lead.ai', true) && mb_strlen($body) >= 6) {
            $verdict = $this->byAi($conversation, $body);
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
     * @return array{hot: bool, reason: string}|null
     */
    private function byAi(Conversation $conversation, string $body): ?array
    {
        $recent = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('type', 'text')
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->reverse();

        $transcript = $recent
            ->map(fn (Message $m) => ($m->direction === Message::DIRECTION_OUTBOUND ? 'Business' : 'Customer')
                . ': ' . Str::limit(trim((string) $m->body), 300))
            ->implode("\n");

        $raw = $this->ai->classify(self::AI_SYSTEM, [[
            'role' => 'user',
            'content' => "Conversation so far:\n{$transcript}\n\nLatest customer message: {$body}",
        ]]);

        if (! $raw || ! preg_match('/\{.*\}/s', $raw, $m)) {
            return null;
        }

        $json = json_decode($m[0], true);
        if (! is_array($json) || ! array_key_exists('hot', $json)) {
            return null;
        }

        $reason = trim((string) ($json['reason'] ?? '')) ?: 'Shows buying interest';

        return ['hot' => $json['hot'] === true, 'reason' => 'AI: ' . Str::limit($reason, 200)];
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

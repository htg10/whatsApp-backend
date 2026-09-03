<?php

namespace App\Modules\Chatbot\Services;

use App\Models\Chatbot;
use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappPhoneNumber;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Auto-reply engine. On an inbound text message, finds the active chatbot for
 * the receiving number, matches the text against its keyword rules (by priority,
 * first match wins), and sends the configured response — a text or a template.
 * If nothing matches and a fallback message is set, sends that.
 */
class ChatbotEngine
{
    public function __construct(private readonly WhatsAppMessageService $messages) {}

    public function handleInbound(
        WhatsappPhoneNumber $phone,
        Contact $contact,
        Conversation $conversation,
        string $type,
        ?string $body
    ): void {
        // Only auto-reply to plain text, and never when a human agent is handling.
        if ($type !== 'text' || ! $body || $conversation->assigned_agent_id) {
            return;
        }

        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('tenant_id', $phone->tenant_id)
            ->where('is_active', true)
            ->where(function ($q) use ($phone) {
                $q->whereNull('whatsapp_phone_number_id')
                  ->orWhere('whatsapp_phone_number_id', $phone->id);
            })
            ->orderByRaw('whatsapp_phone_number_id IS NULL') // number-specific bot wins over a general one
            ->first();

        if (! $chatbot) {
            return;
        }

        $rules = ChatbotRule::withoutGlobalScopes()
            ->where('chatbot_id', $chatbot->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        $matched = $rules->first(fn (ChatbotRule $rule) => $this->matches($rule, $body));

        $to = $contact->wa_id ?: ltrim((string) $contact->phone, '+');
        if (! $to) {
            return;
        }

        try {
            if ($matched) {
                $this->sendResponse($phone, $to, $matched, $conversation, $contact);
            } elseif ($chatbot->fallback_message) {
                $this->replyText($phone, $to, $chatbot->fallback_message, $conversation, $contact);
            }
        } catch (\Throwable $e) {
            Log::warning('Chatbot auto-reply failed: ' . $e->getMessage(), ['chatbot' => $chatbot->id]);
        }
    }

    private function matches(ChatbotRule $rule, string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $k = mb_strtolower(trim((string) $rule->keyword));
        if ($k === '') {
            return false;
        }

        return match ($rule->match_type) {
            'exact' => $t === $k,
            'starts_with' => str_starts_with($t, $k),
            'regex' => @preg_match('/' . $rule->keyword . '/iu', $text) === 1,
            default => str_contains($t, $k), // contains
        };
    }

    private function sendResponse(WhatsappPhoneNumber $phone, string $to, ChatbotRule $rule, Conversation $conversation, Contact $contact): void
    {
        if ($rule->response_type === 'template' && $rule->template_name) {
            $result = $this->messages->sendTemplate($phone, $to, $rule->template_name, 'en');
            $this->recordOutbound($phone, $conversation, $contact, "[template: {$rule->template_name}]", 'template', $result);
        } elseif ($rule->response_text) {
            $this->replyText($phone, $to, $rule->response_text, $conversation, $contact);
        }
    }

    private function replyText(WhatsappPhoneNumber $phone, string $to, string $body, Conversation $conversation, Contact $contact): void
    {
        $result = $this->messages->sendText($phone, $to, $body);
        $this->recordOutbound($phone, $conversation, $contact, $body, 'text', $result);
    }

    /** Log the bot's reply as an outbound message so it shows in the inbox. */
    private function recordOutbound(WhatsappPhoneNumber $phone, Conversation $conversation, Contact $contact, string $body, string $type, array $result): void
    {
        $wamid = $this->messages->wamid($result);

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $phone->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'whatsapp_phone_number_id' => $phone->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => $type,
            'body' => $body,
            'external_message_id' => $wamid,
            'status' => 'sent',
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($body, 100),
        ]);
    }
}

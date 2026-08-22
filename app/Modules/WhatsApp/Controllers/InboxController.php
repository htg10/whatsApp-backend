<?php

namespace App\Modules\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Modules\WhatsApp\Resources\ConversationResource;
use App\Modules\WhatsApp\Resources\MessageResource;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InboxController extends Controller
{
    public function __construct(private readonly WhatsAppMessageService $messages) {}

    public function conversations(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $query = Conversation::with(['contact', 'phoneNumber', 'assignedAgent'])
            ->orderByDesc('last_message_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->whereHas('contact', fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('wa_id', 'like', "%{$search}%"));
        }

        $conversations = $query->paginate($request->integer('per_page', 25));

        return $this->ok([
            'conversations' => ConversationResource::collection($conversations),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function messages(Request $request, string $conversationUuid): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $conversation = Conversation::where('uuid', $conversationUuid)->firstOrFail();

        $messages = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->orderByDesc('created_at')
            ->cursorPaginate($request->integer('per_page', 50));

        return $this->ok([
            'messages' => MessageResource::collection($messages),
            'next_cursor' => $messages->nextCursor()?->encode(),
            'has_more' => $messages->hasMorePages(),
        ]);
    }

    public function markRead(Request $request, string $conversationUuid): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $conversation = Conversation::where('uuid', $conversationUuid)->firstOrFail();
        $conversation->update(['unread_count' => 0]);

        return $this->ok(['message' => 'Conversation marked as read.']);
    }

    public function send(Request $request, string $conversationUuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $conversation = Conversation::where('uuid', $conversationUuid)
            ->with(['contact', 'phoneNumber'])
            ->firstOrFail();

        $data = $request->validate([
            'type' => ['nullable', 'in:text,template'],
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:4096'],
            'template' => ['required_if:type,template', 'nullable', 'string'],
            'language' => ['nullable', 'string', 'max:16'],
        ]);

        $type = $data['type'] ?? 'text';
        $phone = $conversation->phoneNumber;
        $to = $conversation->contact->wa_id;

        if ($type === 'text') {
            if (! $conversation->windowIsOpen()) {
                return $this->fail('The 24-hour messaging window has expired. Send a template message to re-open the conversation.', 422);
            }
            $result = $this->messages->sendText($phone, $to, $data['body']);
        } else {
            $result = $this->messages->sendTemplate(
                $phone, $to,
                $data['template'],
                $data['language'] ?? 'en',
            );
        }

        $wamid = $this->messages->wamid($result);

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_phone_number_id' => $phone->id,
            'sender_user_id' => $request->user()->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'type' => $type === 'template' ? 'template' : 'text',
            'body' => $data['body'] ?? "[Template: " . ($data['template'] ?? '') . "]",
            'external_message_id' => $wamid,
            'status' => 'sent',
            'sent_at' => now(),
            'payload' => $result,
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit($message->body, 100),
        ]);

        return $this->ok(['message' => new MessageResource($message->load(['sender', 'attachments']))]);
    }
}

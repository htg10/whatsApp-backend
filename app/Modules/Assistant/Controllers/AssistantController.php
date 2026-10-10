<?php

namespace App\Modules\Assistant\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappPhoneNumber;
use App\Modules\Chatbot\Services\AiReplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard AI assistant. Answers questions about the signed-in user's OWN data.
 *
 * Privacy: every figure is gathered with the tenant global scope applied, and an
 * agent (who cannot assign chats) is further limited to the chats assigned to
 * them. The AI model only ever receives this scoped snapshot, so it structurally
 * cannot reveal another business's — or another agent's — data.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AiReplyService $ai) {}

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        $snapshot = $this->snapshot($request);

        $system = implode("\n", [
            'You are a helpful analytics assistant inside a WhatsApp business dashboard.',
            'Answer the user ONLY from the DATA SNAPSHOT below. This snapshot belongs to one',
            'business and to this specific user\'s own view - treat it as the only truth.',
            'If the answer is not in the snapshot, say you do not have that information yet;',
            'never guess or invent numbers, names or amounts.',
            'Be concise and friendly. Reply in the same language the user writes in',
            '(English / Hindi / Hinglish). Use plain text - no markdown tables or headings.',
            '',
            'DATA SNAPSHOT (as of ' . now()->format('d M Y, H:i') . '):',
            $this->snapshotText($snapshot),
        ]);

        $answer = $this->ai->classify($system, [
            ['role' => 'user', 'content' => $data['question']],
        ]);

        if (! $answer) {
            $answer = $this->fallbackAnswer($snapshot);
        }

        return $this->ok([
            'answer' => $answer,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * A compact, scope-correct picture of the user's data.
     *
     * @return array<string, int|string>
     */
    private function snapshot(Request $request): array
    {
        $user = $request->user();
        $onlyAssigned = ! $user->is_super_admin && ! $user->can('conversations.assign');

        $today = now()->startOfDay();
        $weekStart = now()->startOfWeek();

        // Conversations (scoped to the agent's assigned chats when applicable).
        $convBase = Conversation::query();
        if ($onlyAssigned) {
            $convBase->where('assigned_agent_id', $user->id);
        }
        $conversationIds = (clone $convBase)->pluck('id');

        $totalConversations = (clone $convBase)->count();
        $openConversations = (clone $convBase)->where('status', 'open')->count();
        $unreadConversations = (clone $convBase)->where('unread_count', '>', 0)->count();

        // Messages, limited to the same set of conversations for an agent.
        $msgBase = Message::query();
        if ($onlyAssigned) {
            $msgBase->whereIn('conversation_id', $conversationIds);
        }
        $messagesToday = (clone $msgBase)->where('created_at', '>=', $today)->count();
        $inboundToday = (clone $msgBase)->where('created_at', '>=', $today)
            ->where('direction', Message::DIRECTION_INBOUND)->count();
        $outboundToday = (clone $msgBase)->where('created_at', '>=', $today)
            ->where('direction', Message::DIRECTION_OUTBOUND)->count();
        $messagesThisWeek = (clone $msgBase)->where('created_at', '>=', $weekStart)->count();

        // Tenant-wide counts the user is allowed to see.
        $totalContacts = Contact::count();
        $hotContacts = Contact::where('tag_list', 'like', '%' . Contact::HOT_TAG . '%')->count();
        $totalCampaigns = Campaign::count();
        $runningCampaigns = Campaign::where('status', 'running')->count();
        $whatsappNumbers = WhatsappPhoneNumber::count();

        return [
            'scope' => $onlyAssigned ? 'assigned_chats_only' : 'whole_business',
            'whatsapp_numbers' => $whatsappNumbers,
            'total_conversations' => $totalConversations,
            'open_conversations' => $openConversations,
            'unread_conversations' => $unreadConversations,
            'messages_today' => $messagesToday,
            'incoming_messages_today' => $inboundToday,
            'outgoing_messages_today' => $outboundToday,
            'messages_this_week' => $messagesThisWeek,
            'total_contacts' => $totalContacts,
            'hot_leads' => $hotContacts,
            'total_campaigns' => $totalCampaigns,
            'running_campaigns' => $runningCampaigns,
        ];
    }

    /** @param array<string, int|string> $s */
    private function snapshotText(array $s): string
    {
        $scopeNote = $s['scope'] === 'assigned_chats_only'
            ? 'This user is an agent; chat/message figures cover only the chats assigned to them.'
            : 'This user can see the whole business.';

        return implode("\n", [
            '- ' . $scopeNote,
            "- Conversations: {$s['total_conversations']} total, {$s['open_conversations']} open, {$s['unread_conversations']} unread",
            "- Messages today: {$s['messages_today']} ({$s['incoming_messages_today']} incoming, {$s['outgoing_messages_today']} outgoing)",
            "- Messages this week: {$s['messages_this_week']}",
            "- Contacts: {$s['total_contacts']} total, {$s['hot_leads']} hot leads",
            "- Campaigns: {$s['total_campaigns']} total, {$s['running_campaigns']} running",
            "- Connected WhatsApp numbers: {$s['whatsapp_numbers']}",
        ]);
    }

    /** Used when no AI key is configured or the model call fails. */
    private function fallbackAnswer(array $s): string
    {
        return 'Here is a quick summary of your data: '
            . "{$s['total_conversations']} conversations ({$s['open_conversations']} open, {$s['unread_conversations']} unread), "
            . "{$s['messages_today']} messages today, "
            . "{$s['total_contacts']} contacts ({$s['hot_leads']} hot leads), "
            . "{$s['running_campaigns']} running campaigns. "
            . '(AI assistant is not configured right now, so I am showing the key numbers directly.)';
    }
}

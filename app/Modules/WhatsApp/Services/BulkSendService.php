<?php

namespace App\Modules\WhatsApp\Services;

use App\Models\BlacklistEntry;
use App\Models\BulkSend;
use App\Models\BulkSendRecipient;
use App\Models\WhatsappPhoneNumber;
use App\Support\Services\BaseService;
use Illuminate\Support\Str;

class BulkSendService extends BaseService
{
    public function __construct(private readonly WhatsAppMessageService $messages) {}

    public function send(int $tenantId, int $userId, array $numbers, string $template, string $language, array $components = []): BulkSend
    {
        // Prefer the default active number; fall back to ANY active number.
        $phone = WhatsappPhoneNumber::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['connected', 'registered'])
            ->orderByDesc('is_default')
            ->first();

        if (! $phone) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'template' => ['No active WhatsApp number found. Please connect and register a WhatsApp number on the WhatsApp page before sending.'],
            ]);
        }

        $numbers = collect($numbers)
            ->map(fn ($n) => preg_replace('/\D/', '', $n))
            ->filter(fn ($n) => strlen($n) >= 10 && strlen($n) <= 15)
            ->unique()
            ->values()
            ->all();

        // Drop black-listed numbers so opted-out users are never messaged.
        $blocked = BlacklistEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('phone')
            ->all();
        if (! empty($blocked)) {
            $numbers = array_values(array_diff($numbers, $blocked));
        }

        if (empty($numbers)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'numbers' => ['No valid phone numbers left to send (all were invalid or black-listed).'],
            ]);
        }

        $bulkSend = BulkSend::create([
            'tenant_id' => $tenantId,
            'whatsapp_phone_number_id' => $phone->id,
            'template_name' => $template,
            'language' => $language,
            'status' => 'processing',
            'total' => count($numbers),
            'created_by' => $userId,
        ]);

        $recipients = [];
        foreach ($numbers as $number) {
            $recipients[] = BulkSendRecipient::create([
                'bulk_send_id' => $bulkSend->id,
                'phone' => $number,
            ]);
        }

        $bulkSendId = $bulkSend->id;
        $recipientIds = collect($recipients)->pluck('id')->all();
        $messages = $this->messages;

        dispatch(function () use ($bulkSendId, $recipientIds, $phone, $template, $language, $components, $messages) {
            set_time_limit(0);
            ignore_user_abort(true);

            $sentCount = 0;
            $failedCount = 0;

            foreach ($recipientIds as $recipientId) {
                $recipient = BulkSendRecipient::find($recipientId);
                if (! $recipient) continue;

                try {
                    $result = $messages->sendTemplate($phone, $recipient->phone, $template, $language, $components);
                    $wamid = $messages->wamid($result);
                    $recipient->update([
                        'status' => 'sent',
                        'wamid' => $wamid,
                        'sent_at' => now(),
                    ]);
                    $sentCount++;
                } catch (\Throwable $e) {
                    $msg = $e instanceof \App\Modules\WhatsApp\Exceptions\WhatsAppApiException
                        ? $e->userMessage
                        : $e->getMessage();
                    $recipient->update([
                        'status' => 'failed',
                        'error_message' => Str::limit($msg, 500),
                    ]);
                    $failedCount++;
                }

                usleep(50000);
            }

            $bs = BulkSend::find($bulkSendId);
            if ($bs) {
                $bs->update([
                    'status' => $failedCount === count($recipientIds) ? 'failed' : 'completed',
                    'sent_count' => $sentCount,
                    'failed_count' => $failedCount,
                ]);
            }
        })->afterResponse();

        return $bulkSend->fresh(['phoneNumber', 'recipients']);
    }
}

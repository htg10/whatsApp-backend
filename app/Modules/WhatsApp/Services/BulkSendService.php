<?php

namespace App\Modules\WhatsApp\Services;

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
        $phone = WhatsappPhoneNumber::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->whereIn('status', ['connected', 'registered'])
            ->firstOrFail();

        $numbers = collect($numbers)
            ->map(fn ($n) => preg_replace('/\D/', '', $n))
            ->filter(fn ($n) => strlen($n) >= 10 && strlen($n) <= 15)
            ->unique()
            ->values()
            ->all();

        if (empty($numbers)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'numbers' => ['No valid phone numbers found after cleaning.'],
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

        $sentCount = 0;
        $failedCount = 0;

        foreach ($recipients as $recipient) {
            try {
                $result = $this->messages->sendTemplate($phone, $recipient->phone, $template, $language, $components);
                $wamid = $this->messages->wamid($result);
                $recipient->update([
                    'status' => 'sent',
                    'wamid' => $wamid,
                    'sent_at' => now(),
                ]);
                $sentCount++;
            } catch (\Throwable $e) {
                $recipient->update([
                    'status' => 'failed',
                    'error_message' => Str::limit($e->getMessage(), 500),
                ]);
                $failedCount++;
            }

            usleep(50000);
        }

        $bulkSend->update([
            'status' => $failedCount === count($recipients) ? 'failed' : 'completed',
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
        ]);

        return $bulkSend->fresh(['phoneNumber', 'recipients']);
    }
}

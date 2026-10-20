<?php

namespace App\Modules\Campaigns\Services;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Modules\WhatsApp\Exceptions\WhatsAppApiException;
use App\Modules\WhatsApp\Services\WhatsAppMessageService;
use Illuminate\Support\Str;

/**
 * Delivers a running campaign without a queue worker — same model as
 * BulkSendService: a few seconds inline, the rest in an app()->terminating()
 * callback, and stalled runs resumed by status polls or the scheduler.
 */
class CampaignSendService
{
    public const STALE_SECONDS = 150;

    public const INLINE_SECONDS = 8;

    private const MAX_RESUME_AGE_HOURS = 24;

    public function __construct(private readonly WhatsAppMessageService $messages)
    {
    }

    /** Send pending contacts until done, paused/cancelled, or $maxSeconds elapses. */
    public function process(Campaign $campaign, ?int $maxSeconds = null): void
    {
        $deadline = $maxSeconds ? microtime(true) + $maxSeconds : null;

        $campaign->loadMissing(['template.components', 'phoneNumber']);
        $phone = $campaign->phoneNumber;
        $template = $campaign->template;

        if (!$phone || !$template) {
            $this->failPending($campaign);
            return;
        }

        while ($deadline === null || microtime(true) < $deadline) {
            $status = Campaign::withoutGlobalScopes()->whereKey($campaign->id)->value('status');
            if ($status !== 'running') {
                return;
            }

            $campaignContact = CampaignContact::withoutGlobalScopes()
                ->where('campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->with('contact')
                ->orderBy('id')
                ->first();

            if (!$campaignContact) {
                break;
            }

            $error = null;
            try {
                if (!$campaignContact->contact) {
                    throw new \RuntimeException('Contact no longer exists.');
                }
                $this->messages->sendTemplate(
                    $phone,
                    $campaignContact->contact->phone,
                    $template->name,
                    $template->language,
                    $this->buildComponents($campaign, $template, $campaignContact->contact),
                );
            } catch (\Throwable $e) {
                $error = $e instanceof WhatsAppApiException ? $e->userMessage : $e->getMessage();
            }

            $campaignContact->update(['status' => $error === null ? 'sent' : 'failed']);

            CampaignMessage::create([
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'campaign_contact_id' => $campaignContact->id,
                'message_id' => null,
                'status' => $error === null ? 'sent' : 'failed',
                'error_message' => $error === null ? null : Str::limit($error, 500),
                'attempts' => 1,
            ]);

            // Builder increment also bumps updated_at, which is the run's heartbeat.
            Campaign::withoutGlobalScopes()->whereKey($campaign->id)
                ->increment($error === null ? 'sent_count' : 'failed_count');

            usleep(50000);
        }

        $this->completeIfDone($campaign);
    }

    /**
     * The Meta template "components" for one recipient, built from campaigns.variable_mapping.
     *
     *   { "1": "{first_name}", "2": "Diwali offer", "3": "company", "header_media_url": "https://..." }
     *
     * - numeric keys are the body variables {{1}}, {{2}}, ...; a value is plain text, a contact field name
     *   (name, first_name, phone, email, company) or text with {name}-style placeholders filled per contact;
     * - header_media_url is the image / video / document for a template with a media header.
     *
     * An empty or unknown mapping gives [], so a campaign without one is sent exactly as before.
     */
    private function buildComponents(Campaign $campaign, $template, Contact $contact): array
    {
        $mapping = $campaign->variable_mapping;
        if (!is_array($mapping) || $mapping === []) {
            return [];
        }

        $components = [];

        $format = strtoupper((string) optional($template->components->firstWhere('type', 'HEADER'))->format);
        $headerUrl = trim((string) ($mapping['header_media_url'] ?? ''));
        if ($headerUrl !== '' && in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $kind = strtolower($format);
            $components[] = [
                'type' => 'header',
                'parameters' => [['type' => $kind, $kind => ['link' => $headerUrl]]],
            ];
        }

        $last = collect(array_keys($mapping))
            ->filter(fn($key) => ctype_digit((string) $key) && (int) $key > 0)
            ->map(fn($key) => (int) $key)
            ->max() ?? 0;

        if ($last > 0) {
            $parameters = [];
            for ($n = 1; $n <= $last; $n++) {
                $parameters[] = ['type' => 'text', 'text' => $this->resolveValue((string) ($mapping[$n] ?? ''), $contact)];
            }
            $components[] = ['type' => 'body', 'parameters' => $parameters];
        }

        return $components;
    }

    /** Fills one variable for one contact. WhatsApp rejects empty values, new lines and tabs, so none are returned. */
    private function resolveValue(string $value, Contact $contact): string
    {
        $firstName = trim((string) Str::of((string) $contact->name)->trim()->explode(' ')->first());
        $fields = [
            'name' => trim((string) $contact->name),
            'first_name' => $firstName,
            'phone' => trim((string) $contact->phone),
            'email' => trim((string) $contact->email),
            'company' => trim((string) $contact->company),
        ];
        // Greeting words fall back to "there" when the contact has no name; other fields to a dash.
        $fallback = fn(string $field) => in_array($field, ['name', 'first_name'], true) ? 'there' : '-';

        $key = mb_strtolower(trim($value));
        if (array_key_exists($key, $fields)) {
            $out = $fields[$key] !== '' ? $fields[$key] : $fallback($key);
        } else {
            $out = preg_replace_callback(
                '/\{(name|first_name|phone|email|company)\}/i',
                function ($m) use ($fields, $fallback) {
                    $field = mb_strtolower($m[1]);

                    return $fields[$field] !== '' ? $fields[$field] : $fallback($field);
                },
                $value,
            );
        }

        $out = trim((string) preg_replace('/\s+/', ' ', (string) $out));

        return $out === '' ? '-' : Str::limit($out, 1000, '');
    }

    public function continueAfterResponse(Campaign $campaign): void
    {
        $id = $campaign->id;

        app()->terminating(function () use ($id) {
            @set_time_limit(0);
            ignore_user_abort(true);

            $campaign = Campaign::withoutGlobalScopes()->find($id);
            if ($campaign && $campaign->status === 'running') {
                $this->process($campaign);
            }
        });
    }

    /** See BulkSendService::claimIfStalled — same compare-and-swap ownership rule. */
    public function claimIfStalled(Campaign $campaign): bool
    {
        if ($campaign->status !== 'running' || $campaign->updated_at > now()->subSeconds(self::STALE_SECONDS)) {
            return false;
        }

        if (($campaign->started_at ?? $campaign->created_at) < now()->subHours(self::MAX_RESUME_AGE_HOURS)) {
            $this->failPending($campaign);
            return false;
        }

        return Campaign::withoutGlobalScopes()
            ->whereKey($campaign->id)
            ->where('status', 'running')
            ->where('updated_at', $campaign->updated_at)
            ->update(['updated_at' => now()]) === 1;
    }

    private function failPending(Campaign $campaign): void
    {
        $failed = CampaignContact::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        if ($failed > 0) {
            Campaign::withoutGlobalScopes()->whereKey($campaign->id)->increment('failed_count', $failed);
        }

        $this->completeIfDone($campaign);
    }

    private function completeIfDone(Campaign $campaign): void
    {
        $pending = CampaignContact::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->exists();

        if (!$pending) {
            Campaign::withoutGlobalScopes()
                ->whereKey($campaign->id)
                ->where('status', 'running')
                ->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }
}

<?php

namespace App\Modules\WhatsApp\Providers;

use App\Modules\WhatsApp\Contracts\WhatsAppProviderInterface;
use Illuminate\Support\Str;

/**
 * Deterministic in-memory provider for tests and local dev.
 * Records every call so tests can assert against them. Never touches the network.
 */
class FakeWhatsAppProvider implements WhatsAppProviderInterface
{
    public array $sent = [];

    private function fakeWamid(): string
    {
        return 'wamid.FAKE' . Str::upper(Str::random(20));
    }

    public function sendMessage(string $phoneNumberId, string $to, array $message): array
    {
        $this->sent[] = compact('phoneNumberId', 'to', 'message');
        return ['messages' => [['id' => $this->fakeWamid()]]];
    }

    public function sendTemplate(string $phoneNumberId, string $to, array $template): array
    {
        $this->sent[] = compact('phoneNumberId', 'to', 'template');
        return ['messages' => [['id' => $this->fakeWamid()]]];
    }

    public function getTemplates(string $wabaId): array
    {
        return ['data' => []];
    }

    public function getPhoneNumber(string $phoneNumberId): array
    {
        return ['id' => $phoneNumberId, 'display_phone_number' => '15550000000', 'quality_rating' => 'GREEN'];
    }

    public function registerPhoneNumber(string $phoneNumberId, string $pin): array
    {
        return ['success' => true];
    }

    public function uploadMedia(string $phoneNumberId, string $path, string $mime): array
    {
        return ['id' => 'media_' . Str::random(12)];
    }
}

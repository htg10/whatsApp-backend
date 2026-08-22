<?php

namespace App\Modules\WhatsApp\Contracts;

/**
 * The ONLY seam through which the application talks to WhatsApp.
 * Nothing outside a provider implementation may call graph.facebook.com.
 * Bound in a service provider: Meta in production, Fake in tests/local.
 */
interface WhatsAppProviderInterface
{
    public function sendMessage(string $phoneNumberId, string $to, array $message): array;

    public function sendTemplate(string $phoneNumberId, string $to, array $template): array;

    public function getTemplates(string $wabaId): array;

    public function getPhoneNumber(string $phoneNumberId): array;

    public function registerPhoneNumber(string $phoneNumberId, string $pin): array;

    public function uploadMedia(string $phoneNumberId, string $path, string $mime): array;
}

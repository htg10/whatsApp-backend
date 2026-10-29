<?php

namespace App\Console\Commands;

use App\Models\WhatsappBusinessAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnoses why creating a WhatsApp message template fails with Meta's
 * "Object with ID ... does not exist / missing permissions / does not support
 * this operation" error.
 *
 * For each connected WhatsApp Business Account it checks, using the WABA's own
 * token: (1) which Meta app the token belongs to and which permissions it was
 * granted, (2) whether the stored WABA ID is a real, readable WABA, and (3)
 * whether we can read its message templates (needs whatsapp_business_management).
 * The combination pinpoints the cause — wrong WABA ID vs a missing permission.
 */
class DiagnoseTemplateSetup extends Command
{
    protected $signature = 'whatsapp:diagnose-template {--tenant=}';

    protected $description = 'Check each WABA token/ID/permissions to explain template-create failures';

    public function handle(): int
    {
        $base = rtrim((string) config('services.meta.graph_base', 'https://graph.facebook.com'), '/')
            . '/' . config('services.meta.api_version', 'v23.0');

        $query = WhatsappBusinessAccount::withoutGlobalScopes();
        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', $tenant);
        }
        $wabas = $query->get();

        if ($wabas->isEmpty()) {
            $this->warn('No WhatsApp Business Accounts found.');
            return self::SUCCESS;
        }

        $this->info("Configured META_APP_ID: " . (config('services.meta.app_id') ?: '(not set)'));
        $this->line(str_repeat('=', 70));

        foreach ($wabas as $waba) {
            $this->line("\nTenant {$waba->tenant_id} · WABA record #{$waba->id}");
            $this->line("  Stored WABA ID        : {$waba->waba_id}");
            $this->line("  Business portfolio ID : " . ($waba->business_portfolio_id ?: '—'));

            $token = (string) $waba->access_token;
            if ($token === '') {
                $this->error('  No access token stored — reconnect this number.');
                continue;
            }

            // 1) Token identity + granted scopes
            try {
                $dbg = Http::baseUrl($base)->acceptJson()->timeout(20)
                    ->get('/debug_token', ['input_token' => $token, 'access_token' => $token])->json('data') ?? [];
                $this->line('  Token app id          : ' . ($dbg['app_id'] ?? '?') . ($dbg['application'] ?? '' ? " ({$dbg['application']})" : ''));
                $this->line('  Token valid           : ' . (($dbg['is_valid'] ?? false) ? 'yes' : 'NO'));
                $scopes = $dbg['scopes'] ?? [];
                $this->line('  Scopes                : ' . (empty($scopes) ? '(none)' : implode(', ', $scopes)));
                $hasMgmt = in_array('whatsapp_business_management', $scopes, true);
                $this->line('  whatsapp_business_management: ' . ($hasMgmt ? 'YES ✓' : 'MISSING ✗ (needed to create templates)'));
            } catch (\Throwable $e) {
                $this->error('  debug_token failed: ' . $e->getMessage());
            }

            // 2) Is the stored WABA ID a real, readable WABA?
            try {
                $res = Http::withToken($token)->baseUrl($base)->acceptJson()->timeout(20)
                    ->get("/{$waba->waba_id}", ['fields' => 'id,name,currency']);
                if ($res->ok()) {
                    $this->line('  WABA lookup           : OK (' . ($res->json('name') ?? 'unnamed') . ')');
                } else {
                    $this->error('  WABA lookup FAILED    : ' . ($res->json('error.message') ?? $res->body()));
                    $this->warn('    → The stored WABA ID looks wrong. Fix it on the WhatsApp page.');
                }
            } catch (\Throwable $e) {
                $this->error('  WABA lookup error: ' . $e->getMessage());
            }

            // 3) Can we read templates (confirms management access on this WABA)?
            try {
                $res = Http::withToken($token)->baseUrl($base)->acceptJson()->timeout(20)
                    ->get("/{$waba->waba_id}/message_templates", ['limit' => 1]);
                $this->line('  Read templates        : ' . ($res->ok() ? 'OK ✓ (create should work)' : 'FAILED ✗ — ' . ($res->json('error.message') ?? $res->body())));
            } catch (\Throwable $e) {
                $this->error('  Template read error: ' . $e->getMessage());
            }
        }

        $this->line("\n" . str_repeat('=', 70));
        $this->info('Share this output to get the exact fix.');

        return self::SUCCESS;
    }
}

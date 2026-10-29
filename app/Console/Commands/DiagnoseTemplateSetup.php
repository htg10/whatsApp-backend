<?php

namespace App\Console\Commands;

use App\Models\WhatsappBusinessAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Diagnoses why creating a WhatsApp message template fails with Meta's
 * "Object with ID ... does not exist / missing permissions / does not support
 * this operation" error.
 *
 * For each connected WABA it reports, using reliable checks:
 *  - the token's real app id, validity, expiry and granted scopes, inspected
 *    with our APP access token (self-introspection returns nothing for a
 *    customer's token, which is misleading);
 *  - which Meta app is subscribed to the WABA (the app a template is created
 *    under);
 *  - whether the WABA and its templates can be READ; and
 *  - with --try-create (requires --tenant), it actually creates a tiny text
 *    template and deletes it, printing Meta's exact create error — the one that
 *    pinpoints a missing whatsapp_business_management permission vs a bad ID.
 */
class DiagnoseTemplateSetup extends Command
{
    protected $signature = 'whatsapp:diagnose-template {--tenant=} {--try-create : Attempt a throwaway text template to test write access (needs --tenant)}';

    protected $description = 'Check each WABA token/ID/permissions to explain template-create failures';

    private string $base;

    public function handle(): int
    {
        $this->base = rtrim((string) config('services.meta.graph_base', 'https://graph.facebook.com'), '/')
            . '/' . config('services.meta.api_version', 'v23.0');

        $appId = config('services.meta.app_id');
        $appSecret = config('services.meta.app_secret');
        $appToken = ($appId && $appSecret) ? ($appId . '|' . $appSecret) : null;

        $tryCreate = (bool) $this->option('try-create');
        if ($tryCreate && ! $this->option('tenant')) {
            $this->error('--try-create needs --tenant=<id> so it only tests one account.');
            return self::FAILURE;
        }

        $query = WhatsappBusinessAccount::withoutGlobalScopes();
        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', $tenant);
        }
        $wabas = $query->get();

        if ($wabas->isEmpty()) {
            $this->warn('No WhatsApp Business Accounts found.');
            return self::SUCCESS;
        }

        $this->info('Configured META_APP_ID: ' . ($appId ?: '(not set)'));
        $this->line(str_repeat('=', 70));

        foreach ($wabas as $waba) {
            $token = (string) $waba->access_token;
            $this->line("\nTenant {$waba->tenant_id} · WABA record #{$waba->id} · {$waba->name}");
            $this->line("  Stored WABA ID : {$waba->waba_id}");

            if ($token === '') {
                $this->error('  No access token stored — reconnect this number.');
                continue;
            }

            // 1) Reliable token inspection using OUR app access token.
            if ($appToken) {
                $dbg = Http::baseUrl($this->base)->acceptJson()->timeout(20)
                    ->get('/debug_token', ['input_token' => $token, 'access_token' => $appToken]);
                $d = $dbg->json('data');
                if (is_array($d) && ($d['app_id'] ?? null)) {
                    $expires = ($d['expires_at'] ?? 0);
                    $expStr = ! $expires ? 'never' : date('Y-m-d H:i', $expires) . ($expires < time() ? '  ← EXPIRED' : '');
                    $this->line('  Token app id   : ' . $d['app_id'] . (($d['app_id'] == $appId) ? '  (our app)' : '  (DIFFERENT app)'));
                    $this->line('  Token valid    : ' . (($d['is_valid'] ?? false) ? 'yes' : 'NO'));
                    $this->line('  Token expires  : ' . $expStr);
                    $scopes = $d['scopes'] ?? [];
                    $this->line('  Scopes         : ' . (empty($scopes) ? '(none)' : implode(', ', $scopes)));
                    $this->line('  Can manage WABA: ' . (in_array('whatsapp_business_management', $scopes, true) ? 'YES ✓' : 'MISSING ✗ (needed to create templates)'));
                } else {
                    $this->warn('  Token is NOT from our app (likely a manual-connect token from the customer\'s own app).');
                    $this->line('    debug_token error: ' . ($dbg->json('error.message') ?? $dbg->body()));
                }
            }

            // 2) Which app is subscribed to this WABA (templates are created under it).
            $sub = Http::withToken($token)->baseUrl($this->base)->acceptJson()->timeout(20)
                ->get("/{$waba->waba_id}/subscribed_apps");
            if ($sub->ok()) {
                $apps = collect($sub->json('data') ?? [])
                    ->map(fn ($a) => ($a['whatsapp_business_api_data']['id'] ?? '?') . ' (' . ($a['whatsapp_business_api_data']['name'] ?? '?') . ')')
                    ->implode(', ');
                $this->line('  Subscribed app : ' . ($apps !== '' ? $apps : 'NONE ✗ (no app subscribed — create/send will fail)'));
            } else {
                $this->error('  subscribed_apps FAILED: ' . ($sub->json('error.message') ?? $sub->body()));
            }

            // 3) Read checks.
            $r1 = Http::withToken($token)->baseUrl($this->base)->acceptJson()->timeout(20)->get("/{$waba->waba_id}", ['fields' => 'id,name']);
            $this->line('  WABA lookup    : ' . ($r1->ok() ? 'OK' : 'FAILED — ' . ($r1->json('error.message') ?? $r1->body())));
            $r2 = Http::withToken($token)->baseUrl($this->base)->acceptJson()->timeout(20)->get("/{$waba->waba_id}/message_templates", ['limit' => 1]);
            $this->line('  Read templates : ' . ($r2->ok() ? 'OK' : 'FAILED — ' . ($r2->json('error.message') ?? $r2->body())));

            // 4) The decisive test: actually create (then delete) a text template.
            if ($tryCreate) {
                $name = 'zz_diag_' . Str::lower(Str::random(8));
                $create = Http::withToken($token)->baseUrl($this->base)->acceptJson()->timeout(30)
                    ->post("/{$waba->waba_id}/message_templates", [
                        'name' => $name,
                        'language' => 'en',
                        'category' => 'UTILITY',
                        'components' => [['type' => 'BODY', 'text' => 'Diagnostic test. Please ignore.']],
                    ]);
                if ($create->ok()) {
                    $this->info('  CREATE TEST    : OK ✓ — template creation works. (cleaning up)');
                    Http::withToken($token)->baseUrl($this->base)->acceptJson()->timeout(20)
                        ->delete("/{$waba->waba_id}/message_templates", ['name' => $name]);
                } else {
                    $this->error('  CREATE TEST    : FAILED ✗ — ' . ($create->json('error.message') ?? $create->body()));
                    $this->line('    error code/subcode: ' . ($create->json('error.code') ?? '?') . '/' . ($create->json('error.error_subcode') ?? '?'));
                }
            }
        }

        $this->line("\n" . str_repeat('=', 70));
        $this->info('Share this output to get the exact fix.' . ($tryCreate ? '' : ' Add --tenant=<id> --try-create for the decisive write test.'));

        return self::SUCCESS;
    }
}

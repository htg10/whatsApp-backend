<?php

namespace App\Console\Commands;

use App\Models\WhatsappBusinessAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Marks WhatsApp Business Account records as "disconnected" when their WABA ID no
 * longer exists on Meta (every Graph call returns "Object ... does not exist").
 *
 * A tenant that reconnects WhatsApp several times accumulates stale WABA rows
 * pointing at deleted/replaced WABA IDs. Template create/upload then sometimes
 * picks a dead row and fails. Flagging the dead rows disconnected keeps them out
 * of selection (which prefers connected) without deleting history. Reversible;
 * dry-run by default.
 */
class CleanupDeadWabas extends Command
{
    protected $signature = 'whatsapp:cleanup-wabas {--tenant=} {--apply : Write changes (otherwise dry-run)}';

    protected $description = 'Flag WABA records whose WABA ID no longer exists on Meta as disconnected';

    public function handle(): int
    {
        $base = rtrim((string) config('services.meta.graph_base', 'https://graph.facebook.com'), '/')
            . '/' . config('services.meta.api_version', 'v23.0');
        $apply = (bool) $this->option('apply');

        $query = WhatsappBusinessAccount::withoutGlobalScopes();
        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', $tenant);
        }
        $wabas = $query->get();

        if ($wabas->isEmpty()) {
            $this->warn('No WABA records found.');
            return self::SUCCESS;
        }

        $this->info(($apply ? 'APPLYING' : 'DRY-RUN') . ': checking ' . $wabas->count() . ' WABA record(s)');
        $dead = 0;

        foreach ($wabas as $waba) {
            $token = (string) $waba->access_token;
            if ($token === '') {
                continue;
            }

            $res = Http::withToken($token)->baseUrl($base)->acceptJson()->timeout(20)
                ->get("/{$waba->waba_id}", ['fields' => 'id']);

            // Only a genuine "object does not exist" (code 100) means the WABA is
            // gone. Leave transient/other errors alone so we never disconnect a
            // live account over a temporary blip.
            $isDead = $res->failed() && (int) $res->json('error.code') === 100;

            $tag = $isDead ? 'DEAD' : 'ok';
            $this->line(sprintf('  [%s] tenant %d · #%d · %s · %s', $tag, $waba->tenant_id, $waba->id, $waba->waba_id, $waba->status));

            if ($isDead) {
                $dead++;
                if ($apply && $waba->status !== 'disconnected') {
                    $waba->update(['status' => 'disconnected']);
                }
            }
        }

        $this->line(str_repeat('-', 60));
        if ($apply) {
            $this->info("Done. {$dead} dead WABA record(s) flagged disconnected.");
        } else {
            $this->info("Found {$dead} dead WABA record(s). Re-run with --apply to flag them disconnected.");
        }

        return self::SUCCESS;
    }
}

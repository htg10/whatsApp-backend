<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Modules\WhatsApp\Services\BulkSendService;
use Illuminate\Console\Command;

/**
 * Replays stored WhatsApp delivery-status webhooks against bulk-send recipients.
 *
 * Why: a bulk recipient is marked "sent" the moment Meta accepts the send. Meta
 * then reports the real outcome (delivered / failed) via status webhooks. For
 * cold/bulk numbers with no inbox message, those webhooks used to be dropped
 * ("no message found"), so recipients stayed "sent" forever and the bulk-send
 * Sent/Failed columns over-reported vs Meta Insights.
 *
 * The raw webhooks are still stored (webhook_events, object_type = 'status'), so
 * this command replays them to retroactively move recipients to delivered/failed
 * and re-sync each bulk send's counts. Safe to run repeatedly (idempotent).
 */
class ReconcileBulkStatus extends Command
{
    protected $signature = 'bulk:reconcile-status {--days=60 : How far back to replay status webhooks} {--apply : Write changes (otherwise dry-run)}';

    protected $description = 'Replay stored delivery-status webhooks so bulk-send Sent/Failed match what Meta actually delivered';

    public function handle(BulkSendService $bulk): int
    {
        $days = (int) $this->option('days');
        $apply = (bool) $this->option('apply');
        $since = now()->subDays($days);

        $query = WebhookEvent::query()
            ->where('object_type', 'status')
            ->where('created_at', '>=', $since)
            ->orderBy('id');

        $total = (int) $query->count();
        if ($total === 0) {
            $this->info("No status webhooks found in the last {$days} days.");
            return self::SUCCESS;
        }

        $this->info(($apply ? 'APPLYING' : 'DRY-RUN') . ": replaying {$total} status webhooks from the last {$days} days");
        if (! $apply) {
            $this->warn('No changes will be written. Re-run with --apply to update recipients.');
        }

        $changed = 0;
        $seen = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(500, function ($events) use ($bulk, $apply, &$changed, &$seen, $bar) {
            foreach ($events as $event) {
                $bar->advance();
                $status = $event->payload['status'] ?? null;
                if (! is_array($status)) {
                    continue;
                }
                $wamid = $status['id'] ?? '';
                $name = $status['status'] ?? '';
                if ($wamid === '' || $name === '') {
                    continue;
                }
                $seen++;

                if (! $apply) {
                    // Dry-run: only estimate how many would flip delivered/failed.
                    if (in_array($name, ['delivered', 'read', 'failed'], true)) {
                        $changed++;
                    }
                    continue;
                }

                if ($bulk->syncRecipientStatus(
                    $wamid,
                    $name,
                    $status['errors'][0]['title'] ?? null,
                    isset($status['errors'][0]['code']) ? (string) $status['errors'][0]['code'] : null,
                )) {
                    $changed++;
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($apply) {
            $this->info("Done. {$changed} recipient status change(s) applied across {$seen} delivery events.");
            $this->line('Open Campaigns → Bulk Send to see the corrected Sent/Failed columns.');
        } else {
            $this->info("Would process ~{$changed} delivery events (of {$seen}). Re-run with --apply to write.");
        }

        return self::SUCCESS;
    }
}

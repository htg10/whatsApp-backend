<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Explains why the in-app Analytics (Sent/Delivered/Read/Failed) differs from
 * Meta's WhatsApp Manager > Insights. Prints the current-status buckets, a
 * cumulative delivery funnel built from the message_statuses event log, and the
 * breakdown of "failed" messages by Meta error code — so we can see how many of
 * the failures are genuine non-deliveries vs Meta policy drops (e.g. the
 * marketing per-user frequency cap) that Meta excludes from its Insights.
 */
class DiagnoseAnalytics extends Command
{
    protected $signature = 'analytics:diagnose {--days=30} {--tenant=}';

    protected $description = 'Break down outbound message delivery status to reconcile with Meta Insights';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days)->startOfDay();
        $tenant = $this->option('tenant');

        $base = DB::table('messages')->where('direction', 'outbound')->where('created_at', '>=', $since);
        if ($tenant) {
            $base->where('tenant_id', $tenant);
            $this->info("Tenant filter: {$tenant}");
        }

        $this->info("Outbound messages in the last {$days} days (since {$since->toDateString()})");
        $this->line(str_repeat('=', 64));

        // 1) Current-status buckets (what the dashboard shows today)
        $buckets = (clone $base)->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')->pluck('c', 'status');
        $total = (clone $base)->count();

        $this->line("\n[1] CURRENT-STATUS BUCKETS (today's dashboard logic)");
        foreach (['queued', 'sent', 'delivered', 'read', 'failed'] as $s) {
            $this->line(sprintf('    %-10s %6d', $s, (int) ($buckets[$s] ?? 0)));
        }
        $this->line(sprintf('    %-10s %6d', 'TOTAL', $total));

        // 2) Cumulative funnel from the message_statuses event log (Meta-style)
        $ids = (clone $base)->pluck('id');
        $ev = fn (string $status) => DB::table('message_statuses')
            ->whereIn('message_id', $ids)->where('status', $status)
            ->distinct('message_id')->count('message_id');

        $reached = DB::table('message_statuses')->whereIn('message_id', $ids)
            ->whereIn('status', ['delivered', 'read'])->distinct('message_id')->count('message_id');

        $this->line("\n[2] CUMULATIVE FUNNEL from message_statuses events (Meta-style)");
        $this->line(sprintf('    %-24s %6d', 'has sent event', $ev('sent')));
        $this->line(sprintf('    %-24s %6d', 'has delivered event', $ev('delivered')));
        $this->line(sprintf('    %-24s %6d', 'has read event', $ev('read')));
        $this->line(sprintf('    %-24s %6d', 'DELIVERED (deliv OR read)', $reached));
        $this->line(sprintf('    %-24s %6d', 'has failed event', $ev('failed')));

        // 3) "Failed" messages that actually reached the device (wrong downgrade)
        $failedButReached = DB::table('messages as m')
            ->whereIn('m.id', $ids)->where('m.status', 'failed')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('message_statuses as s')
                  ->whereColumn('s.message_id', 'm.id')->whereIn('s.status', ['delivered', 'read']);
            })->count();
        $this->line("\n[3] Messages marked FAILED that DID reach the device (bad downgrade)");
        $this->line(sprintf('    %6d  (these should count as delivered)', $failedButReached));

        // 4) Breakdown of failed by Meta error code
        $this->line("\n[4] FAILED breakdown by Meta error code");
        $codes = (clone $base)->where('status', 'failed')
            ->select('error_code', DB::raw('COUNT(*) as c'))
            ->groupBy('error_code')->orderByDesc('c')->get();
        if ($codes->isEmpty()) {
            $this->line('    (none)');
        }
        foreach ($codes as $row) {
            $this->line(sprintf('    code %-8s %6d  — %s', $row->error_code ?? 'null', $row->c, $this->codeLabel($row->error_code)));
        }

        $this->line("\n" . str_repeat('=', 64));
        $this->info('Share this output to get the exact Analytics fix.');

        return self::SUCCESS;
    }

    private function codeLabel(?string $code): string
    {
        return match ((string) $code) {
            '131049' => 'Meta per-user MARKETING frequency cap (dropped, not a real failure)',
            '131050' => 'User stopped marketing messages (opt-out)',
            '131047' => '24h window closed — needs a template',
            '131026' => 'Not on WhatsApp / undeliverable',
            '131021' => 'Recipient blocked the business',
            '131030' => 'Number not in allowed list (dev mode)',
            '130472' => 'Marketing frequency cap (experiment group)',
            '0', '' , 'null' => 'no error code recorded',
            default => '—',
        };
    }
}

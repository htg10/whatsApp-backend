<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('analytics.view');

        $today = Carbon::now()->startOfDay();
        $weekAgo = Carbon::now()->subDays(7);
        $monthAgo = Carbon::now()->subDays(30);

        return $this->ok([
            'total_contacts' => Contact::count(),
            'total_conversations' => Conversation::count(),
            'open_conversations' => Conversation::where('status', 'open')->count(),
            'messages_today' => Message::where('created_at', '>=', $today)->count(),
            'messages_this_week' => Message::where('created_at', '>=', $weekAgo)->count(),
            'messages_this_month' => Message::where('created_at', '>=', $monthAgo)->count(),
            'outbound_today' => Message::where('direction', 'outbound')
                ->where('created_at', '>=', $today)
                ->count(),
            'inbound_today' => Message::where('direction', 'inbound')
                ->where('created_at', '>=', $today)
                ->count(),
            'templates_approved' => Template::where('status', 'APPROVED')->count(),
            'active_campaigns' => Campaign::where('status', 'running')->count(),
            'total_campaigns' => Campaign::count(),
        ]);
    }

    public function messageStats(Request $request): JsonResponse
    {
        $this->authorize('analytics.view');

        $days = min((int) $request->query('days', 30), 90);
        $startDate = Carbon::now()->subDays($days)->startOfDay();
        $tenantId = $request->user()->tenant_id;

        // Delivery is read from the message_statuses EVENT LOG, the same way Meta's
        // WhatsApp Manager > Insights counts — NOT from the message's mutable
        // "current status" column. A message counts as "reached the device" if it
        // ever received a `delivered` OR `read` event, even if a later stray
        // `failed` event flipped the row's status. This is why the old buckets
        // (Sent 70 / Failed 1602) never matched Meta and these totals do.
        $reachedSub = "SELECT DISTINCT message_id FROM message_statuses WHERE status IN ('delivered','read')";
        $readSub = "SELECT DISTINCT message_id FROM message_statuses WHERE status = 'read'";

        $daily = DB::table('messages as m')
            ->leftJoin(DB::raw("($reachedSub) as dr"), 'dr.message_id', '=', 'm.id')
            ->leftJoin(DB::raw("($readSub) as rd"), 'rd.message_id', '=', 'm.id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.created_at', '>=', $startDate)
            ->selectRaw('DATE(m.created_at) as date')
            ->selectRaw("SUM(CASE WHEN m.direction = 'outbound' THEN 1 ELSE 0 END) as outbound")
            ->selectRaw("SUM(CASE WHEN m.direction = 'inbound' THEN 1 ELSE 0 END) as inbound")
            ->selectRaw("SUM(CASE WHEN m.direction = 'outbound' AND dr.message_id IS NOT NULL THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN m.direction = 'outbound' AND rd.message_id IS NOT NULL THEN 1 ELSE 0 END) as `read`")
            ->groupByRaw('DATE(m.created_at)')
            ->orderBy('date')
            ->get();

        // Cumulative funnel (Meta-style):
        //   Sent      = every message WhatsApp accepted = all outbound rows
        //               (a row is only created once Meta accepts the send).
        //   Delivered = reached the device (delivered OR read events).
        //   Read      = read events.
        //   Failed    = accepted but never reached the device (Sent - Delivered).
        $totalOutbound = (int) $daily->sum('outbound');
        $totalInbound = (int) $daily->sum('inbound');
        $totalDelivered = (int) $daily->sum('delivered');
        $totalRead = (int) $daily->sum('read');
        $totalSent = $totalOutbound;
        $totalFailed = max($totalSent - $totalDelivered, 0);

        // Per-day series in the shape the chart already understands.
        $dailyOut = $daily->map(function ($r) {
            $outbound = (int) $r->outbound;
            $delivered = (int) $r->delivered;

            return [
                'date' => $r->date,
                'sent' => $outbound,
                'delivered' => $delivered,
                'read' => (int) $r->read,
                'failed' => max($outbound - $delivered, 0),
                'inbound' => (int) $r->inbound,
            ];
        });

        return $this->ok([
            'daily' => $dailyOut,
            'totals' => [
                'total_sent' => $totalSent,
                'total_delivered' => $totalDelivered,
                'total_read' => $totalRead,
                'total_failed' => $totalFailed,
                'total_inbound' => $totalInbound,
                'delivery_rate' => $totalSent > 0
                    ? round($totalDelivered / $totalSent * 100, 2)
                    : 0,
                'read_rate' => $totalSent > 0
                    ? round($totalRead / $totalSent * 100, 2)
                    : 0,
            ],
        ]);
    }

    public function campaignStats(Request $request): JsonResponse
    {
        $this->authorize('analytics.view');

        $campaigns = Campaign::orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function (Campaign $campaign) {
                $totalOutbound = $campaign->sent_count
                    + $campaign->delivered_count
                    + $campaign->read_count
                    + $campaign->failed_count;

                return [
                    'name' => $campaign->name,
                    'status' => $campaign->status,
                    'total_recipients' => $campaign->total_recipients,
                    'sent_count' => $campaign->sent_count,
                    'delivered_count' => $campaign->delivered_count,
                    'read_count' => $campaign->read_count,
                    'failed_count' => $campaign->failed_count,
                    'delivery_rate' => $totalOutbound > 0
                        ? round(($campaign->delivered_count + $campaign->read_count) / $totalOutbound * 100, 2)
                        : 0,
                    'read_rate' => $totalOutbound > 0
                        ? round($campaign->read_count / $totalOutbound * 100, 2)
                        : 0,
                ];
            });

        return $this->ok(['campaigns' => $campaigns]);
    }
}

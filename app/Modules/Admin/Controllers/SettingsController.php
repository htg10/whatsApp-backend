<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Models\InvoiceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super-admin settings: the seller/company details printed on every invoice.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->ok(['settings' => $this->toArray(InvoiceSetting::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'tax_details' => ['nullable', 'string', 'max:255'],
            'invoice_prefix' => ['nullable', 'string', 'max:16'],
            'gst_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $settings = InvoiceSetting::current();
        $settings->update($data);

        return $this->ok(['settings' => $this->toArray($settings->fresh()), 'message' => 'Settings saved.']);
    }

    private function toArray(InvoiceSetting $s): array
    {
        return [
            'company_name' => $s->company_name,
            'address' => $s->address,
            'gstin' => $s->gstin,
            'email' => $s->email,
            'phone' => $s->phone,
            'tax_details' => $s->tax_details,
            'invoice_prefix' => $s->invoice_prefix,
            'gst_rate' => $s->gst_rate,
        ];
    }

    /** AI chatbot provider settings (keys are never returned, only their presence). */
    public function showAi(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->ok(['ai' => $this->aiArray(AiSetting::current())]);
    }

    public function updateAi(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $data = $request->validate([
            'provider' => ['required', Rule::in(AiSetting::PROVIDERS)],
            'enabled' => ['required', 'boolean'],
            'gemini_model' => ['nullable', 'string', 'max:100'],
            'openai_model' => ['nullable', 'string', 'max:100'],
            'anthropic_model' => ['nullable', 'string', 'max:100'],
            'gemini_price_in' => ['nullable', 'numeric', 'min:0'],
            'gemini_price_out' => ['nullable', 'numeric', 'min:0'],
            'openai_price_in' => ['nullable', 'numeric', 'min:0'],
            'openai_price_out' => ['nullable', 'numeric', 'min:0'],
            'anthropic_price_in' => ['nullable', 'numeric', 'min:0'],
            'anthropic_price_out' => ['nullable', 'numeric', 'min:0'],
            // Keys are write-only. Send a new value to set it; omit the field to
            // keep the existing key; send "" to clear it.
            'gemini_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'openai_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'anthropic_key' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $settings = AiSetting::current();
        $settings->provider = $data['provider'];
        $settings->enabled = $data['enabled'];
        $settings->gemini_model = $data['gemini_model'] ?? null;
        $settings->openai_model = $data['openai_model'] ?? null;
        $settings->anthropic_model = $data['anthropic_model'] ?? null;

        foreach (['gemini_price_in', 'gemini_price_out', 'openai_price_in', 'openai_price_out', 'anthropic_price_in', 'anthropic_price_out'] as $priceField) {
            if (isset($data[$priceField])) {
                $settings->{$priceField} = $data[$priceField];
            }
        }

        foreach (['gemini_key', 'openai_key', 'anthropic_key'] as $field) {
            if ($request->has($field)) {
                $settings->{$field} = $data[$field] !== '' ? $data[$field] : null;
            }
        }

        $settings->save();

        return $this->ok(['ai' => $this->aiArray($settings->fresh()), 'message' => 'AI settings saved.']);
    }

    private function aiArray(AiSetting $s): array
    {
        return [
            'provider' => $s->provider,
            'enabled' => (bool) $s->enabled,
            'gemini_model' => $s->gemini_model,
            'openai_model' => $s->openai_model,
            'anthropic_model' => $s->anthropic_model,
            'gemini_price_in' => (float) $s->gemini_price_in,
            'gemini_price_out' => (float) $s->gemini_price_out,
            'openai_price_in' => (float) $s->openai_price_in,
            'openai_price_out' => (float) $s->openai_price_out,
            'anthropic_price_in' => (float) $s->anthropic_price_in,
            'anthropic_price_out' => (float) $s->anthropic_price_out,
            // Presence only — the raw keys are never exposed to the client.
            'has_gemini_key' => ($s->gemini_key ?? '') !== '',
            'has_openai_key' => ($s->openai_key ?? '') !== '',
            'has_anthropic_key' => ($s->anthropic_key ?? '') !== '',
        ];
    }

    /** AI usage + estimated cost (super-admin). days capped at 365. */
    public function aiUsage(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $days = min(max((int) $request->query('days', 30), 1), 365);
        $since = now()->subDays($days)->startOfDay();
        $settings = AiSetting::current();

        $rows = \App\Models\AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('provider, model, COUNT(*) as calls, SUM(input_tokens) as in_tok, SUM(output_tokens) as out_tok')
            ->groupBy('provider', 'model')
            ->get();

        $cost = function (string $provider, int $in, int $out) use ($settings): float {
            [$pin, $pout] = $settings->priceFor($provider);

            return ($in / 1_000_000) * $pin + ($out / 1_000_000) * $pout;
        };

        $byModel = $rows->map(function ($r) use ($cost) {
            return [
                'provider' => $r->provider,
                'model' => $r->model,
                'calls' => (int) $r->calls,
                'input_tokens' => (int) $r->in_tok,
                'output_tokens' => (int) $r->out_tok,
                'est_cost' => round($cost($r->provider, (int) $r->in_tok, (int) $r->out_tok), 2),
            ];
        })->sortByDesc('est_cost')->values();

        $byProvider = $rows->groupBy('provider')->map(function ($g, $provider) use ($cost) {
            $in = (int) $g->sum('in_tok');
            $out = (int) $g->sum('out_tok');

            return [
                'provider' => $provider,
                'calls' => (int) $g->sum('calls'),
                'input_tokens' => $in,
                'output_tokens' => $out,
                'est_cost' => round($cost($provider, $in, $out), 2),
            ];
        })->values();

        $daily = \App\Models\AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw("DATE(created_at) as date, provider, SUM(input_tokens) as in_tok, SUM(output_tokens) as out_tok")
            ->groupByRaw('DATE(created_at), provider')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(function ($g, $date) use ($cost) {
                $c = 0.0;
                foreach ($g as $r) {
                    $c += $cost($r->provider, (int) $r->in_tok, (int) $r->out_tok);
                }

                return ['date' => $date, 'est_cost' => round($c, 2)];
            })->values();

        return $this->ok([
            'usage' => [
                'days' => $days,
                'total_calls' => (int) $rows->sum('calls'),
                'total_input_tokens' => (int) $rows->sum('in_tok'),
                'total_output_tokens' => (int) $rows->sum('out_tok'),
                'total_est_cost' => round($byProvider->sum('est_cost'), 2),
                'by_provider' => $byProvider,
                'by_model' => $byModel,
                'daily' => $daily,
            ],
        ]);
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}

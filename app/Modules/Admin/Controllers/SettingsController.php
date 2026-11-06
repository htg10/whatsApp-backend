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
            // Presence only — the raw keys are never exposed to the client.
            'has_gemini_key' => ($s->gemini_key ?? '') !== '',
            'has_openai_key' => ($s->openai_key ?? '') !== '',
            'has_anthropic_key' => ($s->anthropic_key ?? '') !== '',
        ];
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}

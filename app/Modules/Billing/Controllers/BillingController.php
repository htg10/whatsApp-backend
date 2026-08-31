<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-side billing for a tenant: current plan/subscription, wallet balance and
 * ledger, available plans, and invoice history. Plan changes are recorded here;
 * real payment capture is delegated to a gateway (Razorpay/Stripe) as a
 * follow-up — this module never moves real money on its own.
 */
class BillingController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->authorize('billing.view');

        $tenant = $request->user()->tenant;
        $subscription = Subscription::with('plan')->latest('id')->first();
        $wallet = $this->walletFor($request);

        return $this->ok([
            'subscription' => $subscription ? $this->subscriptionArray($subscription) : null,
            'wallet' => $this->walletArray($wallet),
            'tenant' => [
                'status' => $tenant?->status,
                'trial_ends_at' => $tenant?->trial_ends_at?->toIso8601String(),
            ],
        ]);
    }

    public function plans(): JsonResponse
    {
        $this->authorize('billing.view');

        $plans = Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        return $this->ok([
            'plans' => $plans->map(fn (Plan $p) => $this->planArray($p)),
        ]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $this->authorize('billing.view');

        $wallet = $this->walletFor($request);
        $transactions = $wallet
            ? $wallet->transactions()->orderByDesc('id')->limit(50)->get()
            : collect();

        return $this->ok([
            'wallet' => $this->walletArray($wallet),
            'transactions' => $transactions->map(fn ($t) => [
                'id' => $t->uuid,
                'type' => $t->type,
                'amount' => $this->money($t->amount_minor),
                'amount_minor' => $t->amount_minor,
                'balance_after' => $this->money($t->balance_after_minor),
                'description' => $t->description,
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->authorize('billing.view');

        $invoices = Invoice::orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return $this->ok([
            'invoices' => $invoices->getCollection()->map(fn (Invoice $inv) => [
                'id' => $inv->uuid,
                'number' => $inv->number,
                'status' => $inv->status,
                'total' => $this->money($inv->total_minor),
                'currency' => $inv->currency,
                'issued_at' => $inv->issued_at?->toIso8601String(),
                'paid_at' => $inv->paid_at?->toIso8601String(),
                'due_at' => $inv->due_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * Assign / change the tenant's plan. Records the subscription against the
     * chosen plan; payment capture (if the plan is paid) is handled by the
     * gateway integration and is intentionally out of scope here.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,uuid'],
        ]);

        $plan = Plan::where('uuid', $data['plan_id'])->where('is_active', true)->firstOrFail();
        $tenantId = $request->user()->tenant_id;

        $subscription = Subscription::latest('id')->first();

        $attributes = [
            'plan_id' => $plan->id,
            'status' => $plan->price > 0 ? 'active' : 'active',
            'current_period_start' => now(),
            'current_period_end' => $plan->billing_period === 'yearly' ? now()->addYear() : now()->addMonth(),
        ];

        if ($subscription) {
            $subscription->update($attributes);
        } else {
            $subscription = Subscription::create(array_merge($attributes, [
                'tenant_id' => $tenantId,
            ]));
        }

        $subscription->load('plan');

        return $this->ok([
            'subscription' => $this->subscriptionArray($subscription),
        ]);
    }

    // ---- helpers ----

    private function walletFor(Request $request): ?Wallet
    {
        return Wallet::first(); // tenant-scoped by global scope; one wallet per tenant
    }

    private function walletArray(?Wallet $wallet): array
    {
        return [
            'balance' => $this->money($wallet?->balance_minor ?? 0),
            'balance_minor' => $wallet?->balance_minor ?? 0,
            'reserved' => $this->money($wallet?->reserved_minor ?? 0),
            'currency' => $wallet?->currency ?? 'INR',
            'auto_recharge' => (bool) ($wallet?->auto_recharge ?? false),
        ];
    }

    private function planArray(Plan $p): array
    {
        return [
            'id' => $p->uuid ?? (string) $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'billing_period' => $p->billing_period,
            'price' => (float) $p->price,
            'price_display' => $this->money((int) round($p->price * 100), $p->currency),
            'currency' => $p->currency,
            'trial_days' => $p->trial_days,
            'features' => $p->features ?? [],
            'limits' => $p->limits ?? [],
        ];
    }

    private function subscriptionArray(Subscription $s): array
    {
        return [
            'id' => $s->uuid,
            'status' => $s->status,
            'plan' => $s->plan ? $this->planArray($s->plan) : null,
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'current_period_start' => $s->current_period_start?->toIso8601String(),
            'current_period_end' => $s->current_period_end?->toIso8601String(),
            'cancelled_at' => $s->cancelled_at?->toIso8601String(),
        ];
    }

    /** Format minor units (paise) into a currency string like "₹1,250.00". */
    private function money(int $minor, string $currency = 'INR'): string
    {
        $symbol = $currency === 'INR' ? '₹' : ($currency === 'USD' ? '$' : $currency . ' ');
        return $symbol . number_format($minor / 100, 2);
    }
}

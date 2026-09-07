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
        $subscription = $this->applyPlan($plan, $request->user()->tenant_id);

        return $this->ok(['subscription' => $this->subscriptionArray($subscription)]);
    }

    /**
     * Start a checkout. Free plans are assigned immediately. Paid plans create a
     * Razorpay order and return the details the frontend needs to open checkout.
     */
    public function createOrder(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,uuid'],
        ]);

        $plan = Plan::where('uuid', $data['plan_id'])->where('is_active', true)->firstOrFail();

        // Free plan — no payment needed, assign right away.
        if ($plan->price <= 0) {
            $subscription = $this->applyPlan($plan, $request->user()->tenant_id);
            return $this->ok(['free' => true, 'subscription' => $this->subscriptionArray($subscription)]);
        }

        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        if (! $key || ! $secret) {
            return $this->fail('Online payments are not configured. Please contact support.', [], 503);
        }

        $amount = (int) round($plan->price * 100); // paise
        $response = \Illuminate\Support\Facades\Http::withBasicAuth($key, $secret)
            ->acceptJson()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amount,
                'currency' => $plan->currency ?: 'INR',
                'receipt' => 'plan_' . $plan->uuid . '_' . now()->timestamp,
                'notes' => ['plan' => $plan->name, 'tenant_id' => (string) $request->user()->tenant_id],
            ]);

        if ($response->failed()) {
            $msg = data_get($response->json(), 'error.description', 'Could not create the payment order.');
            return $this->fail('Razorpay: ' . $msg, [], 502);
        }

        return $this->ok([
            'free' => false,
            'order_id' => $response->json('id'),
            'amount' => $amount,
            'currency' => $plan->currency ?: 'INR',
            'key_id' => $key,
            'plan' => $this->planArray($plan),
        ]);
    }

    /**
     * Verify a completed Razorpay payment and, on success, assign the plan to the
     * tenant. Signature = HMAC-SHA256(order_id|payment_id, secret).
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        $this->authorize('billing.manage');

        $data = $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,uuid'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $secret = config('services.razorpay.secret');
        $expected = hash_hmac('sha256', $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'], (string) $secret);

        if (! hash_equals($expected, $data['razorpay_signature'])) {
            return $this->fail('Payment verification failed. If money was deducted it will be refunded automatically.', [], 422);
        }

        $plan = Plan::where('uuid', $data['plan_id'])->where('is_active', true)->firstOrFail();
        $subscription = $this->applyPlan($plan, $request->user()->tenant_id);

        return $this->ok([
            'message' => 'Payment successful — your plan is now active.',
            'subscription' => $this->subscriptionArray($subscription),
        ]);
    }

    /** Create or update the tenant's subscription to point at the given plan. */
    private function applyPlan(Plan $plan, int $tenantId): Subscription
    {
        $attributes = [
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => $plan->billing_period === 'yearly' ? now()->addYear() : now()->addMonth(),
        ];

        $subscription = Subscription::latest('id')->first();
        if ($subscription) {
            $subscription->update($attributes);
        } else {
            $subscription = Subscription::create(array_merge($attributes, ['tenant_id' => $tenantId]));
        }

        return $subscription->load('plan');
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

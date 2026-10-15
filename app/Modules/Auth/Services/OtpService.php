<?php

namespace App\Modules\Auth\Services;

use App\Models\LoginOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Issues and checks one-time login codes for mobile-OTP sign-in. Codes are 6
 * digits, valid for 5 minutes, limited to 5 attempts, with a resend cooldown.
 * To avoid leaking which numbers are registered, requesting a code always looks
 * like it succeeded.
 */
class OtpService
{
    private const TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 30;

    public function __construct(private readonly OtpSender $sender) {}

    /**
     * Generate and send a code. Returns ['dev_code' => string|null] — the code is
     * only echoed back in the local environment so developers can test.
     */
    public function request(string $rawPhone): array
    {
        $phone = $this->normalize($rawPhone);
        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => ['Enter a valid mobile number.']]);
        }

        $recent = LoginOtp::where('phone', $phone)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))
            ->exists();
        if ($recent) {
            throw ValidationException::withMessages(['phone' => ['Please wait a few seconds before requesting another code.']]);
        }

        $user = $this->findUserByPhone($phone);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        LoginOtp::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        // Only actually deliver when the number belongs to a real user.
        if ($user) {
            $this->sender->send($user, $phone, $code);
        }

        // Echo the code back only in local/testing so developers (and the test
        // suite) can complete the flow without a live SMS/WhatsApp channel.
        return ['dev_code' => app()->environment(['local', 'testing']) ? $code : null];
    }

    /** Verify a code; returns the matching user on success, or null. */
    public function verify(string $rawPhone, string $code): ?User
    {
        $phone = $this->normalize($rawPhone);

        $otp = LoginOtp::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return null;
        }

        $otp->update(['consumed_at' => now()]);

        return $this->findUserByPhone($phone);
    }

    /** Digits only; keeps the trailing 10 (local number) as the match key. */
    private function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /** The active user whose phone ends with these digits. */
    private function findUserByPhone(string $phone): ?User
    {
        if ($phone === '') {
            return null;
        }

        $base = User::withoutGlobalScopes()
            ->whereNotNull('phone')
            ->where('status', '!=', 'disabled');

        // MySQL can match on the stripped number directly (fast). Other drivers
        // (e.g. sqlite in tests) lack REGEXP_REPLACE, so match in PHP.
        if (DB::connection()->getDriverName() === 'mysql') {
            return (clone $base)
                ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = ?", [$phone])
                ->orderByDesc('last_login_at')
                ->first();
        }

        return (clone $base)
            ->orderByDesc('last_login_at')
            ->get()
            ->first(fn (User $u) => substr(preg_replace('/\D+/', '', (string) $u->phone), -10) === $phone);
    }
}

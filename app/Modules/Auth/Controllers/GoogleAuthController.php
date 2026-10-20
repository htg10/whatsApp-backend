<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\RegisterTenantAction;
use App\Modules\Auth\DTOs\RegisterData;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Google" — a manual OAuth 2.0 flow (no extra package).
 *
 * redirect()  → sends the browser to Google's consent screen.
 * callback()  → exchanges the code, finds-or-creates the user (auto-provisioning
 *               a workspace for a brand-new Google email), issues a JWT, and
 *               bounces back to the frontend with ?token=... .
 *
 * Mobile app (added; the website flow above is unchanged): the app opens redirect() with
 * ?app=1&challenge=<sha256 hex of a secret it keeps>. callback() then sends the person back to the app
 * (heltog://google-auth) with a one-time ?code=..., never with the token itself, and the app swaps
 * that code for a login with exchange(), proving it knows the secret (PKCE).
 *
 * Activates as soon as GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI
 * are set; until then it redirects back with a friendly error.
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly RegisterTenantAction $register,
        private readonly AuthService $auth,
    ) {
    }

    private const SCOPES = 'openid email profile';

    /** Where the mobile app is sent back to (the URL scheme registered by the app). */
    private const APP_RETURN = 'heltog://google-auth';

    public function redirect(Request $request): RedirectResponse
    {
        // Website: no parameters, exactly as before. App: ?app=1&challenge=<64 hex characters>.
        $mobile = $request->boolean('app');
        $challenge = (string) $request->query('challenge', '');

        if ($mobile && !preg_match('/^[a-f0-9]{64}$/', $challenge)) {
            return redirect($this->appUrl(['error' => 'bad_request']));
        }

        $clientId = config('services.google.client_id');
        $redirect = config('services.google.redirect');

        if (!$clientId || !$redirect) {
            return $this->bounce('not_configured', $mobile);
        }

        $state = Str::random(40);
        // The state remembers where the sign-in started: `true` for the website, an array for the app.
        Cache::put('google_oauth_state:' . $state, $mobile ? ['mobile' => true, 'challenge' => $challenge] : true, 600);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'select_account',
            'state' => $state,
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $meta = $state ? Cache::pull('google_oauth_state:' . $state) : null;
        $mobile = is_array($meta) && !empty($meta['mobile']);

        if ($request->has('error') || !$request->filled('code')) {
            return $this->bounce('cancelled', $mobile);
        }

        if (!$meta) {
            return $this->bounce('invalid_state', false);
        }

        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        $redirect = config('services.google.redirect');

        // Exchange the authorization code for tokens.
        $tokenRes = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->string('code'),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ]);

        if ($tokenRes->failed()) {
            return $this->bounce('token_exchange_failed', $mobile);
        }

        $accessToken = $tokenRes->json('access_token');
        $refreshToken = $tokenRes->json('refresh_token');

        // Fetch the Google profile.
        $profileRes = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');
        if ($profileRes->failed()) {
            return $this->bounce('profile_failed', $mobile);
        }

        $profile = $profileRes->json();
        $email = $profile['email'] ?? null;
        if (!$email) {
            return $this->bounce('no_email', $mobile);
        }

        $user = $this->findOrCreateUser($profile, $refreshToken);

        if ($user->status === 'disabled') {
            return $this->bounce('account_disabled', $mobile);
        }

        // The app never gets a token in the URL: it gets a one-time code (valid for 2 minutes) that only the app,
        // which holds the matching secret, can swap for a token through exchange().
        if ($mobile) {
            $code = Str::random(48);
            Cache::put('google_mobile_code:' . $code, ['user_id' => $user->id, 'challenge' => $meta['challenge']], 120);

            return redirect($this->appUrl(['code' => $code]));
        }

        $token = auth('api')->login($user);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect($this->frontend('/auth/callback', ['token' => $token]));
    }

    /**
     * Mobile app only. Swaps the one-time code for a normal login: the same answer as /auth/login.
     * The code works once, for two minutes, and only with the secret whose hash the app sent to redirect().
     */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'verifier' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        // pull() removes the code, so a wrong guess cannot be retried either.
        $saved = Cache::pull('google_mobile_code:' . $data['code']);
        if (!is_array($saved) || !hash_equals($saved['challenge'], hash('sha256', $data['verifier']))) {
            return $this->fail('Google sign-in expired. Please try again.', [], 422);
        }

        $user = User::withoutGlobalScopes()->find($saved['user_id']);
        if (!$user) {
            return $this->fail('Google sign-in expired. Please try again.', [], 422);
        }

        $result = $this->auth->loginViaUser($user);

        return $this->ok([
            'user' => new UserResource($result['user']->load('tenant')),
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_in' => $result['expires_in'],
        ]);
    }

    /** Match an existing user by Google id or email; otherwise provision a tenant. */
    private function findOrCreateUser(array $profile, ?string $refreshToken): User
    {
        $email = $profile['email'];
        $name = $profile['name'] ?? Str::before($email, '@');

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($user) {
            $user->forceFill([
                'google_id' => $profile['sub'] ?? $user->google_id,
                'avatar_url' => $user->avatar_url ?: ($profile['picture'] ?? null),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
            if ($refreshToken) {
                $user->google_refresh_token = $refreshToken; // keep for future Google Business Profile use
            }
            $user->saveQuietly();

            return $user;
        }

        // Brand-new Google user → provision a workspace with a random password.
        $user = $this->register->execute(new RegisterData(
            companyName: $name . "'s workspace",
            name: $name,
            email: $email,
            password: Str::random(32),
        ));

        $user->forceFill([
            'google_id' => $profile['sub'] ?? null,
            'google_refresh_token' => $refreshToken,
            'avatar_url' => $profile['picture'] ?? null,
            'email_verified_at' => now(),
        ])->saveQuietly();

        // Restore super-admin auth context cleared by the provisioning action.
        auth('api')->logout();

        return $user;
    }

    /** Send the person back to the app (mobile) or to the website login (web) with a short error code. */
    private function bounce(string $error, bool $mobile): RedirectResponse
    {
        return redirect($mobile
            ? $this->appUrl(['error' => $error])
            : $this->frontend('/login', ['google_error' => $error]));
    }

    /** heltog://google-auth?code=... or ?error=... */
    private function appUrl(array $query): string
    {
        return self::APP_RETURN . '?' . http_build_query($query);
    }

    /** Build a frontend URL with query params. */
    private function frontend(string $path, array $query = []): string
    {
        $base = rtrim((string) config('services.google.frontend_url'), '/');
        $qs = $query ? '?' . http_build_query($query) : '';

        return $base . $path . '/' . $qs;
    }
}

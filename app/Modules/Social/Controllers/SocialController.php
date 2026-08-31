<?php

namespace App\Modules\Social\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Modules\Social\Services\MetaSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SocialController extends Controller
{
    public function __construct(private readonly MetaSocialService $meta) {}

    // ---- connection ----

    public function connection(Request $request): JsonResponse
    {
        $this->authorize('campaigns.view');

        $conn = SocialConnection::first();

        return $this->ok(['connection' => $conn ? $this->connectionArray($conn) : null]);
    }

    public function connect(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage'); // account connection is admin-only

        $data = $request->validate([
            'page_id' => ['required', 'string', 'max:64'],
            'page_access_token' => ['required', 'string'],
        ]);

        // Validate the token against the Graph API and pull page + IG info.
        try {
            $info = $this->meta->inspectPage($data['page_id'], $data['page_access_token']);
        } catch (\Throwable $e) {
            return $this->fail('Could not verify the Facebook Page: ' . $e->getMessage(), [], 422);
        }

        $conn = SocialConnection::first();
        $attributes = [
            'page_id' => $data['page_id'],
            'page_name' => $info['name'],
            'page_access_token' => $data['page_access_token'],
            'ig_user_id' => $info['ig_user_id'],
            'ig_username' => $info['ig_username'],
            'status' => 'connected',
        ];

        if ($conn) {
            $conn->update($attributes);
        } else {
            $conn = SocialConnection::create(array_merge($attributes, ['tenant_id' => $request->user()->tenant_id]));
        }

        return $this->ok(['connection' => $this->connectionArray($conn->fresh())]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        SocialConnection::query()->delete();

        return $this->ok(['message' => 'Disconnected.']);
    }

    // ---- posts ----

    public function posts(Request $request): JsonResponse
    {
        $this->authorize('campaigns.view');

        $posts = SocialPost::orderByDesc('id')->limit(50)->get();

        return $this->ok(['posts' => $posts->map(fn (SocialPost $p) => $this->postArray($p))]);
    }

    public function createPost(Request $request): JsonResponse
    {
        $this->authorize('campaigns.create');

        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:2200'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image' => ['nullable', 'image', 'max:8192'], // 8 MB
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['string', 'in:facebook,instagram'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $conn = SocialConnection::first();
        if (! $conn) {
            return $this->fail('Connect a Facebook Page first.', [], 422);
        }

        // Resolve a public image URL (uploaded file or pasted URL).
        $imageUrl = $data['image_url'] ?? null;
        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('social/' . $request->user()->tenant_id, 'public');
            $imageUrl = rtrim((string) config('app.url'), '/') . '/storage/' . $imagePath;
        }

        if (! $imageUrl) {
            return $this->fail('A photo is required — upload one or paste a public image URL.', [], 422);
        }

        $post = SocialPost::create([
            'tenant_id' => $request->user()->tenant_id,
            'caption' => $data['caption'] ?? null,
            'image_url' => $imageUrl,
            'image_path' => $imagePath,
            'targets' => array_values(array_unique($data['targets'])),
            'status' => isset($data['scheduled_at']) ? 'scheduled' : 'draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Publish immediately unless scheduled for later.
        if (! isset($data['scheduled_at'])) {
            $this->publishPost($post, $conn);
        }

        return $this->ok(['post' => $this->postArray($post->fresh())], 201);
    }

    public function publish(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('campaigns.create');

        $post = SocialPost::where('uuid', $uuid)->firstOrFail();
        $conn = SocialConnection::first();
        if (! $conn) {
            return $this->fail('Connect a Facebook Page first.', [], 422);
        }

        $this->publishPost($post, $conn);

        return $this->ok(['post' => $this->postArray($post->fresh())]);
    }

    // ---- publishing core ----

    private function publishPost(SocialPost $post, SocialConnection $conn): void
    {
        $results = [];
        $ok = 0;
        $fail = 0;

        if (in_array('facebook', $post->targets, true)) {
            try {
                $id = $this->meta->publishFacebook($conn->page_id, $conn->page_access_token, $post->image_url, $post->caption);
                $results['facebook'] = ['status' => 'published', 'id' => $id];
                $ok++;
            } catch (\Throwable $e) {
                $results['facebook'] = ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 300)];
                $fail++;
            }
        }

        if (in_array('instagram', $post->targets, true)) {
            if (! $conn->ig_user_id) {
                $results['instagram'] = ['status' => 'failed', 'error' => 'No Instagram Business account is linked to this Facebook Page.'];
                $fail++;
            } else {
                try {
                    $id = $this->meta->publishInstagram($conn->ig_user_id, $conn->page_access_token, $post->image_url, $post->caption);
                    $results['instagram'] = ['status' => 'published', 'id' => $id];
                    $ok++;
                } catch (\Throwable $e) {
                    $results['instagram'] = ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 300)];
                    $fail++;
                }
            }
        }

        $post->update([
            'status' => $fail === 0 ? 'published' : ($ok > 0 ? 'partial' : 'failed'),
            'results' => $results,
            'published_at' => $ok > 0 ? now() : $post->published_at,
        ]);
    }

    // ---- serialization ----

    private function connectionArray(SocialConnection $c): array
    {
        return [
            'id' => $c->uuid,
            'page_id' => $c->page_id,
            'page_name' => $c->page_name,
            'ig_user_id' => $c->ig_user_id,
            'ig_username' => $c->ig_username,
            'instagram_linked' => (bool) $c->ig_user_id,
            'status' => $c->status,
            'connected_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function postArray(SocialPost $p): array
    {
        return [
            'id' => $p->uuid,
            'caption' => $p->caption,
            'image_url' => $p->image_url,
            'targets' => $p->targets,
            'status' => $p->status,
            'results' => $p->results,
            'scheduled_at' => $p->scheduled_at?->toIso8601String(),
            'published_at' => $p->published_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}

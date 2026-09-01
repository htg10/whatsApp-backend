<?php

namespace App\Modules\Social\Services;

use Illuminate\Support\Facades\Http;

/**
 * Publishes photo posts to a Facebook Page and/or its linked Instagram Business
 * account via the Meta Graph API. Images must be reachable by Meta as a public
 * https URL. The Page access token is used for both surfaces.
 */
class MetaSocialService
{
    private string $base;

    public function __construct()
    {
        $version = config('services.meta.api_version', 'v23.0');
        $graph = rtrim((string) config('services.meta.graph_base', 'https://graph.facebook.com'), '/');
        $this->base = "{$graph}/{$version}";
    }

    /** Verify a Page token and read the Page name + linked IG business account. */
    public function inspectPage(string $pageId, string $token): array
    {
        $res = Http::timeout(30)->get("{$this->base}/{$pageId}", [
            'fields' => 'name,instagram_business_account{id,username}',
            'access_token' => $token,
        ]);

        $this->guard($res, 'Could not read the Facebook Page');

        $data = $res->json();
        return [
            'name' => $data['name'] ?? null,
            'ig_user_id' => $data['instagram_business_account']['id'] ?? null,
            'ig_username' => $data['instagram_business_account']['username'] ?? null,
        ];
    }

    /** Publish a photo to the Facebook Page. Returns the new post id. */
    public function publishFacebook(string $pageId, string $token, string $imageUrl, ?string $caption): string
    {
        $res = Http::timeout(60)->asForm()->post("{$this->base}/{$pageId}/photos", [
            'url' => $imageUrl,
            'caption' => (string) $caption,
            'access_token' => $token,
        ]);

        $this->guard($res, 'Facebook publish failed');

        $data = $res->json();
        return (string) ($data['post_id'] ?? $data['id'] ?? '');
    }

    /** Publish a photo to Instagram (create container, then publish). */
    public function publishInstagram(string $igUserId, string $token, string $imageUrl, ?string $caption): string
    {
        $create = Http::timeout(60)->asForm()->post("{$this->base}/{$igUserId}/media", [
            'image_url' => $imageUrl,
            'caption' => (string) $caption,
            'access_token' => $token,
        ]);
        $this->guard($create, 'Instagram media creation failed');

        $creationId = $create->json('id');
        if (! $creationId) {
            throw new \RuntimeException('Instagram media creation returned no id.');
        }

        $publish = Http::timeout(60)->asForm()->post("{$this->base}/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $token,
        ]);
        $this->guard($publish, 'Instagram publish failed');

        return (string) $publish->json('id');
    }

    /** Publish a video to the Facebook Page. Returns the new video id. */
    public function publishFacebookVideo(string $pageId, string $token, string $videoUrl, ?string $caption): string
    {
        $res = Http::timeout(120)->asForm()->post("{$this->base}/{$pageId}/videos", [
            'file_url' => $videoUrl,
            'description' => (string) $caption,
            'access_token' => $token,
        ]);
        $this->guard($res, 'Facebook video publish failed');

        return (string) ($res->json('id') ?? '');
    }

    /**
     * Publish a Reel to Instagram: create a REELS container, wait for Meta to
     * finish processing the video, then publish.
     */
    public function publishInstagramVideo(string $igUserId, string $token, string $videoUrl, ?string $caption): string
    {
        $create = Http::timeout(120)->asForm()->post("{$this->base}/{$igUserId}/media", [
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'caption' => (string) $caption,
            'access_token' => $token,
        ]);
        $this->guard($create, 'Instagram reel creation failed');

        $creationId = $create->json('id');
        if (! $creationId) {
            throw new \RuntimeException('Instagram reel creation returned no id.');
        }

        // Poll processing status (videos take time). ~90s max.
        $status = 'IN_PROGRESS';
        for ($i = 0; $i < 18; $i++) {
            sleep(5);
            $check = Http::timeout(30)->get("{$this->base}/{$creationId}", [
                'fields' => 'status_code',
                'access_token' => $token,
            ]);
            $status = $check->json('status_code') ?? $status;
            if ($status === 'FINISHED') {
                break;
            }
            if ($status === 'ERROR') {
                throw new \RuntimeException('Instagram could not process the video.');
            }
        }

        if ($status !== 'FINISHED') {
            throw new \RuntimeException('Instagram is still processing the video — try Publish now again in a minute.');
        }

        $publish = Http::timeout(60)->asForm()->post("{$this->base}/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $token,
        ]);
        $this->guard($publish, 'Instagram reel publish failed');

        return (string) $publish->json('id');
    }

    /** Turn a Graph API error response into a clean exception message. */
    private function guard(\Illuminate\Http\Client\Response $res, string $context): void
    {
        if ($res->successful()) {
            return;
        }
        $err = $res->json('error.message') ?? $res->body();
        throw new \RuntimeException("{$context}: {$err}");
    }
}

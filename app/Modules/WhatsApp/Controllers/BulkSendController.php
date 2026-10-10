<?php

namespace App\Modules\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BulkSend;
use App\Modules\WhatsApp\Services\BulkSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkSendController extends Controller
{
    public function __construct(private readonly BulkSendService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $sends = BulkSend::with('phoneNumber')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        foreach ($sends->items() as $send) {
            if ($this->service->claimIfStalled($send)) {
                $this->service->continueAfterResponse($send);
            }
        }

        return $this->ok([
            'bulk_sends' => $sends->items(),
            'meta' => [
                'current_page' => $sends->currentPage(),
                'last_page' => $sends->lastPage(),
                'total' => $sends->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $send = BulkSend::where('uuid', $uuid)->firstOrFail();

        if ($this->service->claimIfStalled($send)) {
            $this->service->continueAfterResponse($send);
        }

        return $this->ok(['bulk_send' => $send->load(['phoneNumber', 'recipients'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $data = $request->validate([
            'numbers' => ['required', 'array', 'min:1', 'max:1000'],
            'numbers.*' => ['required', 'string', 'max:20'],
            'template' => ['required', 'string', 'max:512'],
            'language' => ['nullable', 'string', 'max:16'],
            // Ordered BODY variable values ({{1}}, {{2}}, …). Same values go to
            // every recipient (bulk send is one message to many numbers).
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*' => ['nullable', 'string', 'max:1024'],
            // Public URL for templates whose HEADER is an image/video/document.
            'header_media_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $components = [];

        // A media HEADER (image/video/document) must be supplied at send time, or
        // Meta rejects every message — the cause of "request could not be completed".
        $headerFormat = $this->templateHeaderFormat(
            $request->user()->tenant_id, $data['template'], $data['language'] ?? null
        );
        if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            if (empty($data['header_media_url'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'header_media_url' => ['This template has an ' . strtolower($headerFormat)
                        . ' header. Please provide a public ' . strtolower($headerFormat) . ' URL to send it.'],
                ]);
            }
            $mediaKey = strtolower($headerFormat); // image | video | document
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => $mediaKey,
                    $mediaKey => ['link' => $data['header_media_url']],
                ]],
            ];
        }

        // Build the Meta template "components" payload for body variables.
        $variables = $data['variables'] ?? [];
        if (! empty($variables)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    array_values($variables)
                ),
            ];
        }

        $result = $this->service->send(
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            numbers: $data['numbers'],
            template: $data['template'],
            language: $data['language'] ?? 'en_US',
            components: $components,
        );

        return $this->ok(['bulk_send' => $result], 201);
    }

    /** HEADER format (TEXT/IMAGE/VIDEO/DOCUMENT) of a template, or null if none. */
    private function templateHeaderFormat(int $tenantId, string $name, ?string $language): ?string
    {
        $template = \App\Models\Template::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->when($language, fn ($q) => $q->where('language', $language))
            ->with('components')
            ->first();

        if (! $template) {
            return null;
        }

        $header = $template->components->firstWhere('type', 'HEADER');

        return $header?->format ? strtoupper($header->format) : null;
    }
}

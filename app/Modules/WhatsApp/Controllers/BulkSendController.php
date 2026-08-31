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

        $send = BulkSend::where('uuid', $uuid)
            ->with(['phoneNumber', 'recipients'])
            ->firstOrFail();

        return $this->ok(['bulk_send' => $send]);
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
        ]);

        // Build the Meta template "components" payload for body variables.
        $components = [];
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
}

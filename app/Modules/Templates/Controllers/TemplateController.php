<?php

namespace App\Modules\Templates\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\WhatsappBusinessAccount;
use App\Modules\Templates\Resources\TemplateResource;
use App\Modules\WhatsApp\Services\WhatsAppProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(private readonly WhatsAppProviderFactory $factory) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $query = Template::with('businessAccount')->orderBy('name');

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($category = $request->query('category')) {
            $query->where('category', strtoupper($category));
        }

        $templates = $query->paginate($request->integer('per_page', 50));

        return $this->ok([
            'templates' => TemplateResource::collection($templates),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'total' => $templates->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('whatsapp.view');

        $template = Template::where('uuid', $uuid)
            ->with(['businessAccount', 'components'])
            ->firstOrFail();

        return $this->ok(['template' => new TemplateResource($template)]);
    }

    public function sync(Request $request): JsonResponse
    {
        $this->authorize('whatsapp.manage');

        $tenantId = $request->user()->tenant_id;
        $accounts = WhatsappBusinessAccount::where('tenant_id', $tenantId)->get();

        if ($accounts->isEmpty()) {
            return $this->ok(['message' => 'No WhatsApp accounts to sync.', 'synced' => 0]);
        }

        $synced = 0;

        foreach ($accounts as $waba) {
            try {
                $provider = $this->factory->for($waba);
                $metaTemplates = $provider->getTemplates($waba->waba_id);

                foreach ($metaTemplates['data'] ?? [] as $mt) {
                    $template = Template::updateOrCreate(
                        [
                            'whatsapp_business_account_id' => $waba->id,
                            'name' => $mt['name'],
                            'language' => $mt['language'],
                        ],
                        [
                            'tenant_id' => $tenantId,
                            'meta_template_id' => $mt['id'] ?? null,
                            'category' => $mt['category'] ?? 'UTILITY',
                            'status' => $mt['status'] ?? 'PENDING',
                            'rejection_reason' => $mt['rejected_reason'] ?? null,
                            'quality_score' => $mt['quality_score']['score'] ?? null,
                            'raw' => $mt,
                            'last_synced_at' => now(),
                        ],
                    );

                    $template->components()->delete();
                    foreach ($mt['components'] ?? [] as $idx => $comp) {
                        $template->components()->create([
                            'tenant_id' => $tenantId,
                            'type' => $comp['type'],
                            'format' => $comp['format'] ?? null,
                            'text' => $comp['text'] ?? null,
                            'example' => $comp['example'] ?? null,
                            'buttons' => $comp['buttons'] ?? null,
                            'sort_order' => $idx,
                        ]);
                    }

                    $synced++;
                }
            } catch (\Throwable $e) {
                \Log::warning('template.sync.failed', ['waba' => $waba->waba_id, 'error' => $e->getMessage()]);
            }
        }

        return $this->ok(['message' => "Synced {$synced} templates from Meta.", 'synced' => $synced]);
    }
}

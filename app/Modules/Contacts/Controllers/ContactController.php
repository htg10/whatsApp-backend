<?php

namespace App\Modules\Contacts\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Modules\Contacts\Resources\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('contacts.view');

        $query = Contact::with(['tags', 'assignedAgent'])
            ->orderByDesc('last_interaction_at')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('wa_id', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('tag_list', 'like', "%{$search}%");
            });
        }

        if ($tag = $request->query('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.uuid', $tag));
        }

        if ($request->query('hot') === '1') {
            $query->where('is_hot', true)->reorder()->orderByDesc('hot_at');
        }

        if ($request->query('blocked') === '1') {
            $query->where('is_blocked', true);
        }

        if ($request->query('opted_out') === '1') {
            $query->where('opted_out', true);
        }

        $contacts = $query->paginate($request->integer('per_page', 25));

        return $this->ok([
            'contacts' => ContactResource::collection($contacts),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'total' => $contacts->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('contacts.view');

        $contact = Contact::where('uuid', $uuid)
            ->with(['tags', 'assignedAgent', 'conversations'])
            ->firstOrFail();

        return $this->ok(['contact' => new ContactResource($contact)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('contacts.create');

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:4'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'tag_list' => ['nullable', 'string', 'max:500'],   // e.g. "VIP" or "VIP, Delhi"
        ]);

        $tenantId = $request->user()->tenant_id;
        app(\App\Modules\Billing\Services\PlanLimitService::class)->assertWithinLimit($tenantId, 'max_contacts', 'contacts', 'contacts');
        $waId = preg_replace('/\D/', '', $data['phone']);

        $contact = Contact::create([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'phone' => $data['phone'],
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'company' => $data['company'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'language' => $data['language'] ?? null,
            'country' => $data['country'] ?? null,
            'tag_list' => $this->tagText($data),
        ]);

        return $this->ok(['contact' => new ContactResource($contact->load('assignedAgent'))], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('contacts.update');

        $contact = Contact::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:4'],
            'is_blocked' => ['nullable', 'boolean'],
            'opted_out' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'tag_list' => ['nullable', 'string', 'max:500'],
        ]);

        $contact->update(collect($data)->except(['tags', 'tag_list'])->filter(fn ($v) => $v !== null)->all());

        // tag_list is saved directly in the contacts table (empty text clears it)
        if (array_key_exists('tags', $data) || array_key_exists('tag_list', $data)) {
            $contact->update(['tag_list' => $this->tagText($data)]);
        }

        return $this->ok(['contact' => new ContactResource($contact->fresh(['assignedAgent']))]);
    }

    /** Add / remove a contact from the Hot List by hand. */
    public function setHot(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('contacts.update');

        $data = $request->validate([
            'is_hot' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $contact = Contact::where('uuid', $uuid)->firstOrFail();

        if ($data['is_hot']) {
            $contact->forceFill([
                'is_hot' => true,
                'hot_reason' => $data['reason'] ?? ($contact->hot_reason ?: 'Marked hot manually'),
                'hot_at' => now(),
                'hot_source' => 'manual',
            ])->save();
        } else {
            $contact->forceFill([
                'is_hot' => false,
                'hot_reason' => null,
                'hot_at' => null,
                'hot_source' => null,
            ])->save();
        }

        return $this->ok(['contact' => new ContactResource($contact->fresh(['assignedAgent']))]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('contacts.delete');

        $contact = Contact::where('uuid', $uuid)->firstOrFail();
        $contact->delete();

        return $this->ok(['message' => 'Contact deleted.']);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorize('contacts.import');

        $data = $request->validate([
            'contacts' => ['required', 'array', 'min:1', 'max:5000'],
            'contacts.*.phone' => ['required', 'string', 'max:20'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.company' => ['nullable', 'string', 'max:255'],
            'contacts.*.tags' => ['nullable', 'array'],
            'contacts.*.tags.*' => ['string'],
            'contacts.*.tag_list' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = $request->user()->tenant_id;
        app(\App\Modules\Billing\Services\PlanLimitService::class)->assertWithinLimit($tenantId, 'max_contacts', 'contacts', 'contacts');
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($data['contacts'] as $row) {
            $waId = preg_replace('/\D/', '', $row['phone']);
            if (strlen($waId) < 10) {
                $skipped++;
                continue;
            }

            $contact = Contact::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('wa_id', $waId)
                ->first();
                

            if ($contact) {
                if ($contact->trashed()) {
                    $contact->restore();
                }
                $contact->update(array_filter([
                    'name' => $row['name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'company' => $row['company'] ?? null,
                ], fn ($v) => $v !== null));
                $updated++;
            } else {
                $contact = Contact::create([
                    'tenant_id' => $tenantId,
                    'wa_id' => $waId,
                    'phone' => $row['phone'],
                    'name' => $row['name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'company' => $row['company'] ?? null,
                    'source' => 'import',
                ]);
                $created++;
            }

            // save the tag text straight into contacts.tag_list
            $tagText = $this->tagText($row);
            if ($tagText !== null) {
                $contact->update(['tag_list' => $tagText]);
            }
        }

        return $this->ok([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($data['contacts']),
        ]);
    }

    /**
     * Tag text for contacts.tag_list from `tag_list` (text) or `tags` (array).
     * Returns null when nothing was sent / it is empty.
     * Example: "vip | Delhi;lead" => "vip, Delhi, lead"
     */
    private function tagText(array $data): ?string
    {
        $raw = $data['tag_list'] ?? ($data['tags'] ?? null);
        if (is_array($raw)) {
            $raw = implode(',', $raw);
        }
        if ($raw === null) {
            return null;
        }

        $text = collect(preg_split('/[,;|]/', (string) $raw))
            ->map(fn ($n) => trim($n))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->implode(', ');

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
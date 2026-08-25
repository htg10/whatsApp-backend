<?php

namespace App\Modules\Contacts\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Modules\Contacts\Resources\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($tag = $request->query('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.uuid', $tag));
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
        ]);

        $tenantId = $request->user()->tenant_id;
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
        ]);

        if (!empty($data['tags'])) {
            $this->syncTags($contact, $data['tags'], $tenantId);
        }

        return $this->ok(['contact' => new ContactResource($contact->load('tags'))], 201);
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
        ]);

        $contact->update(collect($data)->except('tags')->filter(fn ($v) => $v !== null)->all());

        if (array_key_exists('tags', $data)) {
            $this->syncTags($contact, $data['tags'] ?? [], $request->user()->tenant_id);
        }

        return $this->ok(['contact' => new ContactResource($contact->fresh(['tags', 'assignedAgent']))]);
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
        ]);

        $tenantId = $request->user()->tenant_id;
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

            if (!empty($row['tags'])) {
                $this->syncTags($contact, $row['tags'], $tenantId);
            }
        }

        return $this->ok([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($data['contacts']),
        ]);
    }

    private function syncTags(Contact $contact, array $tagNames, int $tenantId): void
    {
        $tagIds = [];
        foreach ($tagNames as $name) {
            $tag = \App\Models\Tag::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => trim($name)],
                ['color' => '#' . substr(md5($name), 0, 6)],
            );
            $tagIds[] = $tag->id;
        }
        $contact->tags()->sync($tagIds);
    }
}

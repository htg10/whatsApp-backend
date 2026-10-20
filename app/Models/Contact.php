<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes, TracksBlame;

    protected $fillable = [
        'tenant_id', 'wa_id', 'phone', 'name', 'email', 'company', 'tag_list', 'source',
        'lead_status_id', 'assigned_agent_id', 'language', 'country',
        'is_blocked', 'opted_out', 'last_interaction_at', 'meta',
    ];

    // NOTE: tag_list is a plain varchar column (text), so it must NOT be cast to array
    protected $casts = [
        'is_blocked' => 'boolean',
        'opted_out' => 'boolean',
        'last_interaction_at' => 'datetime',
        'meta' => 'array',
    ];

    /** A contact is on the Hot List when the AI has added this tag to contacts.tag_list. */
    public const HOT_TAG = 'Hot';

    /** Tag names from the plain-text tag_list column ("Hot, Delhi" => ["Hot", "Delhi"]). */
    public function tagNames(): array
    {
        return collect(preg_split('/[,;|]/', (string) $this->tag_list))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->values()
            ->all();
    }

    public function getIsHotAttribute(): bool
    {
        return collect($this->tagNames())
            ->contains(fn ($t) => mb_strtolower($t) === mb_strtolower(self::HOT_TAG));
    }

    /** Priority score the AI gave (1-100, higher = handle first). Older hot tags without a score count as 50. */
    public function getHotScoreAttribute(): int
    {
        if (! $this->is_hot) {
            return 0;
        }

        $score = (int) ($this->meta['hot']['score'] ?? 0);

        return $score > 0 ? min($score, 100) : 50;
    }

    /**
     * SQL: 0 for a normal contact, otherwise the hot priority score (1-100).
     * Used to put hot chats / hot contacts first. $t = the contacts table alias.
     * (No user input is ever placed in this string.)
     */
    public static function hotPrioritySql(string $t = 'contacts'): string
    {
        $isHot = "CONCAT(',', REPLACE(REPLACE(COALESCE({$t}.tag_list, ''), ', ', ','), ' ', ''), ',') LIKE '%," . self::HOT_TAG . ",%'";
        $score = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$t}.meta, '$.hot.score')), 0) AS UNSIGNED)";

        return "CASE WHEN {$isHot} THEN COALESCE(NULLIF({$score}, 0), 50) ELSE 0 END";
    }

    /**
     * Scope: contacts that carry $tag in the plain-text tag_list column.
     *
     * Contact tags live in tag_list ("VIP, New Delhi"), NOT in the contact_tag pivot table, so the old
     * whereHas('tags') never matched anyone. This compares whole tags only (the same normalisation the Hot list
     * uses: separators become commas, spaces are dropped), so "VIP" never matches a name or a phone number.
     * $table = the contacts table alias.
     */
    public function scopeWithTag(Builder $query, string $tag, string $table = 'contacts'): Builder
    {
        $needle = str_replace([' ', ',', ';', '|'], '', trim($tag));
        if ($needle === '') {
            return $query->whereRaw('1 = 0');
        }

        $normalised = "CONCAT(',', REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$table}.tag_list, ''), ', ', ','), ';', ','), '|', ','), ' ', ''), ',')";

        return $query->whereRaw("{$normalised} LIKE ?", ['%,' . addcslashes($needle, '%_\\') . ',%']);
    }

    /** Scope: contacts that carry at least one of $tags (empty list = no restriction). */
    public function scopeWithAnyTag(Builder $query, array $tags, string $table = 'contacts'): Builder
    {
        $tags = array_values(array_filter(array_map('strval', $tags), fn ($t) => trim($t) !== ''));
        if ($tags === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($tags, $table) {
            foreach ($tags as $tag) {
                $q->orWhere(fn (Builder $inner) => $inner->withTag($tag, $table));
            }
        });
    }

    /** Why the AI marked this contact hot (stored in meta.hot). */
    public function getHotReasonAttribute(): ?string
    {
        return $this->meta['hot']['reason'] ?? null;
    }

    public function getHotAtAttribute(): ?string
    {
        return $this->meta['hot']['at'] ?? null;
    }

    public function leadStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag')->withTimestamps();
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(ContactCustomFieldValue::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}

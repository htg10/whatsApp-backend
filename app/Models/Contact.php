<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
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
        'tenant_id',
        'wa_id',
        'phone',
        'name',
        'email',
        'company',
        'tag_list',
        'source',
        'lead_status_id',
        'assigned_agent_id',
        'language',
        'country',
        'is_blocked',
        'opted_out',
        'last_interaction_at',
        'meta',
        'is_hot',
        'hot_reason',
        'hot_at',
        'hot_source',
    ];

    // NOTE: tag_list is a plain varchar column (text), so it must NOT be cast to array
    protected $casts = [
        'is_blocked' => 'boolean',
        'opted_out' => 'boolean',
        'is_hot' => 'boolean',
        'hot_at' => 'datetime',
        'last_interaction_at' => 'datetime',
        'meta' => 'array',
    ];

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
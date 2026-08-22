<?php

namespace App\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TracksBlame;
use App\Support\Scopes\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use BelongsToTenant, HasFactory, HasUuid, SoftDeletes, TracksBlame;

    protected $fillable = [
        'tenant_id', 'whatsapp_business_account_id', 'meta_template_id',
        'name', 'language', 'category', 'status', 'rejection_reason',
        'quality_score', 'raw', 'last_synced_at',
    ];

    protected $casts = [
        'raw' => 'array',
        'last_synced_at' => 'datetime',
    ];

    /** Meta is the source of truth — approved only if Meta says APPROVED. */
    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappBusinessAccount::class, 'whatsapp_business_account_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(TemplateComponent::class);
    }
}

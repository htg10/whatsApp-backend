<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One AI API call's token usage. Not tenant-scoped (super-admin analytics);
 * tenant_id is stored for optional per-company breakdowns. No updated_at.
 */
class AiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'provider', 'model', 'kind', 'input_tokens', 'output_tokens',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];
}

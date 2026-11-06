<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform AI-provider settings. Singleton — one row, managed by the Super Admin.
 * Decides which provider the chatbot uses and holds the per-provider API keys
 * (encrypted at rest). When a key is blank, the service falls back to .env.
 */
class AiSetting extends Model
{
    public const PROVIDERS = ['gemini', 'openai', 'anthropic'];

    protected $fillable = [
        'provider', 'enabled',
        'gemini_key', 'openai_key', 'anthropic_key',
        'gemini_model', 'openai_model', 'anthropic_model',
        'gemini_price_in', 'gemini_price_out',
        'openai_price_in', 'openai_price_out',
        'anthropic_price_in', 'anthropic_price_out',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'gemini_price_in' => 'float', 'gemini_price_out' => 'float',
        'openai_price_in' => 'float', 'openai_price_out' => 'float',
        'anthropic_price_in' => 'float', 'anthropic_price_out' => 'float',
        // Never stored or exposed in plain text.
        'gemini_key' => 'encrypted',
        'openai_key' => 'encrypted',
        'anthropic_key' => 'encrypted',
    ];

    /** The single settings row, created with defaults on first access. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['provider' => 'gemini', 'enabled' => true]);
    }

    /** The configured API key for a provider, or null. */
    public function keyFor(string $provider): ?string
    {
        return match ($provider) {
            'gemini' => $this->gemini_key,
            'openai' => $this->openai_key,
            'anthropic' => $this->anthropic_key,
            default => null,
        };
    }

    /** The configured model override for a provider, or null. */
    public function modelFor(string $provider): ?string
    {
        return match ($provider) {
            'gemini' => $this->gemini_model,
            'openai' => $this->openai_model,
            'anthropic' => $this->anthropic_model,
            default => null,
        };
    }

    /** [input ₹/1M, output ₹/1M] for a provider. */
    public function priceFor(string $provider): array
    {
        return match ($provider) {
            'gemini' => [(float) $this->gemini_price_in, (float) $this->gemini_price_out],
            'openai' => [(float) $this->openai_price_in, (float) $this->openai_price_out],
            'anthropic' => [(float) $this->anthropic_price_in, (float) $this->anthropic_price_out],
            default => [0.0, 0.0],
        };
    }
}

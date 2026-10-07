<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $defaults = config('agent_audit.default_settings', []);
        $fallback = array_key_exists($key, $defaults) ? $defaults[$key] : $default;
        return Cache::remember("setting:{$key}", 60, fn () => Setting::where('key', $key)->value('value') ?? $fallback);
    }

    public function bool(string $key, bool $default = false): bool { return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOL); }
    public function int(string $key, int $default = 0): int { return (int) $this->get($key, $default); }
    public function float(string $key, float $default = 0): float { return (float) $this->get($key, $default); }

    public function set(string $key, mixed $value, ?string $group = null, ?string $description = null): Setting
    {
        $setting = Setting::updateOrCreate(['key'=>$key], ['value'=>$value,'group'=>$group,'description'=>$description]);
        Cache::forget("setting:{$key}");
        return $setting;
    }

    public function seedDefaults(): void
    {
        foreach (config('agent_audit.default_settings', []) as $key => $value) {
            Setting::firstOrCreate(['key'=>$key], ['value'=>$value,'group'=>str($key)->before('.')->toString()]);
        }
    }
}

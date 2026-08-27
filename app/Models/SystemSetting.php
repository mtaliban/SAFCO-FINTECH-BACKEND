<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type', 'label', 'description'];

    /**
     * Return the cast value of a setting by key.
     * Falls back to $default if the key does not exist in the database.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return $setting->castValue();
    }

    /**
     * Persist (create or update) a setting value by key.
     */
    public static function set(string $key, mixed $value): static
    {
        $setting = static::firstOrNew(['key' => $key]);
        $setting->value = is_array($value) ? json_encode($value) : (string) $value;
        $setting->save();

        return $setting;
    }

    /**
     * Return the value cast according to the record's `type` field.
     */
    public function castValue(): mixed
    {
        return match ($this->type) {
            'boolean' => (bool) (int) $this->value,
            'integer' => (int) $this->value,
            'json'    => json_decode($this->value, true),
            default   => $this->value, // string
        };
    }

    /**
     * Append the cast value to serialization for convenience.
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'cast_value' => $this->castValue(),
        ]);
    }
}

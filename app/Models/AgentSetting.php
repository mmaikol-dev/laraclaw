<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'label',
        'description',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::query()->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        return self::castStoredValue($setting->type, $setting->value);
    }

    public static function set(string $key, mixed $value): void
    {
        $serializedValue = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value), $value === null => $value,
            default => json_encode($value, JSON_THROW_ON_ERROR),
        };

        self::query()->where('key', $key)->update([
            'value' => $serializedValue,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function allAsArray(): array
    {
        return self::query()
            ->get()
            ->mapWithKeys(fn (self $setting): array => [$setting->key => $setting->castValue()])
            ->all();
    }

    protected function castValue(): mixed
    {
        return self::castStoredValue($this->type, $this->value);
    }

    public static function castStoredValue(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => (int) $value,
            'json' => $value === null ? null : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }
}

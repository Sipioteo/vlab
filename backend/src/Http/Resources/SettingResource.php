<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Settings\SettingsRepository;
use App\Support\Dates;

final class SettingResource
{
    /** @param array<string,mixed>|object $row raw settings table row @return array<string,mixed> */
    public static function toArray($row): array
    {
        $row = (array) $row;
        $isSecret = (bool) $row['is_secret'];
        $value = SettingsRepository::decodeValue($row['value']);
        return [
            'key' => $row['key'],
            'value' => $isSecret ? '********' : $value,
            'type' => $row['type'],
            'group' => $row['group'],
            'label_it' => $row['label_it'],
            'description_it' => $row['description_it'],
            'is_public' => (bool) $row['is_public'],
            'is_secret' => $isSecret,
            'nullable' => (bool) $row['nullable'],
            'options' => $row['options'] !== null ? json_decode((string) $row['options'], true) : null,
            'position' => (int) $row['position'],
            'updated_at' => Dates::iso($row['updated_at'] ?? null),
        ];
    }
}

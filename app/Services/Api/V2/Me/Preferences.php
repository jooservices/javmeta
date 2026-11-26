<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

use App\Repositories\Api\V2\MeSettingsRepository;

/** The interface preferences of a user. */
final class Preferences
{
    /** Every preference the UI knows (the request validates the value of each). */
    public const KEYS = ['language', 'density', 'privacy', 'shortcuts', 'stats_hidden', 'view_mode', 'page_size'];

    public function __construct(private readonly MeSettingsRepository $settings) {}

    /** @return array<string, mixed> */
    public function all(int $userId): array
    {
        return $this->settings->preferences($userId);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function change(int $userId, array $changes): array
    {
        foreach ($changes as $key => $value) {
            $changes[$key] = match ($key) {
                'privacy', 'shortcuts', 'stats_hidden' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'page_size' => (int) $value,
                default => $value,
            };
        }

        $this->settings->savePreferences($userId, $changes);

        return $this->all($userId);
    }
}

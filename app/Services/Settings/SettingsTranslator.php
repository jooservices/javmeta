<?php

declare(strict_types=1);

namespace App\Services\Settings;

/** Resolves settings registry labels through Laravel lang files with Vietnamese fallbacks. */
final class SettingsTranslator
{
    /** @param array<string, string> $groups
     *  @return array<string, string>
     */
    public function groups(array $groups): array
    {
        $out = [];
        foreach ($groups as $id => $fallback) {
            $out[$id] = $this->group((string) $id, (string) $fallback);
        }

        return $out;
    }

    public function group(string $id, string $fallback): string
    {
        return $this->line('settings.groups.' . $id, $fallback);
    }

    public function label(string $settingKey, string $fallback): string
    {
        return $this->line('settings.definitions.' . $this->definitionKey($settingKey) . '.label', $fallback);
    }

    public function description(string $settingKey, string $fallback): string
    {
        return $this->line('settings.definitions.' . $this->definitionKey($settingKey) . '.description', $fallback);
    }

    private function line(string $key, string $fallback): string
    {
        $translated = __($key);

        return $translated === $key ? $fallback : $translated;
    }

    /** Setting keys contain dots; flatten them so Laravel's dotted translator stays unambiguous. */
    private function definitionKey(string $settingKey): string
    {
        return str_replace('.', '__', $settingKey);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Code;

use InvalidArgumentException;

final readonly class NormalizedCode
{
    private function __construct(
        private string $prefix,
        private string $suffix,
        private ?string $scoped = null,
    ) {}

    public static function from(string $raw): self
    {
        $candidate = mb_strtoupper(trim($raw));

        if ($candidate === '') {
            throw new InvalidArgumentException('Code cannot be empty.');
        }

        // Reserved namespaces keep source-only identities separate from DVD codes.
        if (preg_match('/^(?:TOKYOHOT-SLUG-[A-Z0-9]+(?:_[A-Z0-9]+)+|(?:JAVBTC|FFJAV|TOKYOHOT|141JAV)-DERIVED-[A-F0-9]{64})$/D', $candidate) === 1) {
            return new self('', '', $candidate);
        }

        $matches = [];
        if (preg_match('/^(?<prefix>[A-Z0-9]+(?:[\s._-]+[A-Z0-9]+)*)[\s._-]+(?<suffix>\d+)$/u', $candidate, $matches) !== 1
            && preg_match('/^(?<prefix>[A-Z]+)(?<suffix>\d+)$/u', $candidate, $matches) !== 1) {
            throw new InvalidArgumentException('Code must contain a prefix and numeric suffix.');
        }

        $prefix = preg_replace('/[\s._-]+/u', '', (string) $matches['prefix']);
        // Leading zeros on the numeric suffix are insignificant for DVD-like
        // codes (SSIS-0001 and SSIS-001 both denote SSIS001), so strip them
        // before padding. Prefix identity is normalized separately and is
        // never zero-stripped, so FC2-PPV-* and FC2-* stay distinct.
        $suffix = ltrim((string) $matches['suffix'], '0');
        $suffix = $suffix === '' ? '0' : $suffix;

        if (! is_string($prefix) || $prefix === '' || ctype_digit($prefix)) {
            throw new InvalidArgumentException('Code prefix must contain letters.');
        }

        return new self($prefix, str_pad($suffix, 3, '0', STR_PAD_LEFT));
    }

    public function value(): string
    {
        return $this->scoped ?? $this->prefix . $this->suffix;
    }

    public function display(): string
    {
        return $this->scoped ?? $this->prefix . '-' . $this->suffix;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body: {"cookies": {"<name>": "<value>", ...}}. Names are RFC 6265 tokens and
 * values RFC 6265 cookie-octets. Error messages never include cookie values.
 */
final class PutSourceLoginCookiesRequest extends FormRequest
{
    private const NAME_PATTERN = "/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/";

    private const VALUE_PATTERN = '/^[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]*$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'cookies' => [
                'required',
                'array',
                'min:1',
                'max:50',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    foreach ($value as $name => $cookie) {
                        if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                            $fail('Each cookie name must be an RFC 6265 token.');

                            return;
                        }

                        if (! is_string($cookie) || strlen($cookie) > 4096 || preg_match(self::VALUE_PATTERN, $cookie) !== 1) {
                            $fail("Cookie {$name} must be a string of RFC 6265 cookie-octets (max 4096 bytes).");

                            return;
                        }
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        /** @var array<string, string> $cookies */
        $cookies = $this->validated('cookies');

        return $cookies;
    }
}

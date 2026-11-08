<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class ApiKeyCredential
{
    public function __construct(public int $id, public string $plaintext, public string $purpose = 'consumer') {}
}

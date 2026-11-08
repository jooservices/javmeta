<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Http\Client\Response;
use Tests\E2E\Context;

final readonly class HttpResult
{
    public function __construct(public Context $context, public Response $response) {}
}

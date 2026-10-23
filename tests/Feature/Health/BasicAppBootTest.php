<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use Tests\TestCase;

final class BasicAppBootTest extends TestCase
{
    public function test_root_endpoint_boots_the_application(): void
    {
        // The web root sends visitors to the (authenticated) operations dashboard.
        $this->get('/')->assertRedirect(route('operations.index'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use Tests\TestCase;

final class BasicAppBootTest extends TestCase
{
    public function test_root_endpoint_boots_the_application(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJsonPath('name', 'javmeta')
            ->assertJsonPath('status', 'ok');
    }
}

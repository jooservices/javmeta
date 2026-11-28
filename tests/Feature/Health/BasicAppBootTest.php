<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use Tests\Support\FakesBuiltUi;
use Tests\TestCase;

final class BasicAppBootTest extends TestCase
{
    use FakesBuiltUi;

    public function test_root_endpoint_boots_the_application(): void
    {
        // The web root serves the React UI page.
        $this->fakeBuiltUi();

        $this->get('/')->assertOk();
        $this->removeBuiltUi();
    }
}

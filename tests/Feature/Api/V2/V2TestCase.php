<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use Tests\TestCase;

/**
 * Base of the v2 feature tests. The installation default keeps the UI behind sign-in; these tests exercise the public
 * (guest) operations, so they run with login not required. LoginRequiredTest covers the default.
 */
abstract class V2TestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('javmeta.ui_login_required', false);
    }
}

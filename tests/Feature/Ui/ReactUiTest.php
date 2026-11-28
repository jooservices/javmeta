<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\Support\FakesBuiltUi;
use Tests\TestCase;

/** The React UI is served at the root of the site; the server routes keep answering as they did. */
final class ReactUiTest extends TestCase
{
    use FakesBuiltUi;

    protected function tearDown(): void
    {
        $this->removeBuiltUi();

        parent::tearDown();
    }

    public function test_the_root_and_every_page_address_of_the_ui_get_the_same_static_page(): void
    {
        $this->fakeBuiltUi();

        foreach (['/', '/movies', '/movies/ABC-123', '/performers/12', '/admin/settings', '/login'] as $path) {
            $this->get($path)->assertOk()->assertSee('React UI', false)->assertHeader('Cache-Control', 'no-cache, private');
        }
    }

    public function test_the_command_center_host_keeps_its_own_sign_in_page(): void
    {
        $this->fakeBuiltUi();
        config()->set('javmeta_domains.command_center_host', 'ops.internal.test');

        $this->get('http://ops.internal.test/login')->assertOk()->assertDontSee('React UI', false);
        $this->get('http://app.internal.test/login')->assertOk()->assertSee('React UI', false);
    }

    public function test_server_routes_are_never_answered_with_the_ui_page(): void
    {
        $this->fakeBuiltUi();

        foreach (['/api/v2/not-there', '/api/v1/not-there', '/ui-api', '/ui-me/not-there', '/ui-admin/not-there', '/ops/not-there', '/horizon/not-there', '/files/not-there', '/node/not-there', '/storage/not-there', '/web/assets/not-there.js'] as $path) {
            $response = $this->get($path);

            self::assertNotSame(200, $response->getStatusCode(), $path);
            $response->assertDontSee('React UI', false);
        }
    }

    public function test_a_json_request_to_an_unknown_address_is_a_404_not_the_page(): void
    {
        $this->fakeBuiltUi();

        $this->getJson('/nothing-here')->assertNotFound();
    }

    public function test_a_write_to_an_unknown_address_is_not_answered_with_the_page(): void
    {
        $this->fakeBuiltUi();

        self::assertContains($this->post('/nothing-here')->getStatusCode(), [404, 405]);
    }

    public function test_without_a_build_the_ui_address_is_a_404(): void
    {
        config()->set('javmeta.ui_index_path', sys_get_temp_dir() . '/ui-missing-' . bin2hex(random_bytes(4)) . '.html');

        $this->get('/')->assertNotFound();
    }

    public function test_the_old_ui_stays_where_it_was(): void
    {
        $this->get('/app')->assertRedirect(route('login'));
    }
}

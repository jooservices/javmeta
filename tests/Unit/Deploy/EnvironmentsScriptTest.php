<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

final class EnvironmentsScriptTest extends TestCase
{
    /** @return array{0: int, 1: string} */
    private function sh(string $command): array
    {
        $root = dirname(__DIR__, 3);
        exec('cd ' . escapeshellarg($root) . ' && ' . $command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    /** @return array<string, array<string, string>> */
    private function table(): array
    {
        [$code, $out] = $this->sh('bash deploy/environments.sh');
        $this->assertSame(0, $code, $out);
        $lines = array_values(array_filter(explode("\n", $out)));
        $header = preg_split('/\s+/', trim($lines[0]));
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $cells = preg_split('/\s+/', trim($line));
            $rows[$cells[0]] = array_combine($header, array_slice($cells, 0, count($header)));
        }

        return $rows;
    }

    public function test_every_name_that_must_differ_differs_between_staging_and_uat(): void
    {
        $rows = $this->table();

        foreach (['app_dir', 'postgres', 'mongo', 'elasticsearch', 'redis_prefix', 'port', 'host', 'units'] as $column) {
            $this->assertNotSame($rows['staging'][$column], $rows['uat'][$column], $column . ' must differ');
        }
        $this->assertStringEndsWith('_uat', $rows['uat']['postgres']);
    }

    public function test_the_reset_refuses_staging_and_anything_but_uat(): void
    {
        [$code, $out] = $this->sh('bash deploy/uat-reset.sh staging --dry-run');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('refuses to touch staging', $out);

        [$code] = $this->sh('bash deploy/uat-reset.sh --dry-run');
        $this->assertNotSame(0, $code);
    }

    public function test_the_reset_refuses_a_uat_database_that_is_the_staging_one(): void
    {
        [$code, $out] = $this->sh('JAVMETA_DEPLOY_POSTGRES_DATABASE=javmeta bash deploy/uat-reset.sh uat --dry-run');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('staging one', $out);
    }

    public function test_the_uat_reset_dry_run_names_the_uat_database_and_the_sample_load(): void
    {
        [$code, $out] = $this->sh('JAVMETA_APP_DIR=/nonexistent-uat bash deploy/uat-reset.sh uat --dry-run');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('javmeta_uat', $out);
        $this->assertStringContainsString('migrate:fresh', $out);
        $this->assertStringContainsString('dataset:load sample', $out);
        $this->assertStringContainsString('javmeta-uat-horizon.service', $out);
        $this->assertStringNotContainsString('javmeta-horizon.service', str_replace('javmeta-uat-horizon.service', '', $out));
    }

    public function test_the_deploy_script_names_its_target_in_a_dry_run_help(): void
    {
        [$code, $out] = $this->sh('bash deploy/staging.sh --help');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('[staging|uat]', $out);
    }
}

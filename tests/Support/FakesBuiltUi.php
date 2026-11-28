<?php

declare(strict_types=1);

namespace Tests\Support;

/** Gives a test a built React UI page without running npm: a one-line index.html in a temporary file. */
trait FakesBuiltUi
{
    private ?string $builtUiPath = null;

    protected function fakeBuiltUi(): string
    {
        $this->builtUiPath = tempnam(sys_get_temp_dir(), 'ui-index');
        file_put_contents($this->builtUiPath, '<!doctype html><html lang="vi"><head><title>React UI</title></head><body><div id="root"></div></body></html>');
        config()->set('javmeta.ui_index_path', $this->builtUiPath);

        return $this->builtUiPath;
    }

    protected function removeBuiltUi(): void
    {
        if ($this->builtUiPath !== null && is_file($this->builtUiPath)) {
            unlink($this->builtUiPath);
        }
    }
}

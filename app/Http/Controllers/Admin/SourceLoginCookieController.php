<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\PutSourceLoginCookiesRequest;
use App\Services\Crawler\SourceLoginCookieStore;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;
use JOOservices\LaravelLogging\Facades\ActivityLog;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Owner-only management of crawlerx login cookies per configured source.
 * Responses and audit records carry cookie names only, never values.
 */
final class SourceLoginCookieController extends BaseApiController
{
    public function update(string $slug, PutSourceLoginCookiesRequest $request, SourceLoginCookieStore $store): JsonResponse
    {
        $this->assertConfiguredSource($slug);
        $cookies = $request->cookies();
        $names = array_keys($cookies);

        $updatedAt = $store->put($slug, $cookies);
        $this->audit('source.login_cookies.updated', $slug, $names);

        return $this->respondWithData([
            'source' => $slug,
            'names' => $names,
            'updated_at' => $updatedAt->toISOString(),
        ]);
    }

    public function destroy(string $slug, SourceLoginCookieStore $store): JsonResponse
    {
        $this->assertConfiguredSource($slug);

        $store->forget($slug);
        $this->audit('source.login_cookies.cleared', $slug, []);

        return $this->respondNoContent();
    }

    private function assertConfiguredSource(string $slug): void
    {
        if (! is_array(config("javmeta_sources.sources.{$slug}"))) {
            throw ValidationException::withMessages(['slug' => 'The source must be a configured javmeta source.']);
        }
    }

    /** @param list<string> $names */
    private function audit(string $action, string $slug, array $names): void
    {
        try {
            ActivityLog::system()
                ->level('info')
                ->action($action)
                ->message('Source login cookies changed by the owner.')
                ->context(['source' => $slug, 'cookie_names' => $names])
                ->bySystem()
                ->save();
        } catch (Throwable) {
            // Fail-open: the activity log is not a hard dependency.
        }
    }
}

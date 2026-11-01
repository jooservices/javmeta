<?php

declare(strict_types=1);

namespace App\Services\Ui;

use App\Models\CrawlEvent;
use Illuminate\Support\Facades\Cache;

final class UiLoginStateRecorder
{
    private const STATE_KEY = 'javmeta:ui-login:recorded-state';
    private const LOCK_KEY = 'javmeta:ui-login:recording';

    public function record(): void
    {
        $loginRequired = (bool) config('javmeta.ui_login_required', true);
        if (! Cache::add(self::LOCK_KEY, true, 30)) {
            return;
        }

        try {
            if (Cache::get(self::STATE_KEY) === $loginRequired) {
                return;
            }

            CrawlEvent::query()->create([
                'source_slug' => 'system',
                'kind' => CrawlEvent::KIND_UI_LOGIN_STATE_CHANGED,
                'detail' => [
                    'login_required' => $loginRequired,
                    'guest_mode' => ! $loginRequired,
                ],
                'created_at' => now(),
            ]);

            Cache::forever(self::STATE_KEY, $loginRequired);
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }
}

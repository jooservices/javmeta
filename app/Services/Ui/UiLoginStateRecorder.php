<?php

declare(strict_types=1);

namespace App\Services\Ui;

use App\Repositories\CrawlQueueRepository;
use App\Repositories\CrawlEventRepository;
use App\Models\CrawlEvent;

final class UiLoginStateRecorder
{
    private const STATE_KEY = 'ui_login';

    public function record(): void
    {
        $loginRequired = (bool) config('javmeta.ui_login_required', true);
        app(CrawlQueueRepository::class)->transaction(static function () use ($loginRequired): void {
            app(CrawlQueueRepository::class)->tableQuery('ui_login_states')->insertOrIgnore([
                'state_key' => self::STATE_KEY,
                'login_required' => null,
                'updated_at' => now(),
            ]);

            $state = app(CrawlQueueRepository::class)->tableQuery('ui_login_states')
                ->where('state_key', self::STATE_KEY)
                ->lockForUpdate()
                ->first();

            if ($state !== null && $state->login_required !== null && (bool) $state->login_required === $loginRequired) {
                return;
            }

            app(CrawlQueueRepository::class)->tableQuery('ui_login_states')
                ->where('state_key', self::STATE_KEY)
                ->update(['login_required' => $loginRequired, 'updated_at' => now()]);

            app(CrawlEventRepository::class)->record(
                'system',
                CrawlEvent::KIND_UI_LOGIN_STATE_CHANGED,
                ['login_required' => $loginRequired, 'guest_mode' => ! $loginRequired],
            );
        });
    }
}

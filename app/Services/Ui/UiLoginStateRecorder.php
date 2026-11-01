<?php

declare(strict_types=1);

namespace App\Services\Ui;

use App\Models\CrawlEvent;
use Illuminate\Support\Facades\DB;

final class UiLoginStateRecorder
{
    private const STATE_KEY = 'ui_login';

    public function record(): void
    {
        $loginRequired = (bool) config('javmeta.ui_login_required', true);
        DB::transaction(static function () use ($loginRequired): void {
            DB::table('ui_login_states')->insertOrIgnore([
                'state_key' => self::STATE_KEY,
                'login_required' => null,
                'updated_at' => now(),
            ]);

            $state = DB::table('ui_login_states')
                ->where('state_key', self::STATE_KEY)
                ->lockForUpdate()
                ->first();

            if ($state !== null && $state->login_required !== null && (bool) $state->login_required === $loginRequired) {
                return;
            }

            DB::table('ui_login_states')
                ->where('state_key', self::STATE_KEY)
                ->update(['login_required' => $loginRequired, 'updated_at' => now()]);

            CrawlEvent::query()->create([
                'source_slug' => 'system',
                'kind' => CrawlEvent::KIND_UI_LOGIN_STATE_CHANGED,
                'detail' => [
                    'login_required' => $loginRequired,
                    'guest_mode' => ! $loginRequired,
                ],
                'created_at' => now(),
            ]);
        });
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The backlog_depth alert (a hot source with too little due work) is replaced by backlog_age and backlog_growth (JOOXCR-330).
 * An alert of the old rule that is still open would never be evaluated again, and its template is gone, so every such alert
 * is closed without a recovery notice and the unused template (its revisions go with it) is removed.
 */
return new class extends Migration {
    private const TEMPLATE_KEY = 'crawl.backlog_by_source_high';

    public function up(): void
    {
        $now = now();
        $retired = static fn() => DB::table('alert_events')->where('rule', 'like', 'backlog_depth:%');
        $retired()->whereNull('resolved_at')->update(['resolved_at' => $now]);
        // An alert that was resolved but never recovered would be retried with an empty message, as its template is gone.
        $retired()->whereNull('recovery_completed_at')->update(['recovery_completed_at' => $now]);
        DB::table('notification_templates')->where('key', self::TEMPLATE_KEY)->delete();
    }

    /** The retired rule and its template are not brought back; the data it left is history. */
    public function down(): void {}
};

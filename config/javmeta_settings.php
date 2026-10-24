<?php

declare(strict_types=1);

/*
| Every feature switch and operational value that the owner may change, stored in the database (the laravel-config
| store, group "settings") and edited on the Settings page of the UI. The env file keeps only what is needed to reach
| the databases themselves. A value that was never saved falls back to the config default (which still reads env), and
| `php artisan settings:import-env` copies those defaults into the database once.
|
| Fields: group, label, description, type (int, float, bool, string, secret, int_list), config (the Laravel config keys
| the value is applied to), min, max, options (allowed strings), restart (the unit that must restart to pick it up).
*/
return [
    'groups' => [
        'queue' => 'Hàng đợi crawl',
        'crawl' => 'Vòng lặp crawl',
        'alerts' => 'Cảnh báo',
        'telegram' => 'Telegram',
        'report' => 'Báo cáo hằng giờ',
        'readiness' => 'Kiểm tra sẵn sàng',
        'search' => 'Tìm kiếm',
        'observability' => 'Giám sát',
        'backup' => 'Sao lưu',
        'follow_notifications' => 'Thông báo theo dõi (Telegram)',
    ],

    'definitions' => [
        'queue.workers_per_queue' => ['group' => 'queue', 'label' => 'Số worker mỗi hàng đợi', 'description' => 'Horizon chạy bấy nhiêu tiến trình cho mỗi hàng đợi.', 'type' => 'int', 'config' => ['javmeta_queues.workers_per_queue'], 'min' => 1, 'max' => 32, 'restart' => 'javmeta-horizon'],
        'queue.depth_limit' => ['group' => 'queue', 'label' => 'Ngưỡng hàng đợi đầy', 'description' => 'Trên số việc chờ này, kiểm tra sẵn sàng báo hàng đợi đầy.', 'type' => 'int', 'config' => ['javmeta_queues.depth_limit'], 'min' => 1, 'max' => 100000],
        'queue.per_source_inflight' => ['group' => 'queue', 'label' => 'Việc đồng thời mỗi nguồn', 'description' => 'Tối đa số việc của một nguồn được chạy cùng lúc.', 'type' => 'int', 'config' => ['javmeta_queues.per_source_inflight'], 'min' => 1, 'max' => 64],
        'queue.feed_pool_limit' => ['group' => 'queue', 'label' => 'Việc nạp vào hàng đợi mỗi phút', 'description' => 'Tối đa số dòng crawl_queue được nạp mỗi phút.', 'type' => 'int', 'config' => ['javmeta_queues.feed_pool_limit'], 'min' => 1, 'max' => 5000],
        'queue.pacing_horizon_seconds' => ['group' => 'queue', 'label' => 'Tầm giãn nhịp (giây)', 'description' => 'Cửa sổ dùng để chia nhịp request của mỗi nguồn.', 'type' => 'int', 'config' => ['javmeta_queues.pacing_horizon_seconds'], 'min' => 1, 'max' => 3600],
        'queue.gap_blocked_max_seconds' => ['group' => 'queue', 'label' => 'Khoảng nghỉ tối đa khi bị chặn (giây)', 'description' => 'Khi một nguồn bị chặn, khoảng nghỉ giữa các request tăng gấp đôi đến mức này.', 'type' => 'int', 'config' => ['javmeta_queues.gap_blocked_max_seconds'], 'min' => 1, 'max' => 86400],
        'queue.worker_timeout' => ['group' => 'queue', 'label' => 'Thời gian tối đa một việc (giây)', 'description' => 'Việc chạy lâu hơn sẽ bị dừng.', 'type' => 'int', 'config' => ['javmeta_queues.worker_timeout'], 'min' => 10, 'max' => 3600, 'restart' => 'javmeta-horizon'],

        'crawl.stale_claim_timeout_seconds' => ['group' => 'crawl', 'label' => 'Việc kẹt được giải phóng sau (giây)', 'description' => 'Việc ở trạng thái claimed lâu hơn mức này được trả lại hàng đợi.', 'type' => 'int', 'config' => ['javmeta_sources.defaults.queue.stale_claim_timeout_seconds'], 'min' => 60, 'max' => 86400],
        'crawl.block_delays_seconds' => ['group' => 'crawl', 'label' => 'Thời gian chờ khi bị chặn (giây, cách nhau bằng dấu phẩy)', 'description' => 'Mỗi lần bị chặn chờ theo từng giá trị; hết danh sách thì việc thất bại.', 'type' => 'int_list', 'config' => ['javmeta_sources.defaults.queue.block_delays_seconds']],
        'crawl.fresh_interval_minutes' => ['group' => 'crawl', 'label' => 'Đọc lại trang đầu mỗi (phút)', 'description' => 'Chu kỳ đọc lại trang đầu danh sách để bắt phim mới.', 'type' => 'int', 'config' => ['javmeta_sources.defaults.loop.fresh_interval_minutes'], 'min' => 1, 'max' => 10080],
        'crawl.loop_pause_seconds' => ['group' => 'crawl', 'label' => 'Nghỉ giữa hai vòng danh sách (giây)', 'description' => 'Sau khi đọc hết danh sách, chờ rồi bắt đầu lại từ trang 1.', 'type' => 'int', 'config' => ['javmeta_sources.defaults.loop.pause_seconds'], 'min' => 0, 'max' => 2592000],
        'crawl.recrawl_after_days' => ['group' => 'crawl', 'label' => 'Crawl lại phim thiếu thông tin sau (ngày)', 'description' => 'Phim dưới tầng đầy đủ tối thiểu được crawl lại khi lần crawl cuối cũ hơn mức này.', 'type' => 'int', 'config' => ['javmeta_sources.defaults.recrawl.incomplete_after_days'], 'min' => 1, 'max' => 365],
        'crawl.recrawl_min_tier' => ['group' => 'crawl', 'label' => 'Tầng đầy đủ tối thiểu', 'description' => 'Phim dưới tầng này được xem là thiếu thông tin.', 'type' => 'int', 'config' => ['javmeta_sources.defaults.recrawl.min_tier'], 'min' => 0, 'max' => 3],
        'crawl.recrawl_batch' => ['group' => 'crawl', 'label' => 'Số phim crawl lại mỗi đợt', 'description' => 'Mỗi giờ crawl lại tối đa bấy nhiêu phim thiếu thông tin.', 'type' => 'int', 'config' => ['javmeta_sources.defaults.recrawl.batch'], 'min' => 1, 'max' => 5000],
        'crawl.recrawl_max_rounds' => ['group' => 'crawl', 'label' => 'Số lần crawl lại tối đa mỗi phim', 'description' => 'Sau số lần này phim không được crawl lại nữa.', 'type' => 'int', 'config' => ['javmeta_sources.defaults.recrawl.max_rounds'], 'min' => 1, 'max' => 20],

        'alerts.enabled' => ['group' => 'alerts', 'label' => 'Bật cảnh báo', 'description' => 'Tắt thì không có cảnh báo nào được gửi.', 'type' => 'bool', 'config' => ['javmeta_alerts.enabled']],
        'alerts.debounce_seconds' => ['group' => 'alerts', 'label' => 'Gộp cảnh báo trùng trong (giây)', 'description' => 'Cùng một cảnh báo chỉ gửi một lần trong khoảng này.', 'type' => 'int', 'config' => ['javmeta_alerts.debounce_seconds'], 'min' => 0, 'max' => 86400],
        'alerts.throttle_seconds' => ['group' => 'alerts', 'label' => 'Tối đa một cảnh báo mỗi nguyên nhân trong (giây)', 'description' => 'Áp dụng cho watchdog, sao lưu, crawl đứng, hàng đợi kẹt.', 'type' => 'int', 'config' => ['javmeta_alerts.throttle_seconds'], 'min' => 0, 'max' => 604800],
        'alerts.miss_debounce_seconds' => ['group' => 'alerts', 'label' => 'Gộp cảnh báo thiếu dữ liệu (giây)', 'description' => 'Khoảng gộp cho cảnh báo phim hoặc diễn viên bị hỏi mà chưa có.', 'type' => 'int', 'config' => ['javmeta_alerts.miss_debounce_seconds'], 'min' => 0, 'max' => 604800],
        'alerts.title_failure_threshold' => ['group' => 'alerts', 'label' => 'Số lần lỗi liên tiếp mới cảnh báo', 'description' => 'Một phim lỗi quá số lần này sẽ được cảnh báo.', 'type' => 'int', 'config' => ['javmeta_alerts.title_failure_threshold'], 'min' => 1, 'max' => 100],
        'alerts.heartbeat_stale_seconds' => ['group' => 'alerts', 'label' => 'Worker im lặng quá (giây) thì cảnh báo', 'description' => 'Watchdog: nhịp tim worker cũ hơn mức này.', 'type' => 'int', 'config' => ['javmeta_alerts.watchdog.heartbeat_stale_seconds'], 'min' => 30, 'max' => 86400],
        'alerts.source_stale_seconds' => ['group' => 'alerts', 'label' => 'Nguồn không thành công quá (giây) thì cảnh báo', 'description' => 'Watchdog: nguồn đang bật mà không có kết quả.', 'type' => 'int', 'config' => ['javmeta_alerts.watchdog.source_stale_seconds'], 'min' => 60, 'max' => 2592000],
        'alerts.crawl_stall_seconds' => ['group' => 'alerts', 'label' => 'Crawl đứng quá (giây) thì cảnh báo', 'description' => 'Watchdog: không có phim mới trong khoảng này.', 'type' => 'int', 'config' => ['javmeta_alerts.watchdog.crawl_stall_seconds'], 'min' => 60, 'max' => 604800],
        'alerts.queue_stuck_seconds' => ['group' => 'alerts', 'label' => 'Hàng đợi kẹt quá (giây) thì cảnh báo', 'description' => 'Watchdog: việc chờ lâu không được xử lý.', 'type' => 'int', 'config' => ['javmeta_alerts.watchdog.queue_stuck_seconds'], 'min' => 60, 'max' => 604800],

        'telegram.bot_token' => ['group' => 'telegram', 'label' => 'Token của bot', 'description' => 'Token do BotFather cấp. Được mã hoá trong cơ sở dữ liệu và không hiển thị lại.', 'type' => 'secret', 'config' => ['javmeta_alerts.telegram.bot_token', 'laravel-notifications.channels.telegram.bot_token']],
        'telegram.chat_id' => ['group' => 'telegram', 'label' => 'Chat ID nhận tin', 'description' => 'Chat của chủ sở hữu; chỉ chat này được dùng bot.', 'type' => 'string', 'config' => ['javmeta_alerts.telegram.chat_id', 'laravel-notifications.channels.telegram.chat_id']],
        'telegram.api_base' => ['group' => 'telegram', 'label' => 'Địa chỉ API Telegram', 'description' => 'Mặc định https://api.telegram.org.', 'type' => 'string', 'config' => ['javmeta_alerts.telegram.api_base', 'laravel-notifications.channels.telegram.api_base']],
        'telegram.timeout' => ['group' => 'telegram', 'label' => 'Thời gian chờ Telegram (giây)', 'description' => 'Quá thời gian này tin được xem là chưa gửi.', 'type' => 'int', 'config' => ['javmeta_alerts.telegram.timeout', 'laravel-notifications.channels.telegram.timeout'], 'min' => 1, 'max' => 60],

        'telegram.bot_enabled' => ['group' => 'telegram', 'label' => 'Bật bot hỏi đáp', 'description' => 'Bot trả lời tin của chủ sở hữu: tìm phim, diễn viên, trạng thái, theo dõi. Chỉ chat ID ở trên được dùng.', 'type' => 'bool', 'config' => ['javmeta_notifications.bot.enabled']],
        'telegram.bot_max_results' => ['group' => 'telegram', 'label' => 'Số kết quả bot trả mỗi nhóm', 'description' => 'Tối đa 10.', 'type' => 'int', 'config' => ['javmeta_notifications.bot.max_results'], 'min' => 1, 'max' => 10],
        'report.timezone' => ['group' => 'report', 'label' => 'Múi giờ báo cáo', 'description' => 'Ví dụ Asia/Ho_Chi_Minh.', 'type' => 'string', 'config' => ['javmeta_alerts.hourly_report.timezone']],

        'readiness.max_newest_movie_age_minutes' => ['group' => 'readiness', 'label' => 'Phim mới nhất không cũ quá (phút)', 'description' => 'Cũ hơn mức này nghĩa là crawl không chạy.', 'type' => 'int', 'config' => ['javmeta_readiness.max_newest_movie_age_minutes'], 'min' => 1, 'max' => 10080],
        'readiness.max_dead_sources' => ['group' => 'readiness', 'label' => 'Số nguồn chết tối đa', 'description' => 'Nguồn bật mà 24 giờ không thành công; quá số này thì báo lỗi.', 'type' => 'int', 'config' => ['javmeta_readiness.max_dead_sources'], 'min' => 0, 'max' => 100],
        'readiness.max_backup_age_hours' => ['group' => 'readiness', 'label' => 'Bản sao lưu không cũ quá (giờ)', 'description' => 'Cũ hơn mức này thì báo lỗi.', 'type' => 'int', 'config' => ['javmeta_readiness.max_backup_age_hours'], 'min' => 1, 'max' => 720],

        'search.enabled' => ['group' => 'search', 'label' => 'Bật tìm kiếm Elasticsearch', 'description' => 'Tắt thì chỉ dùng tìm kiếm trong cơ sở dữ liệu.', 'type' => 'bool', 'config' => ['elasticsearch.enabled']],
        'search.embedder_enabled' => ['group' => 'search', 'label' => 'Bật vector (tìm theo nghĩa)', 'description' => 'Tắt thì tài liệu được lập chỉ mục mà không có vector.', 'type' => 'bool', 'config' => ['elasticsearch.embedder_enabled']],

        'observability.openobserve_enabled' => ['group' => 'observability', 'label' => 'Gửi log và metric tới OpenObserve', 'description' => 'Tắt thì không ghi telemetry.', 'type' => 'bool', 'config' => ['openobserve.enabled']],
        'observability.trace_sample_rate' => ['group' => 'observability', 'label' => 'Tỷ lệ lấy mẫu trace (0 đến 1)', 'description' => '1 là lấy mọi request.', 'type' => 'float', 'config' => ['openobserve.trace_sample_rate'], 'min' => 0, 'max' => 1],
        'observability.sentry_traces_sample_rate' => ['group' => 'observability', 'label' => 'Tỷ lệ lấy mẫu trace Sentry (0 đến 1)', 'description' => '0 là tắt trace Sentry.', 'type' => 'float', 'config' => ['sentry.traces_sample_rate'], 'min' => 0, 'max' => 1],

        'follow_notifications.enabled' => ['group' => 'follow_notifications', 'label' => 'Gửi thông báo phim mới của mục đang theo dõi', 'description' => 'Mỗi phim mới của diễn viên, maker hoặc series đang theo dõi gửi một tin Telegram kèm ảnh bìa và nút Mở.', 'type' => 'bool', 'config' => ['javmeta_notifications.follow.enabled']],
        'follow_notifications.daily_cap' => ['group' => 'follow_notifications', 'label' => 'Số tin tối đa mỗi ngày', 'description' => 'Quá số này, các phim còn lại chờ sang ngày sau.', 'type' => 'int', 'config' => ['javmeta_notifications.follow.daily_cap'], 'min' => 1, 'max' => 500],
        'follow_notifications.quiet_from_hour' => ['group' => 'follow_notifications', 'label' => 'Giờ yên lặng từ (0 đến 23)', 'description' => 'Trong giờ yên lặng không gửi tin; tin được gửi sau khi hết giờ. Bằng giờ kết thúc thì tắt giờ yên lặng.', 'type' => 'int', 'config' => ['javmeta_notifications.follow.quiet_from_hour'], 'min' => 0, 'max' => 23],
        'follow_notifications.quiet_to_hour' => ['group' => 'follow_notifications', 'label' => 'Giờ yên lặng đến (0 đến 23)', 'description' => 'Giờ kết thúc (không gồm giờ này), theo múi giờ báo cáo.', 'type' => 'int', 'config' => ['javmeta_notifications.follow.quiet_to_hour'], 'min' => 0, 'max' => 23],
        'follow_notifications.lookback_days' => ['group' => 'follow_notifications', 'label' => 'Chỉ báo phim thấy trong (ngày)', 'description' => 'Phim cũ hơn mức này không được báo.', 'type' => 'int', 'config' => ['javmeta_notifications.follow.lookback_days'], 'min' => 1, 'max' => 30],
        'backup.stale_after_seconds' => ['group' => 'backup', 'label' => 'Bản sao lưu coi là cũ sau (giây)', 'description' => 'Dùng cho cảnh báo sao lưu hằng phút.', 'type' => 'int', 'config' => ['backup.stale_after_seconds'], 'min' => 3600, 'max' => 2592000],
    ],
];

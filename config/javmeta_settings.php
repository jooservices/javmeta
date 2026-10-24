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
        'ai' => 'AI (Ollama, embedding)',
        'follow_notifications' => 'Thông báo theo dõi (Telegram)',
        'domains' => 'Tên miền',
        'library' => 'Thư viện trên đĩa',
        'downloads' => 'Tải về (qBittorrent)',
        'files' => 'Máy chủ tệp',
        'media' => 'Xử lý media',
    ],

    'definitions' => [
        'perf.warm_enabled' => ['group' => 'search', 'label' => 'Làm ấm bộ nhớ đệm', 'description' => 'Tự làm mới trạng thái và bộ lọc toàn bộ danh mục theo lịch, giảm thời gian chờ khi bộ nhớ đệm hết hạn.', 'type' => 'bool', 'config' => ['javmeta_perf.warm_enabled']],

        'search.budget_ms' => ['group' => 'search', 'label' => 'Ngân sách tìm kiếm (mili giây)', 'description' => 'Tổng thời gian dành cho HTTP tùy chọn trong một lượt tìm kiếm kết hợp. Hết thời gian thì trả kết quả từ khóa; không giới hạn truy vấn cơ sở dữ liệu.', 'type' => 'int', 'config' => ['javmeta_search.budget_ms'], 'min' => 25, 'max' => 1500],

        'media.vision_model' => ['group' => 'media', 'label' => 'Mô hình mô tả cảnh', 'description' => 'Mô hình vision Ollama nội bộ; tuyệt đối không gửi ảnh ra cloud.', 'type' => 'string', 'config' => ['javmeta_media.vision_model']],
        'media.scenes_index' => ['group' => 'media', 'label' => 'Chỉ mục tìm cảnh Elasticsearch', 'description' => 'Chỉ mục được tạo khi có vector đầu tiên. Đổi tên cần lập chỉ mục lại.', 'type' => 'string', 'config' => ['javmeta_media.scenes_index']],
        'media.embedding_dimensions' => ['group' => 'media', 'label' => 'Số chiều vector cảnh', 'description' => 'Phải khớp dịch vụ embedder và mapping Elasticsearch.', 'type' => 'int', 'config' => ['javmeta_media.embedding_dimensions'], 'min' => 8, 'max' => 8192],
        'media.translate_model' => ['group' => 'media', 'label' => 'Mô hình dịch phụ đề', 'description' => 'Chỉ chạy mô hình Ollama cục bộ để dịch sang EN và VI.', 'type' => 'string', 'config' => ['javmeta_media.translate_model']],
        'media.translate_batch_segments' => ['group' => 'media', 'label' => 'Số câu phụ đề mỗi đợt dịch', 'description' => 'Giữ nguyên mốc thời gian và số thứ tự.', 'type' => 'int', 'config' => ['javmeta_media.translate_batch_segments'], 'min' => 1, 'max' => 100],
        'media.ollama_timeout_sec' => ['group' => 'media', 'label' => 'Thời gian chờ Ollama media (giây)', 'description' => 'Lỗi thì dừng; không dùng cloud.', 'type' => 'int', 'config' => ['javmeta_media.ollama_timeout_sec'], 'min' => 1, 'max' => 3600],
        'media.whisper_model' => ['group' => 'media', 'label' => 'Mô hình nhận dạng giọng nói', 'description' => 'Whisper chạy CUDA float16 trên GPU nội bộ; không chuyển sang cloud.', 'type' => 'string', 'config' => ['javmeta_media.whisper_model']],
        'media.api_url' => ['group' => 'media', 'label' => 'Địa chỉ JavMeta cho worker', 'description' => 'Địa chỉ nội bộ của API JavMeta.', 'type' => 'string', 'config' => ['javmeta_media.api_url']],
        'media.output_folder' => ['group' => 'media', 'label' => 'Thư mục output media', 'description' => 'Chỉ ghi ảnh và phụ đề tại đây; không ghi vào thư viện.', 'type' => 'string', 'config' => ['javmeta_media.output_folder']],
        'media.max_parallel' => ['group' => 'media', 'label' => 'Số việc media đồng thời', 'description' => 'Giới hạn chung cho mọi worker.', 'type' => 'int', 'config' => ['javmeta_media.max_parallel'], 'min' => 1, 'max' => 16],
        'media.max_attempts' => ['group' => 'media', 'label' => 'Số lần thử media tối đa', 'description' => 'Sau mức này cần chủ sở hữu bấm thử lại.', 'type' => 'int', 'config' => ['javmeta_media.max_attempts'], 'min' => 1, 'max' => 10],
        'media.lease_seconds' => ['group' => 'media', 'label' => 'Thời hạn lượt xử lý (giây)', 'description' => 'Phải lớn hơn thời gian tối đa một việc.', 'type' => 'int', 'config' => ['javmeta_media.lease_seconds'], 'min' => 60, 'max' => 86400],
        'media.job_timeout_sec' => ['group' => 'media', 'label' => 'Thời gian tối đa một việc media', 'description' => 'Dừng tiến trình quá hạn (giây).', 'type' => 'int', 'config' => ['javmeta_media.job_timeout_sec'], 'min' => 10, 'max' => 72000],
        'media.poll_sec' => ['group' => 'media', 'label' => 'Nghỉ khi hàng đợi trống (giây)', 'description' => 'Worker đọc lại cấu hình mỗi lượt.', 'type' => 'int', 'config' => ['javmeta_media.poll_sec'], 'min' => 1, 'max' => 60],
        'media.thumb_interval_sec' => ['group' => 'media', 'label' => 'Khoảng cách ảnh preview (giây)', 'description' => 'Lấy một ảnh sau mỗi khoảng này.', 'type' => 'int', 'config' => ['javmeta_media.thumb_interval_sec'], 'min' => 1, 'max' => 3600],
        'media.scene_threshold' => ['group' => 'media', 'label' => 'Ngưỡng chuyển cảnh', 'description' => 'Độ khác biệt hình ảnh để tách cảnh.', 'type' => 'float', 'config' => ['javmeta_media.scene_threshold'], 'min' => 0.01, 'max' => 1],
        'media.ffmpeg_threads' => ['group' => 'media', 'label' => 'Số luồng ffmpeg', 'description' => 'Giới hạn CPU để giữ máy đáp ứng.', 'type' => 'int', 'config' => ['javmeta_media.ffmpeg_threads'], 'min' => 1, 'max' => 16],
        'queue.workers_per_queue' => ['group' => 'queue', 'label' => 'Số worker mỗi hàng đợi', 'description' => 'Horizon chạy bấy nhiêu tiến trình cho mỗi hàng đợi.', 'type' => 'int', 'config' => ['javmeta_queues.workers_per_queue'], 'min' => 1, 'max' => 32, 'restart' => 'javmeta-horizon'],
        'queue.depth_limit' => ['group' => 'queue', 'label' => 'Ngưỡng hàng đợi đầy', 'description' => 'Trên số việc chờ này, kiểm tra sẵn sàng báo hàng đợi đầy.', 'type' => 'int', 'config' => ['javmeta_queues.depth_limit'], 'min' => 1, 'max' => 100000],
        'queue.per_source_inflight' => ['group' => 'queue', 'label' => 'Việc đồng thời mỗi nguồn', 'description' => 'Tối đa số việc của một nguồn được chạy cùng lúc.', 'type' => 'int', 'config' => ['javmeta_queues.per_source_inflight'], 'min' => 1, 'max' => 64],
        'queue.feed_pool_limit' => ['group' => 'queue', 'label' => 'Việc nạp vào hàng đợi mỗi phút', 'description' => 'Tối đa số dòng crawl_queue được nạp mỗi phút.', 'type' => 'int', 'config' => ['javmeta_queues.feed_pool_limit'], 'min' => 1, 'max' => 5000],
        'queue.pacing_horizon_seconds' => ['group' => 'queue', 'label' => 'Tầm giãn nhịp (giây)', 'description' => 'Cửa sổ dùng để chia nhịp request của mỗi nguồn.', 'type' => 'int', 'config' => ['javmeta_queues.pacing_horizon_seconds'], 'min' => 1, 'max' => 3600],
        'queue.gap_blocked_max_seconds' => ['group' => 'queue', 'label' => 'Khoảng nghỉ tối đa khi bị chặn (giây)', 'description' => 'Khi một nguồn bị chặn, khoảng nghỉ giữa các request tăng gấp đôi đến mức này.', 'type' => 'int', 'config' => ['javmeta_queues.gap_blocked_max_seconds'], 'min' => 1, 'max' => 86400],
        'queue.browser_inflight' => ['group' => 'queue', 'label' => 'Việc dùng trình duyệt cùng lúc (tổng)', 'description' => 'Các nguồn cần FlareSolverr/Playwright trên Dev VM dùng chung giới hạn này để dịch vụ không quá tải và báo lỗi mạng. 0 = không giới hạn.', 'type' => 'int', 'config' => ['javmeta_queues.browser_inflight'], 'min' => 0, 'max' => 64],
        'queue.browser_sources' => ['group' => 'queue', 'label' => 'Nguồn cần trình duyệt (mỗi dòng một mã nguồn)', 'description' => 'Ví dụ javlibrary, javbus, missav, jable, warashi, javdatabase.', 'type' => 'string_list', 'config' => ['javmeta_queues.browser_sources']],
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

        'search.embedder_url' => ['group' => 'search', 'label' => 'Địa chỉ dịch vụ embedder (E5)', 'description' => 'Dùng khi nhà cung cấp embedding là embedder.', 'type' => 'string', 'config' => ['elasticsearch.embedder_url']],
        'search.embedding_dimensions' => ['group' => 'search', 'label' => 'Số chiều của vector', 'description' => 'Đổi bằng lệnh php artisan search:switch-embeddings, không sửa tay: chỉ mục phải được dựng lại.', 'type' => 'int', 'config' => ['elasticsearch.embed_dim'], 'min' => 8, 'max' => 8192],
        'search.movies_index' => ['group' => 'search', 'label' => 'Chỉ mục Elasticsearch của phim', 'description' => 'search:switch-embeddings dựng chỉ mục mới rồi đổi sang nó; chỉ mục cũ được giữ để quay lại.', 'type' => 'string', 'config' => ['elasticsearch.movies_index']],
        'search.performers_index' => ['group' => 'search', 'label' => 'Chỉ mục Elasticsearch của diễn viên', 'description' => 'Như trên.', 'type' => 'string', 'config' => ['elasticsearch.performers_index']],
        'ai.embedding_provider' => ['group' => 'ai', 'label' => 'Nhà cung cấp embedding', 'description' => 'embedder (E5 cục bộ) hoặc ollama. Đổi bằng php artisan search:switch-embeddings <nhà cung cấp> để chỉ mục được dựng lại an toàn.', 'type' => 'string', 'options' => ['embedder', 'ollama'], 'config' => ['javmeta_ai.embedding_provider']],
        'ai.ollama_url' => ['group' => 'ai', 'label' => 'Địa chỉ Ollama', 'description' => 'Ví dụ http://127.0.0.1:11434. Kiểm tra bằng php artisan ai:check.', 'type' => 'string', 'config' => ['javmeta_ai.ollama_url']],
        'ai.ollama_embed_model' => ['group' => 'ai', 'label' => 'Model embedding của Ollama', 'description' => 'Ví dụ nomic-embed-text.', 'type' => 'string', 'config' => ['javmeta_ai.ollama_embed_model']],
        'ai.ollama_chat_model' => ['group' => 'ai', 'label' => 'Model hội thoại của Ollama', 'description' => 'Dùng để hiểu câu hỏi tự nhiên.', 'type' => 'string', 'config' => ['javmeta_ai.ollama_chat_model']],
        'ai.ollama_timeout' => ['group' => 'ai', 'label' => 'Thời gian chờ Ollama (giây)', 'description' => '', 'type' => 'int', 'config' => ['javmeta_ai.ollama_timeout'], 'min' => 1, 'max' => 300],
        'ai.nl_timeout_seconds' => ['group' => 'ai', 'label' => 'Chờ Ollama đọc câu hỏi tối đa (giây)', 'description' => 'Quá thời gian này thì dùng luật cơ bản, để tìm kiếm không bị chậm khi model còn đang nạp.', 'type' => 'int', 'config' => ['javmeta_ai.nl_timeout_seconds'], 'min' => 1, 'max' => 60],
        'ai.ollama_keep_alive' => ['group' => 'ai', 'label' => 'Giữ model trong bộ nhớ (ví dụ 30m)', 'description' => 'Ollama giữ model đã nạp trong khoảng này; lệnh ai:warm nạp lại trước khi hết hạn.', 'type' => 'string', 'config' => ['javmeta_ai.ollama_keep_alive']],
        'ai.nl_enabled' => ['group' => 'ai', 'label' => 'Hiểu câu hỏi tự nhiên', 'description' => 'Đọc bộ lọc (maker, thể loại, năm, thời lượng, diễn viên) từ câu hỏi. Luôn có luật cơ bản; có Ollama thì dùng model trước.', 'type' => 'bool', 'config' => ['javmeta_ai.nl_enabled']],
        'ai.cloud_enabled' => ['group' => 'ai', 'label' => 'Cho phép dùng AI đám mây', 'description' => 'TẮT. Theo văn bản quyết định (JOOXCR-230, chờ duyệt) chỉ câu hỏi tìm kiếm do chủ sở hữu gõ mới được gửi đi, không bao giờ gửi nội dung phim, ảnh hay âm thanh.', 'type' => 'bool', 'config' => ['javmeta_ai.cloud_enabled']],
        'observability.openobserve_enabled' => ['group' => 'observability', 'label' => 'Gửi log và metric tới OpenObserve', 'description' => 'Tắt thì không ghi telemetry.', 'type' => 'bool', 'config' => ['openobserve.enabled']],
        'observability.trace_sample_rate' => ['group' => 'observability', 'label' => 'Tỷ lệ lấy mẫu trace (0 đến 1)', 'description' => '1 là lấy mọi request.', 'type' => 'float', 'config' => ['openobserve.trace_sample_rate'], 'min' => 0, 'max' => 1],
        'observability.sentry_traces_sample_rate' => ['group' => 'observability', 'label' => 'Tỷ lệ lấy mẫu trace Sentry (0 đến 1)', 'description' => '0 là tắt trace Sentry.', 'type' => 'float', 'config' => ['sentry.traces_sample_rate'], 'min' => 0, 'max' => 1],

        'follow_notifications.enabled' => ['group' => 'follow_notifications', 'label' => 'Gửi thông báo phim mới của mục đang theo dõi', 'description' => 'Mỗi phim mới của diễn viên, maker hoặc series đang theo dõi gửi một tin Telegram kèm ảnh bìa và nút Mở.', 'type' => 'bool', 'config' => ['javmeta_notifications.follow.enabled']],
        'follow_notifications.daily_cap' => ['group' => 'follow_notifications', 'label' => 'Số tin tối đa mỗi ngày', 'description' => 'Quá số này, các phim còn lại chờ sang ngày sau.', 'type' => 'int', 'config' => ['javmeta_notifications.follow.daily_cap'], 'min' => 1, 'max' => 500],
        'follow_notifications.quiet_from_hour' => ['group' => 'follow_notifications', 'label' => 'Giờ yên lặng từ (0 đến 23)', 'description' => 'Trong giờ yên lặng không gửi tin; tin được gửi sau khi hết giờ. Bằng giờ kết thúc thì tắt giờ yên lặng.', 'type' => 'int', 'config' => ['javmeta_notifications.follow.quiet_from_hour'], 'min' => 0, 'max' => 23],
        'follow_notifications.quiet_to_hour' => ['group' => 'follow_notifications', 'label' => 'Giờ yên lặng đến (0 đến 23)', 'description' => 'Giờ kết thúc (không gồm giờ này), theo múi giờ báo cáo.', 'type' => 'int', 'config' => ['javmeta_notifications.follow.quiet_to_hour'], 'min' => 0, 'max' => 23],
        'follow_notifications.lookback_days' => ['group' => 'follow_notifications', 'label' => 'Chỉ báo phim thấy trong (ngày)', 'description' => 'Phim cũ hơn mức này không được báo.', 'type' => 'int', 'config' => ['javmeta_notifications.follow.lookback_days'], 'min' => 1, 'max' => 30],
        'library.enabled' => ['group' => 'library', 'label' => 'Bật quét thư viện hằng ngày', 'description' => 'Quét các thư mục bên dưới, nhận mã phim trong tên tệp và ghép với phim đã biết. Không tải gì lên và không sửa tệp.', 'type' => 'bool', 'config' => ['javmeta_library.enabled']],
        'library.folders' => ['group' => 'library', 'label' => 'Thư mục cần quét (mỗi dòng một đường dẫn)', 'description' => 'Đường dẫn tuyệt đối mà máy chạy JavMeta đọc được. Thư mục phải được gắn vào môi trường PHP dùng chung.', 'type' => 'string_list', 'config' => ['javmeta_library.folders']],
        'library.extensions' => ['group' => 'library', 'label' => 'Đuôi tệp video', 'description' => 'Cách nhau bằng dấu phẩy hoặc xuống dòng.', 'type' => 'string_list', 'config' => ['javmeta_library.extensions']],
        'library.scan_hour' => ['group' => 'library', 'label' => 'Giờ quét hằng ngày (0 đến 23)', 'description' => 'Theo múi giờ báo cáo. Có thể quét ngay bằng php artisan library:scan --force.', 'type' => 'int', 'config' => ['javmeta_library.scan_hour'], 'min' => 0, 'max' => 23],
        'library.hash_kilobytes' => ['group' => 'library', 'label' => 'Số KB đầu và cuối dùng để băm nhanh', 'description' => 'Mã băm nhanh = kích thước + phần đầu + phần cuối của tệp.', 'type' => 'int', 'config' => ['javmeta_library.hash_kilobytes'], 'min' => 1, 'max' => 65536],
        'downloads.enabled' => ['group' => 'downloads', 'label' => 'Bật tải về', 'description' => 'TẮT cho tới khi chủ sở hữu duyệt văn bản quyết định (JOOXCR-223). Chỉ nhận magnet mà JavMeta đã lưu cho một phim, mỗi lần tải là một thao tác của chủ sở hữu.', 'type' => 'bool', 'config' => ['javmeta_downloads.enabled']],
        'downloads.qbittorrent_url' => ['group' => 'downloads', 'label' => 'Địa chỉ qBittorrent Web UI', 'description' => 'Ví dụ http://192.168.2.10:8080 (mạng nội bộ).', 'type' => 'string', 'config' => ['javmeta_downloads.qbittorrent_url']],
        'downloads.qbittorrent_username' => ['group' => 'downloads', 'label' => 'Tài khoản qBittorrent', 'description' => '', 'type' => 'string', 'config' => ['javmeta_downloads.qbittorrent_username']],
        'downloads.qbittorrent_password' => ['group' => 'downloads', 'label' => 'Mật khẩu qBittorrent', 'description' => 'Mã hoá trong cơ sở dữ liệu, không hiển thị lại.', 'type' => 'secret', 'config' => ['javmeta_downloads.qbittorrent_password']],
        'downloads.category' => ['group' => 'downloads', 'label' => 'Danh mục (category) trong qBittorrent', 'description' => 'Chỉ các torrent trong danh mục này được liệt kê và quản lý.', 'type' => 'string', 'config' => ['javmeta_downloads.category']],
        'downloads.save_path' => ['group' => 'downloads', 'label' => 'Thư mục lưu', 'description' => 'Đường dẫn trên máy chạy qBittorrent; cũng là thư mục máy chủ tệp được phục vụ nếu máy JavMeta đọc được.', 'type' => 'string', 'config' => ['javmeta_downloads.save_path']],
        'downloads.timeout' => ['group' => 'downloads', 'label' => 'Thời gian chờ qBittorrent (giây)', 'description' => '', 'type' => 'int', 'config' => ['javmeta_downloads.timeout'], 'min' => 1, 'max' => 120],
        'files.enabled' => ['group' => 'files', 'label' => 'Bật máy chủ tệp', 'description' => 'TẮT cho tới khi chủ sở hữu duyệt (JOOXCR-223). Phục vụ tệp đã tải qua liên kết ký có thời hạn.', 'type' => 'bool', 'config' => ['javmeta_downloads.files_enabled']],
        'files.root_folders' => ['group' => 'files', 'label' => 'Thư mục được phép phục vụ (mỗi dòng một đường dẫn)', 'description' => 'Tệp ngoài các thư mục này không bao giờ được phục vụ.', 'type' => 'string_list', 'config' => ['javmeta_downloads.root_folders']],
        'files.link_ttl_minutes' => ['group' => 'files', 'label' => 'Liên kết hết hạn sau (phút)', 'description' => '', 'type' => 'int', 'config' => ['javmeta_downloads.link_ttl_minutes'], 'min' => 1, 'max' => 1440],
        'files.allowed_networks' => ['group' => 'files', 'label' => 'Mạng được truy cập (CIDR, mỗi dòng một mạng)', 'description' => 'Chỉ địa chỉ trong các mạng này mở được liên kết. Mặc định là mạng nội bộ.', 'type' => 'string_list', 'config' => ['javmeta_downloads.allowed_networks']],
        'domains.ui_host' => ['group' => 'domains', 'label' => 'Tên miền giao diện XCrawler', 'description' => 'Giao diện và API. Chỉ để ghi nhớ: HAProxy và Apache quyết định tên miền thật.', 'type' => 'string', 'config' => ['javmeta_domains.ui_host']],
        'domains.command_center_host' => ['group' => 'domains', 'label' => 'Tên miền Command Center', 'description' => 'Trang vận hành (/ops). Đăng nhập ở tên miền này vào thẳng /ops; giao diện XCrawler trỏ nút Vận hành về đây. Để trống nếu không tách tên miền.', 'type' => 'string', 'config' => ['javmeta_domains.command_center_host']],
        'backup.stale_after_seconds' => ['group' => 'backup', 'label' => 'Bản sao lưu coi là cũ sau (giây)', 'description' => 'Dùng cho cảnh báo sao lưu hằng phút.', 'type' => 'int', 'config' => ['backup.stale_after_seconds'], 'min' => 3600, 'max' => 2592000],
    ],
];

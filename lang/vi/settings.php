<?php

declare(strict_types=1);

return [
    'groups' => [
        'queue' => 'Hàng đợi',
        'worker' => 'Worker',
        'browser' => 'Trình duyệt',
        'retry' => 'Làn thử lại',
        'cleanup' => 'Dọn dẹp',
        'timeouts' => 'Thời gian chờ',
        'crawl' => 'Vòng lặp crawl',
        'alerts' => 'Cảnh báo',
        'notifications' => 'Chính sách thông báo',
        'telegram' => 'Telegram',
        'report' => 'Báo cáo',
        'readiness' => 'Kiểm tra sẵn sàng',
        'search' => 'Tìm kiếm',
        'observability' => 'Giám sát',
        'stats' => 'Thống kê crawl',
        'backup' => 'Sao lưu',
        'ai' => 'AI (Ollama, embedding)',
        'follow_notifications' => 'Thông báo theo dõi (Telegram)',
        'domains' => 'Tên miền',
        'library' => 'Thư viện trên đĩa',
        'dataset' => 'Dữ liệu mẫu',
        'downloads' => 'Tải về (qBittorrent)',
        'files' => 'Máy chủ tệp',
        'media' => 'Xử lý media',
        'capture' => 'Ghi luồng',
        'ui_security' => 'Bảo mật giao diện',
        'ui' => 'Giao diện người dùng',
        'nodes' => 'Thêm node',
        'startup_health' => 'Sức khỏe khi khởi động',
        'normalization' => 'Performer normalization',
    ],
    'definitions' => [
        'health__startup__enabled' => [
            'label' => 'Bật cổng kiểm tra khi khởi động',
            'description' => 'Chặn lệnh chạy nền và yêu cầu web khi PostgreSQL, Redis hoặc MongoDB không sẵn sàng.',
        ],
        'health__startup__timeout_seconds' => [
            'label' => 'Thời gian chờ mỗi dịch vụ (giây)',
            'description' => 'Giới hạn cho từng lần kiểm tra kết nối; tối đa 2 giây.',
        ],
        'health__startup__wait_seconds' => [
            'label' => 'Chờ dịch vụ chính khởi động (giây)',
            'description' => 'Thời hạn mặc định health:startup thử lại các dịch vụ chính.',
        ],
        'health__startup__retry_backoff_seconds' => [
            'label' => 'Khoảng nghỉ thử lại (giây)',
            'description' => 'Khoảng nghỉ giữa các lần kiểm tra lại dịch vụ chính.',
        ],
        'health__startup__snapshot_ttl_seconds' => [
            'label' => 'Thời hạn ảnh chụp sức khỏe web (giây)',
            'description' => 'Khoảng thời gian dùng lại kết quả kiểm tra chính để giảm độ trễ request.',
        ],
        'health__startup__primary__web' => [
            'label' => 'Dịch vụ chính của nút web',
            'description' => 'Mỗi dòng một dịch vụ. PostgreSQL, Redis và MongoDB luôn được kiểm tra; Elasticsearch mặc định là dịch vụ chính của nút web.',
        ],
        'health__startup__primary__worker' => [
            'label' => 'Dịch vụ chính của worker',
            'description' => 'Mỗi dòng một dịch vụ. Elasticsearch mặc định là phụ trên worker crawl.',
        ],
        'health__startup__primary__scheduler' => [
            'label' => 'Dịch vụ chính của scheduler',
            'description' => 'Mỗi dòng một dịch vụ. PostgreSQL, Redis và MongoDB luôn được kiểm tra.',
        ],
        'health__startup__command_allowlist' => [
            'label' => 'Lệnh được miễn cổng khởi động',
            'description' => 'Mỗi dòng một tên hoặc mẫu lệnh Artisan; ví dụ health:*, settings:*, migrate*.',
        ],
        'health__startup__command_denylist' => [
            'label' => 'Lệnh luôn bị kiểm tra khi khởi động',
            'description' => 'Mỗi dòng một tên hoặc mẫu lệnh Artisan; danh sách này có ưu tiên cao hơn danh sách miễn.',
        ],
        'health__startup__flaresolverr_url' => [
            'label' => 'Địa chỉ FlareSolverr',
            'description' => 'Địa chỉ dịch vụ trình duyệt phụ; để trống nếu không dùng.',
        ],
        'health__startup__playwright_url' => [
            'label' => 'Địa chỉ browser gateway',
            'description' => 'Địa chỉ dịch vụ Playwright phụ; để trống nếu không dùng.',
        ],
        'ui__locale' => [
            'label' => 'Ngôn ngữ giao diện',
            'description' => 'Tiếng Việt hoặc English cho giao diện chủ sở hữu; trình duyệt khách vẫn chọn riêng trên máy.',
        ],
        'notifications__locale' => [
            'label' => 'Ngôn ngữ mẫu thông báo',
            'description' => 'Ngôn ngữ mẫu Telegram/Alertmanager khi gửi. Mặc định English; đổi sang tiếng Việt chỉ khi chủ sở hữu chọn.',
        ],
        'ui_guest__rate_limit_per_minute' => [
            'label' => 'Giới hạn yêu cầu giao diện khách mỗi phút theo IP',
            'description' => 'Số yêu cầu tối đa mỗi phút từ một địa chỉ IP đến cầu nối giao diện công khai chỉ đọc.',
        ],
        'ui_security__login_required' => [
            'label' => 'Yêu cầu đăng nhập chủ sở hữu',
            'description' => 'Bảo vệ giao diện và cấu hình quản trị bằng phiên đăng nhập chủ sở hữu.',
        ],
        'ui_security__owner_admin_token' => [
            'label' => 'Token quản trị chủ sở hữu',
            'description' => 'Được mã hóa trong cơ sở dữ liệu và không hiển thị lại.',
        ],
        'ui_security__mcp_api_key' => [
            'label' => 'API key MCP cũ',
            'description' => 'Được mã hóa trong cơ sở dữ liệu và không hiển thị lại; API key mới nên dùng scope MCP.',
        ],
        'nodes__enrollment_ttl_minutes' => [
            'label' => 'Thời hạn mã thêm node (phút)',
            'description' => 'Mã dùng một lần để thêm node hết hạn sau số phút này.',
        ],
        'nodes__release_repo_url' => [
            'label' => 'Kho mã nguồn cho node mới',
            'description' => 'Địa chỉ Git mà node mới dùng để cài đặt; node phải đọc được kho này.',
        ],
        'nodes__release_ref' => [
            'label' => 'Nhánh, tag hoặc commit cho node mới',
            'description' => 'Bản phát hành mà node mới cài đặt; nên trùng bản đang chạy.',
        ],
        'dataset__allowed_databases' => [
            'label' => 'Cơ sở dữ liệu cho phép nạp dữ liệu mẫu',
            'description' => 'Chỉ cho phép nạp và xóa dữ liệu mẫu trên các cơ sở dữ liệu này; ngoài kiểm thử luôn cần --force.',
        ],
        'perf__warm_enabled' => [
            'label' => 'Làm ấm bộ nhớ đệm',
            'description' => 'Tự làm mới trạng thái và bộ lọc toàn bộ danh mục theo lịch, giảm thời gian chờ khi bộ nhớ đệm hết hạn.',
        ],
        'search__budget_ms' => [
            'label' => 'Ngân sách tìm kiếm (mili giây)',
            'description' => 'Tổng thời gian dành cho HTTP tùy chọn trong một lượt tìm kiếm kết hợp. Hết thời gian thì trả kết quả từ khóa; không giới hạn truy vấn cơ sở dữ liệu.',
        ],
        'capture__enabled' => [
            'label' => 'Bật ghi luồng',
            'description' => 'Mặc định TẮT. Chỉ bắt đầu từ thao tác rõ ràng của chủ sở hữu; luôn từ chối DRM.',
        ],
        'capture__max_duration_sec' => [
            'label' => 'Thời lượng ghi tối đa (giây)',
            'description' => 'Giới hạn một lần ghi.',
        ],
        'capture__max_bytes' => [
            'label' => 'Dung lượng ghi tối đa (byte)',
            'description' => 'Dừng trước khi đầy ổ đĩa.',
        ],
        'capture__max_playlists' => [
            'label' => 'Số playlist lồng nhau tối đa',
            'description' => 'Từ chối vòng lặp và playlist quá lớn.',
        ],
        'capture__max_manifest_bytes' => [
            'label' => 'Dung lượng manifest tối đa (byte)',
            'description' => 'Giới hạn trang HTML và playlist tải để kiểm tra.',
        ],
        'capture__http_timeout_sec' => [
            'label' => 'Thời gian chờ HTTP khi ghi (giây)',
            'description' => 'Không dùng cookie hoặc tài khoản để vượt bảo vệ.',
        ],
        'media__vision_model' => [
            'label' => 'Mô hình mô tả cảnh',
            'description' => 'Mô hình vision Ollama nội bộ; tuyệt đối không gửi ảnh ra cloud.',
        ],
        'media__scenes_index' => [
            'label' => 'Chỉ mục tìm cảnh Elasticsearch',
            'description' => 'Chỉ mục được tạo khi có vector đầu tiên. Đổi tên cần lập chỉ mục lại.',
        ],
        'media__embedding_dimensions' => [
            'label' => 'Số chiều vector cảnh',
            'description' => 'Phải khớp dịch vụ embedder và mapping Elasticsearch.',
        ],
        'media__translate_model' => [
            'label' => 'Mô hình dịch phụ đề',
            'description' => 'Chỉ chạy mô hình Ollama cục bộ để dịch sang EN và VI.',
        ],
        'media__translate_batch_segments' => [
            'label' => 'Số câu phụ đề mỗi đợt dịch',
            'description' => 'Giữ nguyên mốc thời gian và số thứ tự.',
        ],
        'media__ollama_timeout_sec' => [
            'label' => 'Thời gian chờ Ollama media (giây)',
            'description' => 'Lỗi thì dừng; không dùng cloud.',
        ],
        'media__whisper_model' => [
            'label' => 'Mô hình nhận dạng giọng nói',
            'description' => 'Whisper chạy CUDA float16 trên GPU nội bộ; không chuyển sang cloud.',
        ],
        'media__api_url' => [
            'label' => 'Địa chỉ JavMeta cho worker',
            'description' => 'Địa chỉ nội bộ của API JavMeta.',
        ],
        'media__output_folder' => [
            'label' => 'Thư mục output media',
            'description' => 'Ghi ảnh, phụ đề và tệp MP4 đã ghi luồng tại đây; không ghi vào thư viện.',
        ],
        'media__max_parallel' => [
            'label' => 'Số việc media đồng thời',
            'description' => 'Giới hạn chung cho mọi worker.',
        ],
        'media__max_attempts' => [
            'label' => 'Số lần thử media tối đa',
            'description' => 'Sau mức này cần chủ sở hữu bấm thử lại.',
        ],
        'media__lease_seconds' => [
            'label' => 'Thời hạn lượt xử lý (giây)',
            'description' => 'Phải lớn hơn thời gian tối đa một việc.',
        ],
        'media__job_timeout_sec' => [
            'label' => 'Thời gian tối đa một việc media',
            'description' => 'Dừng tiến trình quá hạn (giây).',
        ],
        'media__poll_sec' => [
            'label' => 'Nghỉ khi hàng đợi trống (giây)',
            'description' => 'Worker đọc lại cấu hình mỗi lượt.',
        ],
        'media__thumb_interval_sec' => [
            'label' => 'Khoảng cách ảnh preview (giây)',
            'description' => 'Lấy một ảnh sau mỗi khoảng này.',
        ],
        'media__scene_threshold' => [
            'label' => 'Ngưỡng chuyển cảnh',
            'description' => 'Độ khác biệt hình ảnh để tách cảnh.',
        ],
        'media__ffmpeg_threads' => [
            'label' => 'Số luồng ffmpeg',
            'description' => 'Giới hạn CPU để giữ máy đáp ứng.',
        ],
        'queue__workers_per_queue' => [
            'label' => 'Worker mặc định mỗi hàng đợi',
            'description' => 'Số tiến trình Horizon mặc định cho mỗi hàng đợi; cấu hình theo làn, loại hàng đợi và node có thể ghi đè.',
        ],
        'worker__direct_workers_per_queue' => [
            'label' => 'Worker mỗi hàng đợi làn trực tiếp',
            'description' => 'Để trống để dùng số worker mặc định; thiết lập riêng cho các queue không dùng trình duyệt.',
        ],
        'worker__browser_workers_per_queue' => [
            'label' => 'Worker mỗi hàng đợi làn trình duyệt',
            'description' => 'Số tiến trình Horizon tối đa cho từng queue cần Playwright hoặc FlareSolverr.',
        ],
        'worker__retry_workers_per_queue' => [
            'label' => 'Worker mỗi hàng đợi làn thử lại',
            'description' => 'Số tiến trình Horizon cho từng queue retry; mặc định chỉ dùng một tiến trình.',
        ],
        'worker__queue_overrides' => [
            'label' => 'Worker ghi đè theo hàng đợi (JSON)',
            'description' => 'Đối tượng JSON ánh xạ tên queue sang số worker từ 1 đến 32, ví dụ {"listing":2,"browser_detail":1}.',
        ],
        'worker__node_overrides' => [
            'label' => 'Worker ghi đè theo node (JSON)',
            'description' => 'Ánh xạ main, worker hoặc role@hostname sang default, direct, browser, retry và queues.',
        ],
        'worker__queue_names' => [
            'label' => 'Tên hàng đợi cần chạy',
            'description' => 'Mỗi worker Horizon giám sát một tên trong danh sách; queue crawl tự thêm queue trình duyệt tương ứng.',
        ],
        'worker__queue_tries' => [
            'label' => 'Số lần thử lại hàng đợi',
            'description' => 'Số lần Horizon thử một job trước khi đánh dấu thất bại.',
        ],
        'worker__queue_max_time' => [
            'label' => 'Thời gian tối đa tiến trình worker (giây)',
            'description' => 'Horizon thay tiến trình sau khoảng thời gian này để giải phóng bộ nhớ.',
        ],
        'worker__queue_max_jobs' => [
            'label' => 'Số job tối đa mỗi tiến trình',
            'description' => 'Horizon thay tiến trình sau số job này; 0 không được dùng để tránh tiến trình chạy vô hạn.',
        ],
        'worker__queue_backoff_seconds' => [
            'label' => 'Nghỉ trước khi thử lại job (giây)',
            'description' => 'Khoảng nghỉ cơ bản của Horizon trước khi thử lại job lỗi.',
        ],
        'worker__queue_sleep_seconds' => [
            'label' => 'Thời gian nghỉ giữa hai lần lấy job (giây)',
            'description' => 'Worker dùng giá trị này khi hàng đợi chưa có việc.',
        ],
        'worker__stop_grace_seconds' => [
            'label' => 'Thời gian chờ worker dừng an toàn (giây)',
            'description' => 'Horizon cho phép job đang chạy hoàn tất trong giới hạn này; giá trị không được thấp hơn worker timeout.',
        ],
        'worker__reconcile_cooldown_seconds' => [
            'label' => 'Khoảng cách tối thiểu giữa hai lần đổi worker (giây)',
            'description' => 'Giữ mỗi node cách nhau ít nhất mức này giữa hai lần thay đổi số worker.',
        ],
        'worker__reconcile_jitter_seconds' => [
            'label' => 'Độ trễ ổn định giữa các node (giây)',
            'description' => 'Mỗi node chờ một khoảng khác nhau trước khi đổi số worker; khóa đội máy đảm bảo chỉ một node đổi tại một thời điểm.',
        ],
        'queue__depth_limit' => [
            'label' => 'Ngưỡng hàng đợi đầy',
            'description' => 'Trên số việc chờ này, kiểm tra sẵn sàng báo hàng đợi đầy.',
        ],
        'queue__per_source_inflight' => [
            'label' => 'Việc đồng thời mỗi nguồn',
            'description' => 'Tối đa số việc của một nguồn được chạy cùng lúc.',
        ],
        'queue__feed_pool_limit' => [
            'label' => 'Việc nạp mỗi lượt',
            'description' => 'Tối đa số dòng crawl_queue được nạp trong một lượt feed pool.',
        ],
        'queue__feed_interval_seconds' => [
            'label' => 'Chu kỳ nạp hàng đợi (giây)',
            'description' => 'Chọn 5, 10, 15, 30 hoặc 60 giây; feeder kiểm tra cấu hình mỗi 5 giây nên thay đổi không cần khởi động lại scheduler.',
        ],
        'queue__pacing_horizon_seconds' => [
            'label' => 'Tầm giãn nhịp (giây)',
            'description' => 'Cửa sổ dùng để chia nhịp request của mỗi nguồn.',
        ],
        'queue__feed_diagnostics__enabled' => [
            'label' => 'Bật chẩn đoán feed pool',
            'description' => 'Ghi số liệu budget, lượt chạy và nguyên nhân dừng của feed pool vào Redis.',
        ],
        'queue__feed_diagnostics__retention_minutes' => [
            'label' => 'Thời hạn lưu chẩn đoán feed pool (phút)',
            'description' => 'Số phút giữ bộ đếm feed pool trong Redis để xuất metric và xem báo cáo.',
        ],
        'queue__gap_blocked_max_seconds' => [
            'label' => 'Khoảng nghỉ tối đa khi bị chặn (giây)',
            'description' => 'Khi một nguồn bị chặn, khoảng nghỉ giữa các request tăng gấp đôi đến mức này.',
        ],
        'queue__retry_after_seconds' => [
            'label' => 'Thời gian giữ job trước khi thử lại (giây)',
            'description' => 'Phải dài hơn timeout của worker để không chạy trùng một job còn đang xử lý.',
        ],
        'queue__observability_sample_percent' => [
            'label' => 'Tỷ lệ lấy mẫu telemetry (%)',
            'description' => 'Chọn phần trăm request được đưa vào hàng đợi OpenObserve.',
        ],
        'queue__observability_depth_limit' => [
            'label' => 'Độ sâu tối đa hàng đợi telemetry',
            'description' => 'Giới hạn số sự kiện đang chờ gửi sang OpenObserve.',
        ],
        'browser__max_concurrency' => [
            'label' => 'Số trình duyệt chạy đồng thời',
            'description' => 'Giới hạn số phiên trình duyệt hoạt động trên máy dịch vụ.',
        ],
        'browser__flaresolverr_url' => [
            'label' => 'Địa chỉ dịch vụ FlareSolverr',
            'description' => 'Địa chỉ dịch vụ giải CAPTCHA trong mạng nội bộ; thay đổi áp dụng cho các job mới.',
        ],
        'browser__playwright_url' => [
            'label' => 'Địa chỉ dịch vụ Playwright',
            'description' => 'Địa chỉ gateway Playwright trong mạng nội bộ; thay đổi áp dụng cho các job mới.',
        ],
        'browser__max_queue' => [
            'label' => 'Số yêu cầu trình duyệt chờ tối đa',
            'description' => 'Giới hạn độ dài hàng đợi của dịch vụ trình duyệt.',
        ],
        'browser__playwright_request_timeout_ms' => [
            'label' => 'Timeout request Playwright (mili giây)',
            'description' => 'Giới hạn thời gian một yêu cầu điều hướng trên dịch vụ trình duyệt.',
        ],
        'browser__max_requests_per_browser' => [
            'label' => 'Số request tối đa mỗi trình duyệt',
            'description' => 'Đóng và tạo lại phiên trình duyệt sau số request này.',
        ],
        'browser__flaresolverr_max_timeout_ms' => [
            'label' => 'Timeout tối đa FlareSolverr (mili giây)',
            'description' => 'Giới hạn thời gian dịch vụ FlareSolverr chờ phản hồi của trang.',
        ],
        'browser__gateway_wait_timeout_ms' => [
            'label' => 'Gateway chờ dịch vụ tối đa (mili giây)',
            'description' => 'Giới hạn thời gian gateway chờ kết quả từ dịch vụ trình duyệt.',
        ],
        'browser__gateway_client_timeout_ms' => [
            'label' => 'Timeout client gateway (mili giây)',
            'description' => 'Phải dài hơn thời gian gateway chờ dịch vụ để client nhận được kết quả.',
        ],
        'browser__config_poll_interval_seconds' => [
            'label' => 'Chu kỳ lấy cấu hình dịch vụ (giây)',
            'description' => 'Browser gateway và watchdog đọc lại cấu hình qua API theo chu kỳ này.',
        ],
        'browser__config_api_client_ms' => [
            'label' => 'Timeout client lấy cấu hình (mili giây)',
            'description' => 'Thời gian tối đa remote service chờ API cấu hình XCrawler.',
        ],
        'timeouts__fetch_service_seconds' => [
            'label' => 'Timeout dịch vụ fetch (giây)',
            'description' => 'Giới hạn một phương thức fetch; timeout client của worker phải dài hơn giá trị này.',
        ],
        'timeouts__playwright_seconds' => [
            'label' => 'Timeout tối đa Playwright (giây)',
            'description' => 'Dừng lần gọi Playwright và giới hạn timeout browser service qua API cấu hình để giải phóng slot.',
        ],
        'crawl__fetch_method_window_size' => [
            'label' => 'Số lần thử trong cửa sổ chọn phương thức',
            'description' => 'Số kết quả Playwright gần nhất dùng để học phương thức fetch cho từng nguồn và máy chủ.',
        ],
        'crawl__fetch_method_failure_threshold' => [
            'label' => 'Số lỗi Playwright để ưu tiên FlareSolverr',
            'description' => 'Cần đạt ngưỡng này trong cửa sổ và có ít nhất một lần FlareSolverr thành công gần đây; ngưỡng thực tế không vượt kích thước cửa sổ.',
        ],
        'crawl__fetch_method_window_ttl_seconds' => [
            'label' => 'Thời hạn kết quả học phương thức (giây)',
            'description' => 'Thời gian giữ cửa sổ thành công và thất bại trong cache dùng chung.',
        ],
        'crawl__fetch_method_preference_ttl_seconds' => [
            'label' => 'Thời gian ưu tiên FlareSolverr (giây)',
            'description' => 'Thời hạn ưu tiên FlareSolverr sau khi Playwright vượt ngưỡng lỗi; probe thành công sẽ bỏ ưu tiên sớm.',
        ],
        'crawl__fetch_method_probe_interval' => [
            'label' => 'Khoảng request giữa các lần probe Playwright',
            'description' => 'Cứ mỗi số request này, thử Playwright trước để phát hiện khi nguồn đã hoạt động lại.',
        ],
        'crawl__source_gap_overrides' => [
            'label' => 'Khoảng request ghi đè theo nguồn (JSON)',
            'description' => 'Ánh xạ mã nguồn đã cấu hình hoặc đã lưu sang min, default và max từ 0,25 đến 600 giây; để trống thì giữ nhịp mặc định. 14 nguồn CrawlerX 1.5.0 bắt đầu ở 8 giây (min 4, max 60); chỉnh nhịp qua ánh xạ này.',
        ],
        'queue__browser_inflight' => [
            'label' => 'Việc dùng trình duyệt cùng lúc (tổng)',
            'description' => 'Giới hạn chung các job dùng FlareSolverr hoặc Playwright để tránh quá tải dịch vụ.',
        ],
        'queue__browser_depth_limit' => [
            'label' => 'Độ sâu queue trình duyệt',
            'description' => 'Số job chờ tối đa trong mỗi queue làn trình duyệt.',
        ],
        'retry__depth_limit' => [
            'label' => 'Độ sâu queue thử lại',
            'description' => 'Số job chờ tối đa trong mỗi queue riêng của làn thử lại.',
        ],
        'retry__feed_pool_limit' => [
            'label' => 'Ngân sách dispatch thử lại mỗi phút',
            'description' => 'Giới hạn riêng cho số job làn thử lại được đưa vào worker mỗi phút.',
        ],
        'retry__pacing_horizon_seconds' => [
            'label' => 'Cửa sổ pacing làn thử lại (giây)',
            'description' => 'Cửa sổ riêng để giới hạn lượng việc thử lại đang chạy hoặc đang chờ theo từng nguồn.',
        ],
        'retry__pacing_gap_multiplier' => [
            'label' => 'Hệ số giãn cách request thử lại',
            'description' => 'Nhân khoảng nghỉ hiện tại của nguồn để retry chậm hơn luồng crawl thường.',
        ],
        'retry__per_source_inflight' => [
            'label' => 'Việc thử lại đồng thời mỗi nguồn',
            'description' => 'Giới hạn riêng số job retry đang chờ hoặc chạy đồng thời cho mỗi nguồn.',
        ],
        'retry__delays_seconds' => [
            'label' => 'Thời gian chờ giữa các vòng thử lại (giây)',
            'description' => 'Danh sách thời gian chờ trước mỗi vòng; mặc định là 30 phút, 2 giờ và 6 giờ.',
        ],
        'retry__max_rounds' => [
            'label' => 'Số vòng thử lại tối đa',
            'description' => 'Sau số vòng này, lỗi còn lại được giữ ở trạng thái thất bại vĩnh viễn.',
        ],
        'retry__attempts_per_round' => [
            'label' => 'Số lần thử trong mỗi vòng retry',
            'description' => 'Mỗi vòng retry dùng số lần thử này trước khi tăng vòng hoặc thất bại vĩnh viễn.',
        ],
        'retry__import_slice_size' => [
            'label' => 'Kích thước mỗi lát nhập lỗi cũ',
            'description' => 'Số dòng failed tối đa được kiểm tra và cập nhật trong một lát lệnh import.',
        ],
        'retry__fetch_profile' => [
            'label' => 'Hồ sơ fetch làn thử lại (JSON)',
            'description' => 'Ghi đè tùy chọn endpoint trình duyệt, HTTP proxy, nhãn egress và thứ tự fetch riêng cho retry.',
        ],
        'queue__browser_sources' => [
            'label' => 'Nguồn cần trình duyệt (mỗi dòng một mã nguồn)',
            'description' => 'Danh sách nguồn bắt buộc dùng dịch vụ trình duyệt.',
        ],
        'crawl__lanes_enabled' => [
            'label' => 'Tách làn crawl',
            'description' => 'Tách queue HTTP trực tiếp và trình duyệt; job đang chờ vẫn được xử lý khi tắt.',
        ],
        'crawl__lane_browser_percent' => [
            'label' => 'Tỷ lệ dùng trình duyệt (%)',
            'description' => 'Nguồn đạt tỷ lệ fetch qua trình duyệt này được tự chuyển làn khi kiểm tra sức khỏe.',
        ],
        'crawl__lane_min_attempts' => [
            'label' => 'Số fetch tối thiểu để phân làn',
            'description' => 'Chỉ tự phân làn khi có đủ lần fetch trong 24 giờ; bỏ qua lần bị bỏ qua.',
        ],
        'crawl__lane_direct_pins' => [
            'label' => 'Ghim nguồn vào làn trực tiếp',
            'description' => 'Mỗi dòng là một mã nguồn ưu tiên dùng fetch trực tiếp.',
        ],
        'crawl__lane_browser_pins' => [
            'label' => 'Ghim nguồn vào làn trình duyệt',
            'description' => 'Mỗi dòng là một mã nguồn ưu tiên dùng trình duyệt.',
        ],
        'queue__worker_timeout' => [
            'label' => 'Timeout client fetch (giây)',
            'description' => 'Thời gian tối đa worker chờ fetch; phải dài hơn timeout của dịch vụ fetch.',
        ],
        'crawl__stale_claim_timeout_seconds' => [
            'label' => 'Việc kẹt được giải phóng sau (giây)',
            'description' => 'Việc đã bắt đầu được thu hồi sau mức này; việc chưa bắt đầu còn được chờ theo giới hạn hàng đợi và timeout worker.',
        ],
        'crawl__block_delays_seconds' => [
            'label' => 'Thời gian chờ khi bị chặn (giây, cách nhau bằng dấu phẩy)',
            'description' => 'Các lần chặn/challenge trong luồng thường dùng thời gian chờ theo danh sách này trước khi chuyển sang làn retry.',
        ],
        'crawl__retry_backoff_seconds' => [
            'label' => 'Backoff giữa các lần thử crawl (giây)',
            'description' => 'Khoảng nghỉ theo số lần thử trước khi chuyển sang vòng retry riêng.',
        ],
        'crawl__fresh_interval_minutes' => [
            'label' => 'Đọc lại trang đầu mỗi (phút)',
            'description' => 'Chu kỳ đọc lại trang đầu danh sách để bắt phim mới.',
        ],
        'crawl__loop_pause_seconds' => [
            'label' => 'Nghỉ giữa hai vòng danh sách (giây)',
            'description' => 'Sau khi đọc hết danh sách, chờ rồi bắt đầu lại từ trang 1.',
        ],
        'crawl__recrawl_after_days' => [
            'label' => 'Crawl lại phim thiếu thông tin sau (ngày)',
            'description' => 'Phim dưới tầng đầy đủ tối thiểu được crawl lại khi lần crawl cuối cũ hơn mức này.',
        ],
        'crawl__recrawl_min_tier' => [
            'label' => 'Tầng đầy đủ tối thiểu',
            'description' => 'Phim dưới tầng này được xem là thiếu thông tin.',
        ],
        'crawl__recrawl_batch' => [
            'label' => 'Số phim crawl lại mỗi đợt',
            'description' => 'Mỗi giờ crawl lại tối đa bấy nhiêu phim thiếu thông tin.',
        ],
        'crawl__recrawl_max_rounds' => [
            'label' => 'Số lần crawl lại tối đa mỗi phim',
            'description' => 'Sau số lần này phim không được crawl lại nữa.',
        ],
        'crawl__max_attempts' => [
            'label' => 'Số lần thử crawl tối đa',
            'description' => 'Sau số lần này lỗi tạm thời chuyển sang làn retry riêng; lỗi parse và lỗi kết thúc vẫn thất bại ngay.',
        ],
        'crawl__circuit_failure_threshold' => [
            'label' => 'Số lỗi mở mạch nguồn',
            'description' => 'Sau số lỗi liên tiếp này, tạm dừng yêu cầu tới nguồn.',
        ],
        'crawl__circuit_cooldown_seconds' => [
            'label' => 'Thời gian nghỉ mạch nguồn (giây)',
            'description' => 'Khoảng chờ trước khi thử lại nguồn bị mở mạch.',
        ],
        'alerts__enabled' => [
            'label' => 'Bật cảnh báo',
            'description' => 'Tắt thì không có cảnh báo nào được gửi.',
        ],
        'alerts__evaluate_interval_seconds' => [
            'label' => 'Kiểm tra cảnh báo mỗi (giây)',
            'description' => 'Lệnh chạy mỗi phút và bỏ qua lượt chưa đến thời điểm kiểm tra này.',
        ],
        'alerts__window_minutes' => [
            'label' => 'Cửa sổ đánh giá crawl (phút)',
            'description' => 'Khoảng thời gian dùng để tính tỷ lệ thành công và bị chặn.',
        ],
        'alerts__success_rate_min_percent' => [
            'label' => 'Tỷ lệ tải thành công tối thiểu (%)',
            'description' => 'Cảnh báo khi tỷ lệ phản hồi HTTP 2xx thấp hơn ngưỡng này.',
        ],
        'alerts__success_rate_critical_percent' => [
            'label' => 'Ngưỡng tỷ lệ thành công nghiêm trọng (%)',
            'description' => 'Báo CRITICAL khi tỷ lệ thành công thấp hơn ngưỡng này trong cửa sổ đánh giá.',
        ],
        'alerts__blocked_ratio_max_percent' => [
            'label' => 'Tỷ lệ bị chặn tối đa (%)',
            'description' => 'Cảnh báo khi tỷ lệ phản hồi 403, 429 hoặc dấu hiệu Cloudflare vượt ngưỡng này.',
        ],
        'alerts__pg_connections_max' => [
            'label' => 'Số kết nối PostgreSQL tối đa',
            'description' => 'Cảnh báo khi số kết nối client vượt ngưỡng này.',
        ],
        'alerts__dispatch_age_max_seconds' => [
            'label' => 'Chưa điều phối crawl quá (giây)',
            'description' => 'Cảnh báo khi còn việc đến hạn nhưng chưa có lần điều phối trong khoảng này.',
        ],
        'alerts__crawl_dispatch_warning_seconds' => [
            'label' => 'Ngưỡng metric điều phối crawl (giây)',
            'description' => 'Ngưỡng warning của metric javmeta_crawl_dispatch_stale; cảnh báo trực tiếp dùng ngưỡng riêng bên trên.',
        ],
        'alerts__backlog_due_min_per_hot_source' => [
            'label' => 'Việc đến hạn tối thiểu mỗi nguồn nóng',
            'description' => 'Cảnh báo khi nguồn đang crawl còn ít việc đến hạn hơn mức này.',
        ],
        'alerts__min_attempts_in_window' => [
            'label' => 'Số lần thử tối thiểu trong cửa sổ',
            'description' => 'Không tính tỷ lệ khi mẫu nhỏ hơn mức này.',
        ],
        'alerts__cooldown_minutes' => [
            'label' => 'Nhắc lại sau (phút)',
            'description' => 'Nếu sự cố còn tiếp diễn, gửi lại tối đa một lần sau khoảng này.',
        ],
        'alerts__recovery_notice' => [
            'label' => 'Gửi tin khi đã phục hồi',
            'description' => 'Gửi một tin khi điều kiện cảnh báo trở lại bình thường.',
        ],
        'alerts__recovery_retry_max_attempts' => [
            'label' => 'Số lần thử gửi tin phục hồi tối đa',
            'description' => 'Sau mỗi khoảng nhắc lại, thử gửi lại tin phục hồi chưa tới được kênh; hết số lần này thì dừng.',
        ],
        'alerts__channels' => [
            'label' => 'Kênh nhận cảnh báo',
            'description' => 'Mỗi dòng chọn một kênh đã bật: telegram hoặc webhook.',
        ],
        'alerts__webhook_url' => [
            'label' => 'Địa chỉ webhook cảnh báo',
            'description' => 'URL HTTPS không chứa thông tin đăng nhập, query hoặc fragment; để trống nếu không dùng webhook.',
        ],
        'alerts__webhook_timeout_seconds' => [
            'label' => 'Webhook chờ tối đa (giây)',
            'description' => 'Giới hạn thời gian chờ gửi cảnh báo tới webhook.',
        ],
        'alerts__history_retention_days' => [
            'label' => 'Số ngày giữ lịch sử cảnh báo',
            'description' => 'Lịch sử đã xử lý cũ hơn mức này được tự động xóa.',
        ],
        'alerts__debounce_seconds' => [
            'label' => 'Gộp cảnh báo trùng trong (giây)',
            'description' => 'Cùng một cảnh báo chỉ gửi một lần trong khoảng này.',
        ],
        'alerts__throttle_seconds' => [
            'label' => 'Tối đa một cảnh báo mỗi nguyên nhân trong (giây)',
            'description' => 'Áp dụng cho watchdog, sao lưu, crawl đứng, hàng đợi kẹt.',
        ],
        'alerts__miss_debounce_seconds' => [
            'label' => 'Gộp cảnh báo thiếu dữ liệu (giây)',
            'description' => 'Khoảng gộp cho cảnh báo phim hoặc diễn viên bị hỏi mà chưa có.',
        ],
        'alerts__title_failure_threshold' => [
            'label' => 'Số lần lỗi liên tiếp mới cảnh báo',
            'description' => 'Một phim lỗi quá số lần này sẽ được cảnh báo.',
        ],
        'alerts__heartbeat_stale_seconds' => [
            'label' => 'Worker im lặng quá (giây) thì cảnh báo',
            'description' => 'Watchdog coi nhịp tim worker cũ hơn mức này là không hoạt động.',
        ],
        'alerts__source_stale_seconds' => [
            'label' => 'Nguồn không thành công quá (giây) thì cảnh báo',
            'description' => 'Ngưỡng mặc định; chu kỳ nguồn trong manifest được cộng thêm một lượt quét.',
        ],
        'alerts__crawl_stall_seconds' => [
            'label' => 'Crawl đứng quá (giây) thì cảnh báo',
            'description' => 'Watchdog kiểm tra khoảng thời gian không có phim mới.',
        ],
        'alerts__queue_stuck_seconds' => [
            'label' => 'Hàng đợi kẹt quá (giây) thì cảnh báo',
            'description' => 'Watchdog kiểm tra việc chờ quá lâu mà chưa được xử lý.',
        ],
        'alerts__crawl_dispatch_critical_seconds' => [
            'label' => 'Ngưỡng metric điều phối crawl mức cao (giây)',
            'description' => 'Ngưỡng critical của metric javmeta_crawl_dispatch_stale; cảnh báo trực tiếp dùng ngưỡng riêng.',
        ],
        'cleanup__pending_queue_limit' => [
            'label' => 'Ngưỡng việc chờ trước khi báo động',
            'description' => 'Watchdog cảnh báo khi số job chờ vượt mức này.',
        ],
        'cleanup__watchdog_stall_after_minutes' => [
            'label' => 'Ngưỡng watchdog 45 phút (phút)',
            'description' => 'Remote watchdog đánh dấu tác vụ không tiến triển sau khoảng thời gian này.',
        ],
        'cleanup__temp_data_max_age_hours' => [
            'label' => 'Tuổi dữ liệu tạm tối đa (giờ)',
            'description' => 'Remote watchdog dọn dữ liệu tạm cũ hơn mức này.',
        ],
        'telegram__owner_user_id' => [
            'label' => 'User ID chủ sở hữu Telegram',
            'description' => 'Bắt buộc cho nhóm: chỉ người dùng này được gửi lệnh và bấm nút. Để trống cho chat riêng.',
        ],
        'telegram__api_base' => [
            'label' => 'Địa chỉ API Telegram',
            'description' => 'Địa chỉ HTTPS của API Telegram; chỉ cho phép HTTP ở localhost.',
        ],
        'telegram__timeout' => [
            'label' => 'Thời gian chờ Telegram (giây)',
            'description' => 'Quá thời gian này tin được xem là chưa gửi.',
        ],
        'telegram__bot_enabled' => [
            'label' => 'Bật bot hỏi đáp',
            'description' => 'Bot trả lời tin của chủ sở hữu: tìm phim, diễn viên, trạng thái, theo dõi. Bot token và chat ID được cấu hình riêng trong runtime secrets.',
        ],
        'telegram__bot_max_results' => [
            'label' => 'Số kết quả bot trả mỗi nhóm',
            'description' => 'Tối đa 10.',
        ],
        'follow_notifications__timezone' => [
            'label' => 'Múi giờ thông báo và quét thư viện',
            'description' => 'Múi giờ cho thông báo theo dõi, footer Telegram và lịch quét thư viện.',
        ],
        'report__state_path' => [
            'label' => 'Tệp trạng thái báo cáo',
            'description' => 'Đường dẫn tệp lưu mốc gửi báo cáo hằng giờ.',
        ],
        'report__enabled' => [
            'label' => 'Bật báo cáo định kỳ',
            'description' => 'Lịch tự động gửi báo cáo trạng thái qua Telegram.',
        ],
        'report__interval_minutes' => [
            'label' => 'Chu kỳ gửi báo cáo (phút)',
            'description' => 'Báo cáo chạy theo mốc cố định trong ngày theo múi giờ đã chọn.',
        ],
        'report__timezone' => [
            'label' => 'Múi giờ báo cáo',
            'description' => 'Tên múi giờ IANA, ví dụ America/Mexico_City.',
        ],
        'report__quiet_hours_start' => [
            'label' => 'Giờ yên lặng bắt đầu',
            'description' => 'Để trống để không bật giờ yên lặng; định dạng HH:MM.',
        ],
        'report__quiet_hours_end' => [
            'label' => 'Giờ yên lặng kết thúc',
            'description' => 'Giờ kết thúc không bao gồm; định dạng HH:MM.',
        ],
        'report__sections' => [
            'label' => 'Các phần trong báo cáo',
            'description' => 'Chỉ các phần được chọn mới xuất hiện trong báo cáo.',
        ],
        'report__top_sources_limit' => [
            'label' => 'Số nguồn tối đa trong danh sách',
            'description' => 'Giới hạn danh sách nguồn bị chặn hoặc đã hết lượt.',
        ],
        'report__channels' => [
            'label' => 'Kênh gửi báo cáo',
            'description' => 'Telegram là kênh gửi hiện được bật.',
        ],
        'report__history_days' => [
            'label' => 'Số ngày lưu lịch sử báo cáo',
            'description' => 'Bản báo cáo cũ hơn mức này sẽ được tự động xóa sau lần gửi.',
        ],
        'report__worker_busy_ttl_seconds' => [
            'label' => 'Thời hạn dấu worker bận (giây)',
            'description' => 'Dấu tự hết hạn nếu tiến trình kết thúc mà không phát sự kiện hoàn tất.',
        ],
        'report__sample_retention_minutes' => [
            'label' => 'Thời gian giữ mẫu nút (phút)',
            'description' => 'Mẫu CPU, RAM, đĩa và worker được giữ trong Redis dùng chung.',
        ],
        'report__sample_max_age_seconds' => [
            'label' => 'Mẫu nút tối đa bao nhiêu giây thì được xem là mới',
            'description' => 'Nút không có mẫu mới sẽ hiển thị no data trong báo cáo.',
        ],
        'report__node_volume_path' => [
            'label' => 'Thư mục đo dung lượng đĩa của nút',
            'description' => 'Đường dẫn nằm trên volume ứng dụng của nút.',
        ],
        'report__browser_stats_url' => [
            'label' => 'Địa chỉ thống kê trình duyệt (tùy chọn)',
            'description' => 'Endpoint nội bộ trả số tiến trình Chrome và lượt Playwright đang bận/tổng.',
        ],
        'report__template' => [
            'label' => 'Mẫu văn bản báo cáo',
            'description' => 'Văn bản mẫu tiếng Anh. Có thể dùng các biến {header}, {movies}, {performers}, {crawl}, {sources}, {fleet}, {workers}, {servers}, {services}, {notification_events}, {deploys}, {backup}, {warnings}.',
        ],
        'notifications__quiet_mode' => [
            'label' => 'Chế độ yên lặng',
            'description' => 'Chỉ gửi cảnh báo CRITICAL và sự kiện bảo mật; các mức khác được giữ cho báo cáo.',
        ],
        'report__daily_time' => [
            'label' => 'Giờ gửi báo cáo hằng ngày',
            'description' => 'Mỗi ngày gửi một báo cáo dữ liệu 24 giờ theo múi giờ báo cáo hằng ngày.',
        ],
        'report__daily_timezone' => [
            'label' => 'Múi giờ báo cáo hằng ngày',
            'description' => 'Tên múi giờ IANA dùng để lên lịch báo cáo hằng ngày.',
        ],
        'report__legacy_hourly_enabled' => [
            'label' => 'Bật báo cáo giờ cũ',
            'description' => 'Chỉ chạy javmeta:hourly-report khi bật và báo cáo định kỳ mới đang tắt.',
        ],
        'readiness__max_newest_movie_age_minutes' => [
            'label' => 'Phim mới nhất không cũ quá (phút)',
            'description' => 'Cũ hơn mức này nghĩa là crawl không chạy.',
        ],
        'readiness__max_dead_sources' => [
            'label' => 'Số nguồn chết tối đa',
            'description' => 'Số nguồn bật đã quá hạn theo lịch; vượt số này thì báo lỗi.',
        ],
        'readiness__max_backup_age_hours' => [
            'label' => 'Bản sao lưu không cũ quá (giờ)',
            'description' => 'Cũ hơn mức này thì báo lỗi.',
        ],
        'search__enabled' => [
            'label' => 'Bật tìm kiếm Elasticsearch',
            'description' => 'Tắt thì chỉ dùng tìm kiếm trong cơ sở dữ liệu.',
        ],
        'search__verify_ssl' => [
            'label' => 'Xác thực chứng chỉ TLS Elasticsearch',
            'description' => 'Bật xác thực chứng chỉ TLS của máy chủ Elasticsearch.',
        ],
        'search__embedder_enabled' => [
            'label' => 'Bật vector (tìm theo nghĩa)',
            'description' => 'Tắt thì tài liệu được lập chỉ mục mà không có vector.',
        ],
        'search__embedder_url' => [
            'label' => 'Địa chỉ dịch vụ embedder (E5)',
            'description' => 'Dùng khi nhà cung cấp embedding là embedder.',
        ],
        'search__embedding_dimensions' => [
            'label' => 'Số chiều của vector',
            'description' => 'Đổi bằng lệnh php artisan search:switch-embeddings, không sửa tay: chỉ mục phải được dựng lại.',
        ],
        'search__movies_index' => [
            'label' => 'Chỉ mục Elasticsearch của phim',
            'description' => 'search:switch-embeddings dựng chỉ mục mới rồi đổi sang nó; chỉ mục cũ được giữ để quay lại.',
        ],
        'search__performers_index' => [
            'label' => 'Chỉ mục Elasticsearch của diễn viên',
            'description' => 'Tên chỉ mục lưu vector và dữ liệu diễn viên.',
        ],
        'ai__embedding_provider' => [
            'label' => 'Nhà cung cấp embedding',
            'description' => 'embedder (E5 cục bộ) hoặc ollama. Đổi bằng php artisan search:switch-embeddings <nhà cung cấp> để chỉ mục được dựng lại an toàn.',
        ],
        'ai__ollama_url' => [
            'label' => 'Địa chỉ Ollama',
            'description' => 'Ví dụ http://127.0.0.1:11434. Kiểm tra bằng php artisan ai:check.',
        ],
        'ai__ollama_embed_model' => [
            'label' => 'Model embedding của Ollama',
            'description' => 'Ví dụ nomic-embed-text.',
        ],
        'ai__ollama_chat_model' => [
            'label' => 'Model hội thoại của Ollama',
            'description' => 'Dùng để hiểu câu hỏi tự nhiên.',
        ],
        'ai__ollama_timeout' => [
            'label' => 'Thời gian chờ Ollama (giây)',
            'description' => 'Giới hạn thời gian một yêu cầu đến Ollama.',
        ],
        'ai__nl_timeout_seconds' => [
            'label' => 'Chờ Ollama đọc câu hỏi tối đa (giây)',
            'description' => 'Giới hạn cứng hai giây cho bước hiểu câu hỏi; quá hạn thì dùng luật cơ bản.',
        ],
        'ai__ollama_keep_alive' => [
            'label' => 'Giữ model trong bộ nhớ (ví dụ 30m)',
            'description' => 'Ollama giữ model đã nạp trong khoảng này; lệnh ai:warm nạp lại trước khi hết hạn.',
        ],
        'ai__nl_enabled' => [
            'label' => 'Hiểu câu hỏi tự nhiên',
            'description' => 'Đọc bộ lọc (maker, thể loại, năm, thời lượng, diễn viên) từ câu hỏi. Luôn có luật cơ bản; có Ollama thì dùng model trước.',
        ],
        'ai__cloud_enabled' => [
            'label' => 'Cho phép dùng AI đám mây',
            'description' => 'TẮT. Theo văn bản quyết định (JOOXCR-230, chờ duyệt) chỉ câu hỏi tìm kiếm do chủ sở hữu gõ mới được gửi đi, không bao giờ gửi nội dung phim, ảnh hay âm thanh.',
        ],
        'observability__fetch_attempts_retention_days' => [
            'label' => 'Số ngày giữ lịch sử thử tải',
            'description' => 'Tự động xóa các lần thử tải cũ sau số ngày này.',
        ],
        'observability__openobserve_enabled' => [
            'label' => 'Gửi log và metric tới OpenObserve',
            'description' => 'Tắt thì không ghi telemetry.',
        ],
        'observability__openobserve_timeout_seconds' => [
            'label' => 'Timeout OpenObserve (giây)',
            'description' => 'Giới hạn thời gian gửi telemetry tới OpenObserve.',
        ],
        'observability__openobserve_org' => [
            'label' => 'Tổ chức OpenObserve',
            'description' => 'Tên tổ chức nhận log và metric.',
        ],
        'observability__openobserve_log_stream' => [
            'label' => 'Luồng log OpenObserve',
            'description' => 'Tên luồng lưu log của ứng dụng.',
        ],
        'observability__openobserve_metrics_stream' => [
            'label' => 'Luồng metric OpenObserve',
            'description' => 'Tên luồng lưu metric của ứng dụng.',
        ],
        'observability__openobserve_service_name' => [
            'label' => 'Tên dịch vụ OpenObserve',
            'description' => 'Tên ứng dụng hiển thị trong dữ liệu telemetry.',
        ],
        'observability__trace_sample_rate' => [
            'label' => 'Tỷ lệ lấy mẫu trace (0 đến 1)',
            'description' => '1 là lấy mọi request.',
        ],
        'observability__sentry_traces_sample_rate' => [
            'label' => 'Tỷ lệ lấy mẫu trace Sentry (0 đến 1)',
            'description' => '0 là tắt trace Sentry.',
        ],
        'observability__egress_probe_urls' => [
            'label' => 'Địa chỉ kiểm tra IP mạng ra',
            'description' => 'Mỗi dòng là một dịch vụ trả về IPv4 công khai; lần lượt thử theo thứ tự khi node kiểm tra.',
        ],
        'observability__egress_probe_interval_seconds' => [
            'label' => 'Chu kỳ kiểm tra IP node (giây)',
            'description' => 'Worker kiểm tra IP mạng ra theo chu kỳ này bằng timer riêng trên từng node.',
        ],
        'observability__browser_egress_probe_interval_seconds' => [
            'label' => 'Chu kỳ kiểm tra IP trình duyệt (giây)',
            'description' => 'Gửi một yêu cầu nhẹ qua mỗi browser host theo chu kỳ này.',
        ],
        'observability__egress_probe_timeout_seconds' => [
            'label' => 'Timeout kiểm tra IP mạng ra (giây)',
            'description' => 'Giới hạn tổng thời gian của mỗi lần kiểm tra IP, tối đa 5 giây.',
        ],
        'observability__egress_changes_stream' => [
            'label' => 'Luồng log khi IP mạng ra thay đổi',
            'description' => 'Luồng OpenObserve nhận một bản ghi có IP cũ, IP mới, node và thời điểm thay đổi.',
        ],
        'observability__record_egress_ip' => [
            'label' => 'Ghi IP mạng ra vào dữ liệu crawl',
            'description' => 'Tắt để giữ node nhưng để trống IP mạng ra trong attempts, log và span.',
        ],
        'stats__enabled' => [
            'label' => 'Bật thống kê crawl dài hạn',
            'description' => 'Lưu số liệu crawl theo giờ và gửi các metric hiệu suất tổng hợp.',
        ],
        'stats__retention_days' => [
            'label' => 'Thời gian lưu thống kê crawl (ngày)',
            'description' => 'Số ngày giữ số liệu crawl theo giờ trước khi tự dọn.',
        ],
        'follow_notifications__enabled' => [
            'label' => 'Gửi thông báo phim mới của mục đang theo dõi',
            'description' => 'Mỗi phim mới của diễn viên, maker hoặc series đang theo dõi gửi một tin Telegram kèm ảnh bìa và nút Mở.',
        ],
        'follow_notifications__daily_cap' => [
            'label' => 'Số tin tối đa mỗi ngày',
            'description' => 'Quá số này, các phim còn lại chờ sang ngày sau.',
        ],
        'follow_notifications__quiet_from_hour' => [
            'label' => 'Giờ yên lặng từ (0 đến 23)',
            'description' => 'Trong giờ yên lặng không gửi tin; tin được gửi sau khi hết giờ. Bằng giờ kết thúc thì tắt giờ yên lặng.',
        ],
        'follow_notifications__quiet_to_hour' => [
            'label' => 'Giờ yên lặng đến (0 đến 23)',
            'description' => 'Giờ kết thúc (không gồm giờ này), theo múi giờ báo cáo.',
        ],
        'follow_notifications__lookback_days' => [
            'label' => 'Chỉ báo phim thấy trong (ngày)',
            'description' => 'Phim cũ hơn mức này không được báo.',
        ],
        'library__enabled' => [
            'label' => 'Bật quét thư viện hằng ngày',
            'description' => 'Quét các thư mục bên dưới, nhận mã phim trong tên tệp và ghép với phim đã biết. Không tải gì lên và không sửa tệp.',
        ],
        'library__folders' => [
            'label' => 'Thư mục cần quét (mỗi dòng một đường dẫn)',
            'description' => 'Đường dẫn tuyệt đối mà máy chạy JavMeta đọc được. Thư mục phải được gắn vào môi trường PHP dùng chung.',
        ],
        'library__extensions' => [
            'label' => 'Đuôi tệp video',
            'description' => 'Cách nhau bằng dấu phẩy hoặc xuống dòng.',
        ],
        'library__scan_hour' => [
            'label' => 'Giờ quét hằng ngày (0 đến 23)',
            'description' => 'Theo múi giờ báo cáo. Có thể quét ngay bằng php artisan library:scan --force.',
        ],
        'library__hash_kilobytes' => [
            'label' => 'Số KB đầu và cuối dùng để băm nhanh',
            'description' => 'Mã băm nhanh = kích thước + phần đầu + phần cuối của tệp.',
        ],
        'downloads__enabled' => [
            'label' => 'Bật tải về',
            'description' => 'TẮT cho tới khi chủ sở hữu duyệt văn bản quyết định (JOOXCR-223). Chỉ nhận magnet mà JavMeta đã lưu cho một phim, mỗi lần tải là một thao tác của chủ sở hữu.',
        ],
        'downloads__qbittorrent_url' => [
            'label' => 'Địa chỉ qBittorrent Web UI',
            'description' => 'Ví dụ http://192.168.2.10:8080 (mạng nội bộ).',
        ],
        'downloads__qbittorrent_username' => [
            'label' => 'Tài khoản qBittorrent',
            'description' => 'Tài khoản dùng để đăng nhập Web UI qBittorrent.',
        ],
        'downloads__qbittorrent_password' => [
            'label' => 'Mật khẩu qBittorrent',
            'description' => 'Mã hoá trong cơ sở dữ liệu, không hiển thị lại.',
        ],
        'downloads__category' => [
            'label' => 'Danh mục (category) trong qBittorrent',
            'description' => 'Chỉ các torrent trong danh mục này được liệt kê và quản lý.',
        ],
        'downloads__save_path' => [
            'label' => 'Thư mục lưu',
            'description' => 'Đường dẫn trên máy chạy qBittorrent; cũng là thư mục máy chủ tệp được phục vụ nếu máy JavMeta đọc được.',
        ],
        'downloads__timeout' => [
            'label' => 'Thời gian chờ qBittorrent (giây)',
            'description' => 'Giới hạn thời gian mỗi yêu cầu tới qBittorrent.',
        ],
        'files__enabled' => [
            'label' => 'Bật máy chủ tệp',
            'description' => 'TẮT cho tới khi chủ sở hữu duyệt (JOOXCR-223). Phục vụ tệp đã tải qua liên kết ký có thời hạn.',
        ],
        'files__root_folders' => [
            'label' => 'Thư mục được phép phục vụ (mỗi dòng một đường dẫn)',
            'description' => 'Tệp ngoài các thư mục này không bao giờ được phục vụ.',
        ],
        'files__link_ttl_minutes' => [
            'label' => 'Liên kết hết hạn sau (phút)',
            'description' => 'Liên kết tải tệp tự hết hạn sau khoảng thời gian này.',
        ],
        'files__allowed_networks' => [
            'label' => 'Mạng được truy cập (CIDR, mỗi dòng một mạng)',
            'description' => 'Chỉ địa chỉ trong các mạng này mở được liên kết. Mặc định là mạng nội bộ.',
        ],
        'domains__ui_host' => [
            'label' => 'Tên miền giao diện XCrawler',
            'description' => 'Giao diện và API. Chỉ để ghi nhớ: HAProxy và Apache quyết định tên miền thật.',
        ],
        'domains__command_center_host' => [
            'label' => 'Tên miền Command Center',
            'description' => 'Trang vận hành (/ops). Đăng nhập ở tên miền này vào thẳng /ops; giao diện XCrawler trỏ nút Vận hành về đây. Để trống nếu không tách tên miền.',
        ],
        'backup__manifest_path' => [
            'label' => 'Đường dẫn manifest sao lưu',
            'description' => 'Tệp trạng thái được lệnh backup cập nhật sau khi hoàn tất.',
        ],
        'backup__stale_after_seconds' => [
            'label' => 'Bản sao lưu coi là cũ sau (giây)',
            'description' => 'Dùng cho cảnh báo sao lưu hằng phút.',
        ],
        'normalization__blood_types' => [
            'label' => 'Nhóm máu hợp lệ',
            'description' => 'Các nhóm máu chuẩn mà bộ chuẩn hóa chấp nhận: A, B, O và AB.',
        ],
        'normalization__cups' => [
            'label' => 'Cỡ cúp hợp lệ',
            'description' => 'Các cỡ cúp chuẩn mà bộ chuẩn hóa chấp nhận, từ A đến K.',
        ],
        'normalization__junk_tokens' => [
            'label' => 'Giá trị rác trong hồ sơ',
            'description' => 'Bản đồ JSON từ trường hồ sơ đến các giá trị rác hoặc giữ chỗ; khi so khớp sẽ bỏ qua hoa thường và khoảng trắng.',
        ],
        'normalization__field_ranges' => [
            'label' => 'Khoảng giá trị trường hồ sơ',
            'description' => 'Giới hạn tối thiểu và tối đa dạng JSON cho số đo theo centimet, ngày sinh và ngày ra mắt; giá trị tối đa của ngày có thể là today.',
        ],
        'normalization__location_aliases' => [
            'label' => 'Tên chuẩn và bí danh địa danh Nhật Bản',
            'description' => 'Bản đồ JSON từ tỉnh, thành phố và bí danh Nhật Bản sang tên địa danh chuẩn; có thể thêm hoặc sửa bí danh tại đây.',
        ],
        'normalization__backfill_chunk_size' => [
            'label' => 'Kích thước lô backfill',
            'description' => 'Số hồ sơ diễn viên xử lý trước mỗi mốc tiếp tục; có thể ghi đè bằng tùy chọn --chunk.',
        ],
    ],
];

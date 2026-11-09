<?php

declare(strict_types=1);

return  [
    'not_found' => 'Không tìm thấy loại thông báo.',
    'registry'
     => [
         'crawl__success_rate_low'
          => [
              'name' => 'Tỷ lệ crawl thành công thấp',
              'usage' => 'Gửi khi tỷ lệ thành công xuống dưới ngưỡng.',
          ],
         'crawl__blocked_ratio_high'
          => [
              'name' => 'Tỷ lệ yêu cầu bị chặn cao',
              'usage' => 'Gửi khi tỷ lệ yêu cầu bị chặn vượt ngưỡng.',
          ],
         'crawl__source_success_low'
          => [
              'name' => 'Tỷ lệ thành công của nguồn thấp',
              'usage' => 'Gửi khi một nguồn có tỷ lệ thành công thấp.',
          ],
         'database__connections_high'
          => [
              'name' => 'Số kết nối PostgreSQL cao',
              'usage' => 'Gửi khi PostgreSQL gần hết số kết nối cho phép.',
          ],
         'crawl__dispatch_delayed'
          => [
              'name' => 'Điều phối crawl bị trễ',
              'usage' => 'Gửi khi còn việc đến hạn nhưng chưa được điều phối.',
          ],
         'crawl__backlog_by_source_high'
          => [
              'name' => 'Hàng đợi của nguồn gần cạn',
              'usage' => 'Gửi khi một nguồn còn rất ít việc đến hạn.',
          ],
         'crawl__heartbeat_stale'
          => [
              'name' => 'Worker mất heartbeat',
              'usage' => 'Gửi khi heartbeat của worker quá hạn.',
          ],
         'crawl__heartbeat_recovered'
          => [
              'name' => 'Worker heartbeat đã phục hồi',
              'usage' => 'Gửi một lần khi heartbeat hoạt động trở lại.',
          ],
         'crawl__source_stale'
          => [
              'name' => 'Nguồn crawl quá hạn',
              'usage' => 'Gửi khi nguồn không thành công theo lịch đã cấu hình.',
          ],
         'crawl__source_recovered'
          => [
              'name' => 'Nguồn crawl đã phục hồi',
              'usage' => 'Gửi khi nguồn hoạt động trở lại hoặc không còn được theo dõi.',
          ],
         'source__unhealthy'
          => [
              'name' => 'Nguồn crawl có lỗi',
              'usage' => 'Gửi khi kiểm tra crawl phát hiện một nguồn gặp lỗi.',
          ],
         'crawl__stalled'
          => [
              'name' => 'Crawl không có tiến triển',
              'usage' => 'Gửi khi có việc đến hạn nhưng không nguồn nào thành công.',
          ],
         'crawl__stalled_recovered'
          => [
              'name' => 'Crawl đã có tiến triển',
              'usage' => 'Gửi một lần khi crawl hoạt động trở lại.',
          ],
         'crawl__queue_stuck'
          => [
              'name' => 'Hàng đợi crawl bị kẹt',
              'usage' => 'Gửi khi các mục đang được xử lý vượt thời gian cho phép.',
          ],
         'crawl__queue_recovered'
          => [
              'name' => 'Hàng đợi crawl đã phục hồi',
              'usage' => 'Gửi một lần khi không còn mục bị kẹt.',
          ],
         'crawl__backlog_high'
          => [
              'name' => 'Hàng đợi crawl quá lớn',
              'usage' => 'Gửi khi số mục đến hạn vượt ngưỡng cấu hình.',
          ],
         'crawl__backlog_recovered'
          => [
              'name' => 'Hàng đợi crawl đã ổn định',
              'usage' => 'Gửi một lần khi số mục đến hạn xuống dưới ngưỡng.',
          ],
         'catalog__hourly'
          => [
              'name' => 'Báo cáo danh mục mỗi giờ',
              'usage' => 'Tóm tắt số phim và diễn viên mới trong giờ.',
          ],
         'catalog__lookup_missing'
          => [
              'name' => 'Không tìm thấy dữ liệu được hỏi',
              'usage' => 'Gửi khi phim hoặc diễn viên được hỏi chưa có dữ liệu.',
          ],
         'source__health_blocked'
          => [
              'name' => 'Nguồn bị chặn',
              'usage' => 'Gửi khi lần kiểm tra phát hiện nguồn mới bị chặn.',
          ],
         'source__health_recovered'
          => [
              'name' => 'Nguồn đã hoạt động trở lại',
              'usage' => 'Gửi khi kiểm tra nguồn xác nhận trạng thái phục hồi.',
          ],
         'staging__readiness_blocked'
          => [
              'name' => 'Staging chưa sẵn sàng',
              'usage' => 'Gửi khi staging không đạt startup/readiness checks.',
          ],
         'staging__readiness_recovered'
          => [
              'name' => 'Staging đã sẵn sàng',
              'usage' => 'Gửi khi staging phục hồi sau trạng thái lỗi.',
          ],
         'follow__match'
          => [
              'name' => 'Phim mới khớp mục theo dõi',
              'usage' => 'Gửi một tin cho phim mới khớp mục chủ sở hữu theo dõi.',
          ],
         'backup__manifest_missing'
          => [
              'name' => 'Thiếu manifest sao lưu',
              'usage' => 'Gửi khi không có manifest sao lưu thành công.',
          ],
         'backup__stale'
          => [
              'name' => 'Bản sao lưu đã quá hạn',
              'usage' => 'Gửi khi bản sao lưu mới nhất vượt giới hạn tuổi.',
          ],
         'backup__recovered'
          => [
              'name' => 'Bản sao lưu đã phục hồi',
              'usage' => 'Gửi khi bản sao lưu mới nhất đã trong giới hạn tuổi.',
          ],
         'openobserve__telegram_warning'
          => [
              'name' => 'OpenObserve Telegram warning',
              'usage' => 'Mẫu văn bản warning do OpenObserve gửi qua Telegram.',
          ],
         'openobserve__telegram_critical'
          => [
              'name' => 'OpenObserve Telegram critical',
              'usage' => 'Mẫu văn bản critical do OpenObserve gửi qua Telegram.',
          ],
         'deploy__success'
          => [
              'name' => 'Triển khai thành công',
              'usage' => 'Được dùng khi triển khai hoàn tất trên các node.',
          ],
         'deploy__rolled_back'
          => [
              'name' => 'Đã hoàn tác triển khai',
              'usage' => 'Được dùng khi triển khai bị hoàn tác.',
          ],
         'node__joined'
          => [
              'name' => 'Node mới tham gia',
              'usage' => 'Được dùng khi một node mới bắt đầu gửi heartbeat.',
          ],
         'node__lost'
          => [
              'name' => 'Node mất kết nối',
              'usage' => 'Được dùng khi node không còn gửi heartbeat.',
          ],
         'horizon__master_missing'
          => [
              'name' => 'Horizon master không hoạt động',
              'usage' => 'Được dùng khi không tìm thấy Horizon master.',
          ],
         'horizon__supervisor_missing'
          => [
              'name' => 'Horizon supervisor không hoạt động',
              'usage' => 'Được dùng khi thiếu Horizon supervisor được yêu cầu.',
          ],
         'queue__failed_jobs_spike'
          => [
              'name' => 'Số failed jobs tăng cao',
              'usage' => 'Được dùng khi số failed jobs vượt ngưỡng.',
          ],
         'database__deadlock_burst'
          => [
              'name' => 'Deadlock cơ sở dữ liệu tăng cao',
              'usage' => 'Được dùng khi số deadlock vượt ngưỡng.',
          ],
         'queue__retry_permanent_failures'
          => [
              'name' => 'Kênh retry lỗi vĩnh viễn',
              'usage' => 'Được dùng khi retry channel gặp lỗi không thể thử lại.',
          ],
         'network__egress_ip_changed'
          => [
              'name' => 'Egress IP đã thay đổi',
              'usage' => 'Được dùng khi địa chỉ egress của node đổi.',
          ],
         'network__egress_ip_shared'
          => [
              'name' => 'Egress IP bị dùng chung',
              'usage' => 'Được dùng khi nhiều node cùng chia sẻ egress IP.',
          ],
         'browser__host_saturated'
          => [
              'name' => 'Browser host quá tải',
              'usage' => 'Được dùng khi browser host đạt ngưỡng bão hòa.',
          ],
         'browser__old_processes'
          => [
              'name' => 'Browser process đã cũ',
              'usage' => 'Được dùng khi browser process tồn tại quá lâu.',
          ],
         'flaresolverr__down'
          => [
              'name' => 'FlareSolverr không hoạt động',
              'usage' => 'Được dùng khi FlareSolverr không trả lời health check.',
          ],
         'sentry__new_critical_group'
          => [
              'name' => 'Sentry có nhóm lỗi critical mới',
              'usage' => 'Được dùng khi Sentry phát hiện nhóm lỗi critical mới.',
          ],
         'source__exhausted'
          => [
              'name' => 'Nguồn đã cạn nội dung',
              'usage' => 'Được dùng khi nhiều lượt quét liên tiếp không tìm thấy nội dung mới.',
          ],
         'catalog__daily_summary'
          => [
              'name' => 'Tóm tắt danh mục hằng ngày',
              'usage' => 'Được dùng cho báo cáo số liệu danh mục mỗi ngày.',
          ],
         'backup__success'
          => [
              'name' => 'Sao lưu thành công',
              'usage' => 'Được dùng sau khi sao lưu cơ sở dữ liệu thành công.',
          ],
         'backup__restore_test'
          => [
              'name' => 'Kiểm thử khôi phục sao lưu',
              'usage' => 'Được dùng sau khi kiểm thử khôi phục hoàn tất.',
          ],
         'security__api_key_created'
          => [
              'name' => 'API key mới được tạo',
              'usage' => 'Được dùng khi chủ sở hữu tạo API key.',
          ],
         'security__api_key_revoked'
          => [
              'name' => 'API key bị thu hồi',
              'usage' => 'Được dùng khi chủ sở hữu thu hồi API key.',
          ],
         'security__login_failures'
          => [
              'name' => 'Đăng nhập thất bại nhiều lần',
              'usage' => 'Được dùng khi số lần đăng nhập thất bại vượt ngưỡng.',
          ],
         'security__guest_mode_toggled'
          => [
              'name' => 'Chế độ khách đã thay đổi',
              'usage' => 'Được dùng khi chế độ truy cập khách được bật hoặc tắt.',
          ],
         'security__settings_changed'
          => [
              'name' => 'Cài đặt hệ thống đã thay đổi',
              'usage' => 'Được dùng khi chủ sở hữu thay đổi cài đặt hệ thống.',
          ],
         'startup_gate__blocked'
          => [
              'name' => 'Startup gate đã chặn ứng dụng',
              'usage' => 'Được dùng khi startup gate ngăn dịch vụ khởi động.',
          ],
         'crawl__success_rate_critical'
          => [
              'name' => 'Tỷ lệ crawl rất thấp',
              'usage' => 'Gửi khi tỷ lệ thành công xuống dưới 70% trong 15 phút.',
          ],
         'crawl__success_rate_low__movies'
          => [
              'name' => 'Tỷ lệ crawl phim thấp',
              'usage' => 'Cảnh báo tỷ lệ crawl phim thấp theo loại dữ liệu.',
          ],
         'crawl__success_rate_low__performers'
          => [
              'name' => 'Tỷ lệ crawl diễn viên thấp',
              'usage' => 'Cảnh báo tỷ lệ crawl diễn viên thấp theo loại dữ liệu.',
          ],
         'dependency__unreachable'
          => [
              'name' => 'Dịch vụ dữ liệu không kết nối được',
              'usage' => 'Gửi khi PostgreSQL, Redis, MongoDB hoặc Elasticsearch không thể truy cập.',
          ],
         'resource__cpu_high'
          => [
              'name' => 'CPU sử dụng cao',
              'usage' => 'Gửi khi CPU của máy chủ vượt ngưỡng cảnh báo.',
          ],
         'resource__memory_high'
          => [
              'name' => 'RAM sử dụng cao',
              'usage' => 'Gửi khi RAM của máy chủ vượt ngưỡng cảnh báo.',
          ],
         'resource__disk_high'
          => [
              'name' => 'Đĩa máy chủ gần đầy',
              'usage' => 'Gửi khi đĩa vượt ngưỡng cảnh báo; mức trên 90% được nâng thành critical.',
          ],
         'resource__disk_critical'
          => [
              'name' => 'Đĩa máy chủ gần đầy nghiêm trọng',
              'usage' => 'Gửi khi đĩa vượt 90%.',
          ],
         'node__left'
          => [
              'name' => 'Node đã rời hệ thống',
              'usage' => 'Gửi một lần khi node rời khỏi fleet.',
          ],
         'report__periodic'
          => [
              'name' => 'Báo cáo trạng thái định kỳ',
              'usage' => 'Mẫu thông báo cho báo cáo trạng thái định kỳ.',
          ],
         'report__daily'
          => [
              'name' => 'Báo cáo trạng thái hằng ngày',
              'usage' => 'Mẫu thông báo cho báo cáo trạng thái 24 giờ.',
          ],
         'deploy__ok'
          => [
              'name' => 'Triển khai hoàn tất',
              'usage' => 'Thông báo một lần khi tất cả node hoàn tất triển khai.',
          ],
         'egress__changed'
          => [
              'name' => 'Địa chỉ egress đã thay đổi',
              'usage' => 'Thông báo khi địa chỉ egress của node thay đổi.',
          ],
         'egress__shared'
          => [
              'name' => 'Địa chỉ egress bị dùng chung',
              'usage' => 'Thông báo khi nhiều node dùng cùng địa chỉ egress.',
          ],
         'retry__permanent_failures'
          => [
              'name' => 'Một số lượt thử lại đã cạn',
              'usage' => 'Tổng hợp hằng ngày các mục hết số vòng thử lại.',
          ],
         'jobs__failed_spike'
          => [
              'name' => 'Số job lỗi tăng cao',
              'usage' => 'Gửi khi số job thất bại vượt ngưỡng trong cửa sổ.',
          ],
         'jobs__deadlock_burst'
          => [
              'name' => 'Deadlock tăng cao',
              'usage' => 'Gửi khi số deadlock vượt ngưỡng trong cửa sổ.',
          ],
         'browser__saturated'
          => [
              'name' => 'Browser host quá tải',
              'usage' => 'Gửi khi các slot trình duyệt đầy và có việc đang chờ.',
          ],
         'browser__down'
          => [
              'name' => 'Dịch vụ trình duyệt không hoạt động',
              'usage' => 'Gửi khi endpoint Playwright hoặc FlareSolverr lỗi liên tục.',
          ],
         'sentry__new_critical'
          => [
              'name' => 'Sentry có lỗi mới',
              'usage' => 'Thông báo khi Sentry phát hiện một nhóm lỗi mới.',
          ],
         'backup__ok'
          => [
              'name' => 'Sao lưu thành công',
              'usage' => 'Thông báo sau khi tác vụ sao lưu hoàn tất.',
          ],
         'startup__blocked'
          => [
              'name' => 'Startup gate đã chặn ứng dụng',
              'usage' => 'Gửi khi dependency chính ngăn dịch vụ khởi động.',
          ],
         'security__setting_changed'
          => [
              'name' => 'Cài đặt hệ thống đã thay đổi',
              'usage' => 'Gửi khi chủ sở hữu thay đổi một cài đặt không bí mật.',
          ],
     ],
];

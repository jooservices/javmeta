<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/** Source of safe defaults and the variable allow-list for every notification type. */
final class NotificationRegistry
{
    /**
     * @return array<string, array{
     *     name: string,
     *     usage: string,
     *     channel: string,
     *     title_template: string,
     *     body_template: string,
     *     enabled: bool,
     *     default_severity: string,
     *     tier: string,
     *     cooldown_minutes: int,
     *     escalation_minutes: int|null,
     *     include_in_periodic_report: bool,
     *     include_in_daily_report: bool,
     *     send_resolved: bool,
     *     variables: list<array{name: string, sample: string|int|float|bool}>
     * }>
     */
    public function all(): array
    {
        $footer = "\n\n🕒 {time} · node: {node}";
        $meta = ['time' => 'Nov 2, 17:40 (Mexico)', 'node' => 'xcrawler'];

        $items = [
            'crawl.success_rate_low' => self::item('Tỷ lệ crawl thành công thấp', 'Gửi khi tỷ lệ thành công xuống dưới ngưỡng.', 'telegram', '{level_marker} [XCrawler][{level}] Crawl success rate is low', "{state_prefix}Success rate is {success_rate} over the last {window_minutes} minutes (minimum {minimum_rate}).\n\n<pre>Success        {success_rate}   (min {minimum_rate})\nBlocked         {blocked_ratio}   (max {maximum_blocked_ratio})\nAttempts       {attempts}\nWorst sources  {worst_sources}</pre>" . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'state_prefix' => '', 'success_rate' => '82.4%', 'minimum_rate' => '85%', 'window_minutes' => 15, 'blocked_ratio' => '9.1%', 'maximum_blocked_ratio' => '10%', 'attempts' => 1842, 'worst_sources' => 'javbus 61%, xcity 48%']),
            'crawl.blocked_ratio_high' => self::item('Tỷ lệ yêu cầu bị chặn cao', 'Gửi khi tỷ lệ yêu cầu bị chặn vượt ngưỡng.', 'telegram', '{level_marker} [XCrawler][{level}] Blocked request ratio is high', "{state_prefix}Blocked request ratio is {blocked_ratio}% over the last {window_minutes} minutes (maximum {maximum_ratio}%).\n\n<pre>Blocked        {blocked_ratio}%   (max {maximum_ratio}%)\nAttempts       {attempts}\nWorst sources  {worst_sources}</pre>" . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'state_prefix' => '', 'blocked_ratio' => '9.1', 'maximum_ratio' => '10', 'window_minutes' => 15, 'attempts' => 1842, 'worst_sources' => 'javbus 61%, xcity 48%']),
            'crawl.source_success_low' => self::item('Tỷ lệ thành công của nguồn thấp', 'Gửi khi một nguồn có tỷ lệ thành công thấp.', 'telegram', '{level_marker} [XCrawler][{level}] Source {source_slug} is performing poorly', "{state_prefix}Source {source_slug} success rate is {success_rate}% over the last {window_minutes} minutes (minimum {minimum_rate}%).\n\n<pre>Source         {source_slug}\nSuccess        {success_rate}%   (min {minimum_rate}%)\nAttempts       {attempts}</pre>" . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'state_prefix' => '', 'source_slug' => 'javbus', 'success_rate' => '61', 'minimum_rate' => '75', 'window_minutes' => 15, 'attempts' => 1842]),
            'database.connections_high' => self::item('Số kết nối PostgreSQL cao', 'Gửi khi PostgreSQL gần hết số kết nối cho phép.', 'telegram', '{level_marker} [XCrawler][{level}] Database connections are high', "{state_prefix}PostgreSQL is nearing its connection limit.\n\n<pre>Connections    {connection_count}\nThreshold      {threshold}</pre>" . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'state_prefix' => '', 'connection_count' => 84, 'threshold' => 80]),
            'crawl.dispatch_delayed' => self::item('Điều phối crawl bị trễ', 'Gửi khi còn việc đến hạn nhưng chưa được điều phối.', 'telegram', '{level_marker} [XCrawler][{level}] Crawl dispatch is delayed', "{state_prefix}Dispatch state: {dispatch_state}.\n\n<pre>Due items       {due_items}\nLast dispatch   {dispatch_age}\nThreshold       {threshold_seconds} seconds</pre>" . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'state_prefix' => '', 'dispatch_state' => 'due work is waiting for dispatch', 'dispatch_age' => '190 seconds ago', 'threshold_seconds' => 180, 'due_items' => 96740]),
            'crawl.backlog_by_source_high' => self::item('Hàng đợi của nguồn gần cạn', 'Gửi khi một nguồn còn rất ít việc đến hạn.', 'telegram', '{level_marker} [XCrawler][{level}] Source {source_slug} is running out of due work', '{state_prefix}Source {source_slug} has {due_items} due items (minimum {minimum_items}).' . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'state_prefix' => '', 'source_slug' => 'ffjav', 'due_items' => 2, 'minimum_items' => 10]),
            'crawl.heartbeat_stale' => self::item('Worker mất heartbeat', 'Gửi khi heartbeat của worker quá hạn.', 'telegram', '{level_marker} [XCrawler][{level}] Worker heartbeat is stale', 'No worker heartbeat has arrived within {stale_seconds} seconds. Last heartbeat: {last_heartbeat_at}.' . $footer, true, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'stale_seconds' => 600, 'last_heartbeat_at' => 'Nov 2, 17:30']),
            'crawl.heartbeat_recovered' => self::item('Worker heartbeat đã phục hồi', 'Gửi một lần khi heartbeat hoạt động trở lại.', 'telegram', '{level_marker} [XCrawler][{level}] Worker heartbeat recovered', 'A crawl worker heartbeat is back within the configured window.' . $footer, true, 'info', $meta + ['level' => 'RESOLVED', 'level_marker' => '🟢']),
            'crawl.source_stale' => self::item('Nguồn crawl quá hạn', 'Gửi khi nguồn không thành công theo lịch đã cấu hình.', 'telegram', '{level_marker} [XCrawler][{level}] Source {source_slug} is stale', 'Source {source_slug} has not succeeded within its configured schedule ({stale_seconds} seconds).' . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'source_slug' => 'javbus', 'stale_seconds' => 86400]),
            'crawl.source_recovered' => self::item('Nguồn crawl đã phục hồi', 'Gửi khi nguồn hoạt động trở lại hoặc không còn được theo dõi.', 'telegram', '{level_marker} [XCrawler][{level}] Source {source_slug} recovered', 'The source succeeded again or is no longer monitored.' . $footer, true, 'info', $meta + ['level' => 'RESOLVED', 'level_marker' => '🟢', 'source_slug' => 'javbus']),
            'source.unhealthy' => self::item('Nguồn crawl có lỗi', 'Gửi khi kiểm tra crawl phát hiện một nguồn gặp lỗi.', 'telegram', '{level_marker} [XCrawler][{level}] Source {source_slug} is unhealthy', 'Source {source_slug} reported {reason}.' . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'source_slug' => 'javbus', 'reason' => 'repeated failures']),
            'crawl.stalled' => self::item('Crawl không có tiến triển', 'Gửi khi có việc đến hạn nhưng không nguồn nào thành công.', 'telegram', '{level_marker} [XCrawler][{level}] Crawl has made no progress', 'Crawl work is waiting but no source succeeded in the last {stall_seconds} seconds. Sources: {source_slugs}.' . $footer, true, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'stall_seconds' => 1800, 'source_slugs' => 'javbus']),
            'crawl.stalled_recovered' => self::item('Crawl đã có tiến triển', 'Gửi một lần khi crawl hoạt động trở lại.', 'telegram', '{level_marker} [XCrawler][{level}] Crawl progress recovered', 'The crawl is making progress again.' . $footer, true, 'info', $meta + ['level' => 'RESOLVED', 'level_marker' => '🟢']),
            'crawl.queue_stuck' => self::item('Hàng đợi crawl bị kẹt', 'Gửi khi các mục đang được xử lý vượt thời gian cho phép.', 'telegram', '{level_marker} [XCrawler][{level}] Crawl queue is stuck', '{stuck_claimed} claimed queue items have not finished for over {stuck_seconds} seconds. Sources: {source_slugs}.' . $footer, true, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'stuck_claimed' => 3, 'stuck_seconds' => 1800, 'source_slugs' => 'javbus']),
            'crawl.queue_recovered' => self::item('Hàng đợi crawl đã phục hồi', 'Gửi một lần khi không còn mục bị kẹt.', 'telegram', '{level_marker} [XCrawler][{level}] Crawl queue recovered', 'No claimed queue items are stuck any more.' . $footer, true, 'info', $meta + ['level' => 'RESOLVED', 'level_marker' => '🟢']),
            'crawl.backlog_high' => self::item('Hàng đợi crawl quá lớn', 'Gửi khi số mục đến hạn vượt ngưỡng cấu hình.', 'telegram', '{level_marker} [XCrawler][{level}] Pending crawl backlog is high', "{pending_due} due crawl items are pending (alert limit {pending_limit}). Sources: {source_slugs}.\n\n<pre>Due items       {pending_due}\nAlert limit     {pending_limit}</pre>" . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'pending_due' => 1842, 'pending_limit' => 1000, 'source_slugs' => 'javbus, ffjav']),
            'crawl.backlog_recovered' => self::item('Hàng đợi crawl đã ổn định', 'Gửi một lần khi số mục đến hạn xuống dưới ngưỡng.', 'telegram', '{level_marker} [XCrawler][{level}] Pending crawl backlog recovered', 'The number of due pending crawl items is below the configured limit ({pending_limit}).' . $footer, true, 'info', $meta + ['level' => 'RESOLVED', 'level_marker' => '🟢', 'pending_limit' => 1000]),
            'catalog.hourly' => self::item('Báo cáo danh mục mỗi giờ', 'Tóm tắt số phim và diễn viên mới trong giờ.', 'telegram', '📊 [XCrawler] Hourly catalog report · {report_time}', "Movies       {movies} ({movie_change})\nPerformers   {performers} ({performer_change})\n{comparison_note}" . $footer, true, 'info', $meta + ['report_time' => 'Nov 2, 17:00', 'movies' => '181,737', 'movie_change' => '+83,856 in 24 h', 'performers' => '99,902', 'performer_change' => '+241', 'comparison_note' => '']),
            'catalog.lookup_missing' => self::item('Không tìm thấy dữ liệu được hỏi', 'Gửi khi phim hoặc diễn viên được hỏi chưa có dữ liệu.', 'telegram', '{level_marker} [XCrawler][{level}] Catalog lookup needs attention', 'The requested {entity_type} {entity_key} is not available in the catalog.' . $footer, true, 'notice', $meta + ['level' => 'NOTICE', 'level_marker' => '🟡', 'entity_type' => 'movie', 'entity_key' => 'ABC-123']),
            'source.health_blocked' => self::item('Nguồn bị chặn', 'Gửi khi lần kiểm tra phát hiện nguồn mới bị chặn.', 'telegram', '{level_marker} [XCrawler][{level}] Source health is blocked', 'Newly blocked: {newly_blocked}. Blocked now: {blocked_sources}.' . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'newly_blocked' => 'javbus', 'blocked_sources' => 'javbus']),
            'source.health_recovered' => self::item('Nguồn đã hoạt động trở lại', 'Gửi khi kiểm tra nguồn xác nhận trạng thái phục hồi.', 'telegram', '{level_marker} [XCrawler][{level}] Source health recovered', 'Recovered: {recovered_sources}. Blocked now: {blocked_sources}.' . $footer, true, 'info', $meta + ['level' => 'RESOLVED', 'level_marker' => '🟢', 'recovered_sources' => 'javbus', 'blocked_sources' => 'none']),
            'staging.readiness_blocked' => self::item('Staging chưa sẵn sàng', 'Gửi khi staging không đạt startup/readiness checks.', 'telegram', '{level_marker} [XCrawler][{level}] Staging readiness is blocked', 'Staging is not ready. Failing checks: {failing_checks}. {first_failure} {next_action}' . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'failing_checks' => 'sources, backup', 'first_failure' => 'Two enabled sources are past due.', 'next_action' => 'Run the source health check.']),
            'staging.readiness_recovered' => self::item('Staging đã sẵn sàng', 'Gửi khi staging phục hồi sau trạng thái lỗi.', 'telegram', '{level_marker} [XCrawler][{level}] Staging readiness recovered', 'Staging is ready. Recovered checks: {resolved_checks}.' . $footer, true, 'info', $meta + ['level' => 'RESOLVED', 'level_marker' => '🟢', 'resolved_checks' => '2']),
            'follow.match' => self::item('Phim mới khớp mục theo dõi', 'Gửi một tin cho phim mới khớp mục chủ sở hữu theo dõi.', 'telegram', '🎬 [XCrawler] New followed movie · {movie_code}', '<b>{movie_title}</b>\nFollowed: {follow_reason}', true, 'info', ['movie_code' => 'ABC-123', 'movie_title' => 'Sample movie', 'follow_reason' => 'performer Risa']),
            'backup.manifest_missing' => self::item('Thiếu manifest sao lưu', 'Gửi khi không có manifest sao lưu thành công.', 'telegram', '{level_marker} [XCrawler][{level}] Backup manifest is missing', 'No successful database backup manifest is available.' . $footer, true, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴']),
            'backup.stale' => self::item('Bản sao lưu đã quá hạn', 'Gửi khi bản sao lưu mới nhất vượt giới hạn tuổi.', 'telegram', '{level_marker} [XCrawler][{level}] Database backup is stale', 'The last successful database backup is older than the configured freshness limit ({threshold_seconds} seconds). Current age: {backup_age_seconds} seconds.' . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'threshold_seconds' => 93600, 'backup_age_seconds' => 100000]),
            'backup.recovered' => self::item('Bản sao lưu đã phục hồi', 'Gửi khi bản sao lưu mới nhất đã trong giới hạn tuổi.', 'telegram', '{level_marker} [XCrawler][{level}] Database backup recovered', 'The last successful database backup is fresh again. Current age: {backup_age_seconds} seconds.' . $footer, true, 'info', $meta + ['level' => 'RESOLVED', 'level_marker' => '🟢', 'backup_age_seconds' => 3600]),
            'openobserve.telegram_warning' => self::item('OpenObserve Telegram warning', 'Mẫu văn bản warning do OpenObserve gửi qua Telegram.', 'openobserve', '[XCrawler][WARNING] {alert_name}', 'Triggered for {stream_name}. {rows}', true, 'warning', ['alert_name' => 'javmeta_source_failure_rate', 'stream_name' => 'javmeta_crawl_run_failures_1h', 'rows' => 'source_slug=javbus value=21']),
            'openobserve.telegram_critical' => self::item('OpenObserve Telegram critical', 'Mẫu văn bản critical do OpenObserve gửi qua Telegram.', 'openobserve', '[XCrawler][CRITICAL] {alert_name}', 'Triggered for {stream_name}. {rows}', true, 'critical', ['alert_name' => 'javmeta_crawl_dispatch_critical', 'stream_name' => 'javmeta_crawl_dispatch_stale', 'rows' => 'value=1']),
            'deploy.success' => self::item('Triển khai thành công', 'Được dùng khi triển khai hoàn tất trên các node.', 'telegram', '🚀 [XCrawler][INFO] Deployed {commit} to {node_count} nodes', "Movies/hour   before {speed_before} → after {speed_after} ({measurement_window})\nNodes         {nodes}" . $footer, false, 'info', $meta + ['commit' => 'b0a5b04', 'node_count' => 3, 'speed_before' => '5,036', 'speed_after' => '5,567', 'measurement_window' => '15 min', 'nodes' => 'xcrawler · worker-1 · worker-2']),
            'deploy.rolled_back' => self::item('Đã hoàn tác triển khai', 'Được dùng khi triển khai bị hoàn tác.', 'telegram', '{level_marker} [XCrawler][{level}] Deployment rolled back', 'Deployment {commit} was rolled back on {nodes}. Reason: {reason}.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'commit' => 'b0a5b04', 'nodes' => 'xcrawler · worker-1', 'reason' => 'readiness check failed']),
            'node.joined' => self::item('Node mới tham gia', 'Được dùng khi một node mới bắt đầu gửi heartbeat.', 'telegram', '🔵 [XCrawler][INFO] Node {node} joined', 'The node reported its first healthy heartbeat at {joined_at}.' . $footer, false, 'info', $meta + ['node' => 'worker-3', 'joined_at' => 'Nov 2, 17:40']),
            'node.lost' => self::item('Node mất kết nối', 'Được dùng khi node không còn gửi heartbeat.', 'telegram', '{level_marker} [XCrawler][{level}] Node {node} was lost', 'No heartbeat has arrived from {node} for {stale_seconds} seconds.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'node' => 'worker-2', 'stale_seconds' => 600]),
            'horizon.master_missing' => self::item('Horizon master không hoạt động', 'Được dùng khi không tìm thấy Horizon master.', 'telegram', '{level_marker} [XCrawler][{level}] Horizon master is missing', 'No Horizon master is running on node {node}.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'node' => 'xcrawler']),
            'horizon.supervisor_missing' => self::item('Horizon supervisor không hoạt động', 'Được dùng khi thiếu Horizon supervisor được yêu cầu.', 'telegram', '{level_marker} [XCrawler][{level}] Horizon supervisor is missing', 'Supervisor {supervisor} is not running on node {node}.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'supervisor' => 'crawler-worker-1', 'node' => 'xcrawler']),
            'queue.failed_jobs_spike' => self::item('Số failed jobs tăng cao', 'Được dùng khi số failed jobs vượt ngưỡng.', 'telegram', '{level_marker} [XCrawler][{level}] Failed jobs have spiked', '{failed_jobs} failed jobs were recorded in the last {window_minutes} minutes (threshold {threshold}).' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'failed_jobs' => 24, 'window_minutes' => 15, 'threshold' => 10]),
            'database.deadlock_burst' => self::item('Deadlock cơ sở dữ liệu tăng cao', 'Được dùng khi số deadlock vượt ngưỡng.', 'telegram', '{level_marker} [XCrawler][{level}] Database deadlocks have spiked', '{deadlocks} deadlocks were recorded in the last {window_minutes} minutes (threshold {threshold}).' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'deadlocks' => 8, 'window_minutes' => 5, 'threshold' => 3]),
            'queue.retry_permanent_failures' => self::item('Kênh retry lỗi vĩnh viễn', 'Được dùng khi retry channel gặp lỗi không thể thử lại.', 'telegram', '{level_marker} [XCrawler][{level}] Retry channel has permanent failures', '{failure_count} permanent failures were recorded for {retry_channel}. First error: {error_summary}.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'failure_count' => 5, 'retry_channel' => 'crawler', 'error_summary' => 'connection refused']),
            'network.egress_ip_changed' => self::item('Egress IP đã thay đổi', 'Được dùng khi địa chỉ egress của node đổi.', 'telegram', '{level_marker} [XCrawler][{level}] Egress IP changed', 'Node {node} now exits through {current_ip}; previous address was {previous_ip}.' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'node' => 'worker-1', 'current_ip' => '203.0.113.12', 'previous_ip' => '203.0.113.11']),
            'network.egress_ip_shared' => self::item('Egress IP bị dùng chung', 'Được dùng khi nhiều node cùng chia sẻ egress IP.', 'telegram', '{level_marker} [XCrawler][{level}] Egress IP is shared', 'Nodes {nodes} are using the same egress address {current_ip}.' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'nodes' => 'worker-1 · worker-2', 'current_ip' => '203.0.113.12']),
            'browser.host_saturated' => self::item('Browser host quá tải', 'Được dùng khi browser host đạt ngưỡng bão hòa.', 'telegram', '{level_marker} [XCrawler][{level}] Browser host is saturated', 'Browser host {host} is at {utilization} utilization (threshold {threshold}). Active processes: {process_count}.' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'host' => 'browser-1', 'utilization' => '94%', 'threshold' => '85%', 'process_count' => 18]),
            'browser.old_processes' => self::item('Browser process đã cũ', 'Được dùng khi browser process tồn tại quá lâu.', 'telegram', '{level_marker} [XCrawler][{level}] Old browser processes were found', '{process_count} browser processes on {host} are older than {age_minutes} minutes.' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'process_count' => 4, 'host' => 'browser-1', 'age_minutes' => 120]),
            'flaresolverr.down' => self::item('FlareSolverr không hoạt động', 'Được dùng khi FlareSolverr không trả lời health check.', 'telegram', '{level_marker} [XCrawler][{level}] FlareSolverr is unavailable', 'FlareSolverr did not pass its health check. Last error: {error_summary}.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'error_summary' => 'connection timeout']),
            'sentry.new_critical_group' => self::item('Sentry có nhóm lỗi critical mới', 'Được dùng khi Sentry phát hiện nhóm lỗi critical mới.', 'telegram', '{level_marker} [XCrawler][{level}] New critical Sentry group', '{group_title} · {event_count} events · {project}.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'group_title' => 'Database connection failures', 'event_count' => 12, 'project' => 'javmeta']),
            'source.exhausted' => self::item('Nguồn đã cạn nội dung', 'Được dùng khi nhiều lượt quét liên tiếp không tìm thấy nội dung mới.', 'telegram', '🟡 [XCrawler][NOTICE] Source {source_slug} is exhausted', 'Only {due_items} items are due and the last {empty_passes} listing passes found nothing new. Movies/hour dropped because the source ran out of content, not because of a fault.' . $footer, false, 'notice', $meta + ['source_slug' => 'ffjav', 'due_items' => 2, 'empty_passes' => 3]),
            'catalog.daily_summary' => self::item('Tóm tắt danh mục hằng ngày', 'Được dùng cho báo cáo số liệu danh mục mỗi ngày.', 'telegram', '📊 [XCrawler] Daily summary, {report_date}', "Movies       {movies}   ({movie_change} in 24 h)\nPerformers   {performers}\nSpeed        avg {average_speed}/h · peak {peak_speed}\nSuccess      {success_rate} · blocked {blocked_ratio}\n⚠️ Blocked sources: {blocked_sources}\n🟡 Exhausted sources: {exhausted_sources}\n🖥 {nodes_running} nodes running · {failed_jobs} failed jobs" . $footer, false, 'info', $meta + ['report_date' => 'Nov 2', 'movies' => '181,737', 'movie_change' => '+83,856', 'performers' => '99,902', 'average_speed' => '3,490', 'peak_speed' => '5,574', 'success_rate' => '89%', 'blocked_ratio' => '1.3%', 'blocked_sources' => 'jable, javlibrary, onejav, missav', 'exhausted_sources' => 'ffjav', 'nodes_running' => 3, 'failed_jobs' => 0]),
            'backup.success' => self::item('Sao lưu thành công', 'Được dùng sau khi sao lưu cơ sở dữ liệu thành công.', 'telegram', '🔵 [XCrawler][INFO] Database backup completed', 'Database backup finished at {completed_at}. Size: {backup_size}. Duration: {duration_seconds} seconds.' . $footer, false, 'info', $meta + ['completed_at' => 'Nov 2, 17:40', 'backup_size' => '4.2 GB', 'duration_seconds' => 86]),
            'backup.restore_test' => self::item('Kiểm thử khôi phục sao lưu', 'Được dùng sau khi kiểm thử khôi phục hoàn tất.', 'telegram', '{level_marker} [XCrawler][{level}] Backup restore test {result}', 'Restore verification completed at {completed_at}. Duration: {duration_seconds} seconds. Details: {details}.' . $footer, false, 'info', $meta + ['level' => 'INFO', 'level_marker' => '🔵', 'result' => 'passed', 'completed_at' => 'Nov 2, 17:40', 'duration_seconds' => 120, 'details' => 'schema and checksums verified']),
            'security.api_key_created' => self::item('API key mới được tạo', 'Được dùng khi chủ sở hữu tạo API key.', 'telegram', '🔵 [XCrawler][INFO] API key created', 'A new API key named {key_label} was created by {actor}.' . $footer, false, 'info', $meta + ['key_label' => 'reporting', 'actor' => 'owner']),
            'security.api_key_revoked' => self::item('API key bị thu hồi', 'Được dùng khi chủ sở hữu thu hồi API key.', 'telegram', '🟡 [XCrawler][NOTICE] API key revoked', 'API key {key_label} was revoked by {actor}.' . $footer, false, 'notice', $meta + ['key_label' => 'reporting', 'actor' => 'owner']),
            'security.login_failures' => self::item('Đăng nhập thất bại nhiều lần', 'Được dùng khi số lần đăng nhập thất bại vượt ngưỡng.', 'telegram', '{level_marker} [XCrawler][{level}] Repeated login failures detected', '{failure_count} failed login attempts were recorded from {source} in {window_minutes} minutes.' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'failure_count' => 8, 'source' => 'unknown', 'window_minutes' => 5]),
            'security.guest_mode_toggled' => self::item('Chế độ khách đã thay đổi', 'Được dùng khi chế độ truy cập khách được bật hoặc tắt.', 'telegram', '🟡 [XCrawler][NOTICE] Guest mode changed', 'Guest mode was {guest_mode} by {actor}.' . $footer, false, 'notice', $meta + ['guest_mode' => 'enabled', 'actor' => 'owner']),
            'security.settings_changed' => self::item('Cài đặt hệ thống đã thay đổi', 'Được dùng khi chủ sở hữu thay đổi cài đặt hệ thống.', 'telegram', '🔵 [XCrawler][INFO] System setting changed', 'Setting {setting_key} was changed by {actor}.' . $footer, false, 'info', $meta + ['setting_key' => 'queue.feed_pool_limit', 'actor' => 'owner']),
            'startup_gate.blocked' => self::item('Startup gate đã chặn ứng dụng', 'Được dùng khi startup gate ngăn dịch vụ khởi động.', 'telegram', '{level_marker} [XCrawler][{level}] Startup gate blocked service startup', 'Service {service} did not start because {reason}.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'service' => 'crawler', 'reason' => 'required dependency is unavailable']),
            'crawl.success_rate_critical' => self::item('Tỷ lệ crawl rất thấp', 'Gửi khi tỷ lệ thành công xuống dưới 70% trong 15 phút.', 'telegram', '{level_marker} [XCrawler][{level}] Crawl success rate is critical', 'Success rate is {success_rate}% over the last {window_minutes} minutes (critical threshold {threshold}%).' . $footer, true, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'success_rate' => '68.2', 'window_minutes' => 15, 'threshold' => 70]),
            'crawl.success_rate_low.movies' => self::item('Tỷ lệ crawl phim thấp', 'Cảnh báo tỷ lệ crawl phim thấp theo loại dữ liệu.', 'telegram', '{level_marker} [XCrawler][{level}] Movie crawl success rate is low', 'Movie crawl success rate is {success_rate}% over the last {window_minutes} minutes (minimum {minimum_rate}%).' . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'success_rate' => '82.4', 'window_minutes' => 15, 'minimum_rate' => 85]),
            'crawl.success_rate_low.performers' => self::item('Tỷ lệ crawl diễn viên thấp', 'Cảnh báo tỷ lệ crawl diễn viên thấp theo loại dữ liệu.', 'telegram', '{level_marker} [XCrawler][{level}] Performer crawl success rate is low', 'Performer crawl success rate is {success_rate}% over the last {window_minutes} minutes (minimum {minimum_rate}%).' . $footer, true, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'success_rate' => '82.4', 'window_minutes' => 15, 'minimum_rate' => 85]),
            'dependency.unreachable' => self::item('Dịch vụ dữ liệu không kết nối được', 'Gửi khi PostgreSQL, Redis, MongoDB hoặc Elasticsearch không thể truy cập.', 'telegram', '{level_marker} [XCrawler][{level}] A required datastore is unreachable', '{dependency} did not pass its connection check. {reason}' . $footer, true, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'dependency' => 'PostgreSQL', 'reason' => 'Connection check failed.']),
            'resource.cpu_high' => self::item('CPU sử dụng cao', 'Gửi khi CPU của máy chủ vượt ngưỡng cảnh báo.', 'telegram', '{level_marker} [XCrawler][{level}] CPU usage is high', '{node} CPU usage is {value}% (warning threshold {threshold}%).' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'value' => 86, 'threshold' => 85]),
            'resource.memory_high' => self::item('RAM sử dụng cao', 'Gửi khi RAM của máy chủ vượt ngưỡng cảnh báo.', 'telegram', '{level_marker} [XCrawler][{level}] Memory usage is high', '{node} memory usage is {value}% (warning threshold {threshold}%).' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'value' => 86, 'threshold' => 85]),
            'resource.disk_high' => self::item('Đĩa máy chủ gần đầy', 'Gửi khi đĩa vượt ngưỡng cảnh báo; mức trên 90% được nâng thành critical.', 'telegram', '{level_marker} [XCrawler][{level}] Disk usage is high', '{node} disk usage is {value}% (warning threshold {threshold}%).' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'value' => 88, 'threshold' => 85]),
            'resource.disk_critical' => self::item('Đĩa máy chủ gần đầy nghiêm trọng', 'Gửi khi đĩa vượt 90%.', 'telegram', '{level_marker} [XCrawler][{level}] Disk usage is critical', '{node} disk usage is {value}% (critical threshold {threshold}%).' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'value' => 92, 'threshold' => 90]),
            'node.left' => self::item('Node đã rời hệ thống', 'Gửi một lần khi node rời khỏi fleet.', 'telegram', '🟡 [XCrawler][NOTICE] Node {node} left the fleet', 'Node {node} stopped reporting at {left_at}. Fleet size is now {node_count}.' . $footer, false, 'notice', $meta + ['node' => 'worker-2', 'left_at' => 'Nov 2, 17:40', 'node_count' => 2]),
            'report.periodic' => self::item('Báo cáo trạng thái định kỳ', 'Mẫu thông báo cho báo cáo trạng thái định kỳ.', 'telegram', '📊 [XCrawler] Report · {report_time}', '{report_body}', true, 'info', $meta + ['report_time' => 'Nov 2, 18:00', 'report_body' => 'No report data is available for this window.']),
            'report.daily' => self::item('Báo cáo trạng thái hằng ngày', 'Mẫu thông báo cho báo cáo trạng thái 24 giờ.', 'telegram', '📊 [XCrawler] Daily report · {report_time}', '{report_body}', true, 'info', $meta + ['report_time' => 'Nov 3, 08:00', 'report_body' => 'No report data is available for this window.']),
            'deploy.ok' => self::item('Triển khai hoàn tất', 'Thông báo một lần khi tất cả node hoàn tất triển khai.', 'telegram', '🚀 [XCrawler][NOTICE] Deployment {release} finished', 'Release {release} finished on {node_count} nodes: {nodes}.' . $footer, false, 'notice', $meta + ['release' => 'release-2026-11-03', 'node_count' => 3, 'nodes' => 'xcrawler · worker-1 · worker-2']),
            'egress.changed' => self::item('Địa chỉ egress đã thay đổi', 'Thông báo khi địa chỉ egress của node thay đổi.', 'telegram', '🟡 [XCrawler][NOTICE] Egress IP changed', 'Node {node} now exits through {current_ip}; previous address was {previous_ip}.' . $footer, false, 'notice', $meta + ['node' => 'worker-1', 'current_ip' => '203.0.113.12', 'previous_ip' => '203.0.113.11']),
            'egress.shared' => self::item('Địa chỉ egress bị dùng chung', 'Thông báo khi nhiều node dùng cùng địa chỉ egress.', 'telegram', '🟡 [XCrawler][NOTICE] Egress IP is shared', 'Nodes {nodes} are using the same egress address {current_ip}.' . $footer, false, 'notice', $meta + ['nodes' => 'worker-1 · worker-2', 'current_ip' => '203.0.113.12']),
            'retry.permanent_failures' => self::item('Một số lượt thử lại đã cạn', 'Tổng hợp hằng ngày các mục hết số vòng thử lại.', 'telegram', '{level_marker} [XCrawler][{level}] Retry rounds were exhausted', '{failure_count} retry items exhausted all rounds. Top sources: {sources}.' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'failure_count' => 8, 'sources' => 'javbus: 5, ffjav: 3']),
            'jobs.failed_spike' => self::item('Số job lỗi tăng cao', 'Gửi khi số job thất bại vượt ngưỡng trong cửa sổ.', 'telegram', '{level_marker} [XCrawler][{level}] Failed jobs have spiked', '{failed_jobs} jobs failed in the last {window_minutes} minutes (threshold {threshold}).' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'failed_jobs' => 28, 'window_minutes' => 15, 'threshold' => 25]),
            'jobs.deadlock_burst' => self::item('Deadlock tăng cao', 'Gửi khi số deadlock vượt ngưỡng trong cửa sổ.', 'telegram', '{level_marker} [XCrawler][{level}] Database deadlocks have spiked', '{deadlocks} deadlocks were recorded in the last {window_minutes} minutes (threshold {threshold}).' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'deadlocks' => 6, 'window_minutes' => 15, 'threshold' => 5]),
            'browser.saturated' => self::item('Browser host quá tải', 'Gửi khi các slot trình duyệt đầy và có việc đang chờ.', 'telegram', '{level_marker} [XCrawler][{level}] Browser host is saturated', '{host} has {in_flight} active requests and {queued} waiting (threshold {threshold_minutes} minutes).' . $footer, false, 'warning', $meta + ['level' => 'WARNING', 'level_marker' => '🟠', 'host' => 'browser-1', 'in_flight' => 8, 'queued' => 4, 'threshold_minutes' => 10]),
            'browser.down' => self::item('Dịch vụ trình duyệt không hoạt động', 'Gửi khi endpoint Playwright hoặc FlareSolverr lỗi liên tục.', 'telegram', '{level_marker} [XCrawler][{level}] Browser service is unavailable', '{service} has failed health checks for {duration_minutes} minutes.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'service' => 'Playwright', 'duration_minutes' => 3]),
            'sentry.new_critical' => self::item('Sentry có lỗi mới', 'Thông báo khi Sentry phát hiện một nhóm lỗi mới.', 'telegram', '🟠 [XCrawler][WARNING] New Sentry error', '{group_title} · {event_count} events · {project}.' . $footer, false, 'warning', $meta + ['group_title' => 'Database connection failures', 'event_count' => 12, 'project' => 'javmeta']),
            'backup.ok' => self::item('Sao lưu thành công', 'Thông báo sau khi tác vụ sao lưu hoàn tất.', 'telegram', '🔵 [XCrawler][INFO] Database backup completed', 'Backup of {target} finished at {completed_at}.' . $footer, false, 'info', $meta + ['target' => 'database', 'completed_at' => 'Nov 2, 17:40']),
            'startup.blocked' => self::item('Startup gate đã chặn ứng dụng', 'Gửi khi dependency chính ngăn dịch vụ khởi động.', 'telegram', '{level_marker} [XCrawler][{level}] Startup gate blocked service startup', 'Service {service} did not start because {reason}.' . $footer, false, 'critical', $meta + ['level' => 'CRITICAL', 'level_marker' => '🔴', 'service' => 'crawler', 'reason' => 'required dependency is unavailable']),
            'security.setting_changed' => self::item('Cài đặt hệ thống đã thay đổi', 'Gửi khi chủ sở hữu thay đổi một cài đặt không bí mật.', 'telegram', '🔵 [XCrawler][INFO] System setting changed', 'Setting {setting_key} was changed by {actor}.' . $footer, false, 'info', $meta + ['setting_key' => 'queue.feed_pool_limit', 'actor' => 'owner']),
        ];

        foreach ($items as $key => &$item) {
            $item += self::policyDefaults($key, $item['default_severity']);
        }
        unset($item);

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, string|int|float|bool> */
    public function samples(string $key): array
    {
        $definition = $this->get($key);
        if ($definition === null) {
            return [];
        }

        $samples = [];
        foreach ($definition['variables'] as $variable) {
            $samples[$variable['name']] = $variable['sample'];
        }

        return $samples;
    }

    /** @param array<string, string|int|float|bool> $samples
     *  @return array<string, mixed>
     */
    private static function item(string $name, string $usage, string $channel, string $title, string $body, bool $enabled, string $severity, array $samples): array
    {
        $variables = [];
        foreach ($samples as $key => $sample) {
            $variables[] = ['name' => $key, 'sample' => $sample];
        }
        $placeholders = [];
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $title . $body, $matches);
        $placeholders = array_unique($matches[1]);
        foreach (['time' => 'Nov 2, 17:40 (Mexico)', 'node' => 'xcrawler'] as $key => $sample) {
            if (in_array($key, $placeholders, true) && ! in_array($key, array_column($variables, 'name'), true)) {
                $variables[] = ['name' => $key, 'sample' => $sample];
            }
        }

        $titleTemplate = $channel === 'telegram' && str_contains($title, '[XCrawler][') && ! str_starts_with($title, '<b>')
            ? '<b>' . $title . '</b>'
            : $title;

        return [
            'name' => $name,
            'usage' => $usage,
            'channel' => $channel,
            'title_template' => $titleTemplate,
            'body_template' => $body,
            'enabled' => $enabled,
            'default_severity' => $severity,
            'variables' => $variables,
        ];
    }

    /** @return array{tier: string, cooldown_minutes: int, escalation_minutes: int|null, include_in_periodic_report: bool, include_in_daily_report: bool, send_resolved: bool} */
    private static function policyDefaults(string $key, string $severity): array
    {
        $critical = str_starts_with($key, 'security.') || in_array($key, [
            'crawl.stalled', 'crawl.heartbeat_stale', 'node.lost',
            'dependency.unreachable', 'crawl.success_rate_critical', 'resource.disk_critical',
            'backup.manifest_missing', 'backup.stale', 'startup_gate.blocked', 'startup.blocked',
            'browser.down', 'flaresolverr.down', 'security.api_key_created', 'security.api_key_revoked',
            'security.login_failures', 'security.guest_mode_toggled', 'security.settings_changed',
            'security.setting_changed',
        ], true);
        $notice = in_array($key, [
            'deploy.success', 'deploy.ok', 'node.joined', 'node.left',
            'network.egress_ip_changed', 'egress.changed',
        ], true);
        $dailyOnly = in_array($key, [
            'source.exhausted', 'retry.permanent_failures', 'queue.retry_permanent_failures',
            'jobs.failed_spike', 'queue.failed_jobs_spike',
        ], true);
        $reportTemplate = str_starts_with($key, 'report.');
        $warning = $severity === 'warning' || in_array($key, [
            'crawl.success_rate_low.movies', 'crawl.success_rate_low.performers',
            'database.connections_high', 'sentry.new_critical_group',
        ], true);
        $tier = $critical ? 'critical' : ($notice ? 'notice' : ($key === 'source.exhausted' ? 'info' : ($warning ? 'warning' : $severity)));

        return [
            'tier' => $tier,
            'cooldown_minutes' => $key === 'network.egress_ip_changed' || $key === 'egress.changed' ? 360
                : (in_array($key, ['backup.manifest_missing', 'backup.stale'], true) ? 720
                    : ($key === 'report.periodic' ? 120 : ($key === 'report.daily' ? 1440 : (in_array($tier, ['critical', 'warning'], true) ? 120 : ($tier === 'notice' ? 5 : 0))))),
            'escalation_minutes' => $warning ? 60 : null,
            'include_in_periodic_report' => ! $dailyOnly && ! $reportTemplate && in_array($tier, ['warning', 'notice', 'info'], true),
            'include_in_daily_report' => ! $reportTemplate && in_array($tier, ['warning', 'notice', 'info'], true),
            'send_resolved' => $tier === 'critical',
        ];
    }
}

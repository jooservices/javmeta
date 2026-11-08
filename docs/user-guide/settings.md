# Cài đặt

[Mục lục](README.md) · [Tra cứu mọi nhóm và từng mục](settings-reference.md)

## Khi nào dùng

Đổi cách hiển thị, cấu hình tính năng hoặc địa chỉ dịch vụ mà không sửa tệp cấu hình máy chủ.

## Cách dùng

Giao diện có Chủ đề (Tối, Sáng, Hệ thống) và Mật độ (Vừa, Gọn), lưu riêng trong trình duyệt. Hệ thống có Mở vận hành và Đăng xuất (có xác nhận). Ô Lọc cài đặt thu hẹp danh sách theo tên hoặc khoá. Tab Thu thập & hàng đợi tách Hàng đợi, Worker và Vòng lặp thu thập.

Cấu hình hệ thống lưu trong cơ sở dữ liệu. Mỗi nhóm có nút Lưu riêng: sửa giá trị rồi bấm Lưu, đợi “Đã lưu” và trang tải lại. DB nghĩa là đã lưu; mặc định nghĩa là đang dùng giá trị có sẵn. Nhãn “khởi động lại” yêu cầu người quản lý khởi động lại tiến trình được nêu.

[Settings reference](settings-reference.md) lists all groups: Queues, Workers, Browsers, Cleanup, Timeouts, Crawl loops, Alerts, Telegram, Hourly reports, Readiness, Search, Observability, Backups, AI (Ollama and embeddings), Follow notifications (Telegram), Performer normalization, Domains, On-disk library, Downloads (qBittorrent), and File servers. Names, descriptions, and limits are generated from the application registry.

The **Performer normalization** group manages the A/B/O/AB blood-type allow-list, A–K cup allow-list, placeholder tokens, measurement and date ranges, Japanese prefecture/city aliases, and backfill chunk size. Preview changes with `performers:backfill-normalization`; it uses the configured chunk size unless `--chunk` overrides it. Use `--apply` when ready to update rows and queue search reindexing.

Nhịp request mặc định của mỗi nguồn được lưu trong bản ghi nguồn ở cơ sở dữ liệu. 14 nguồn mới từ CrawlerX 1.5.0 bắt đầu với khoảng nghỉ 8 giây, tối thiểu 4 giây và tối đa 60 giây. Có thể điều chỉnh theo từng mã nguồn bằng `crawl.source_gap_overrides` trong Cài đặt → Vòng lặp crawl; thiết lập này cũng được lưu trong cơ sở dữ liệu.

Các ô bí mật hiện trạng thái đã đặt mà không trả lại giá trị; Telegram bot token và chat ID được quản lý trong runtime secrets, không phải trên trang này. Danh sách thư mục hoặc mạng có thể nhập mỗi dòng một mục. Không bật Tải về, Máy chủ tệp hay AI đám mây khi chưa có quyết định cho phép tương ứng. Thay đổi model hoặc số chiều tìm kiếm cần quy trình dựng lại chỉ mục của người quản lý.

## Nếu có vẻ sai

Đọc lỗi cạnh nút Lưu: số ngoài giới hạn hoặc giá trị sai sẽ bị từ chối. Nếu chỉ thấy Giao diện/Hệ thống mà thiếu Cấu hình hệ thống, tải lại hoặc đăng nhập lại; việc đọc cấu hình có thể đã lỗi. Không xoá một giá trị chỉ vì đang thấy nhãn mặc định. Thay tên miền ở đây không tự sửa cấu hình mạng.

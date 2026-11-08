# Mẫu thông báo

Trang **Cài đặt → Thông báo** chỉ dành cho chủ sở hữu đã đăng nhập. Trang liệt
kê từng loại thông báo, mục đích, kênh, trạng thái bật/tắt, lần gửi gần nhất và
lần chỉnh sửa gần nhất.

Chọn **Sửa** để thay tiêu đề hoặc nội dung. Dùng danh sách biến được phép để chèn
placeholder như `{source_slug}`; giá trị trong cột mẫu chỉ dùng cho xem trước.
Xem trước cập nhật khi nội dung đổi. **Gửi thử** gửi đúng nội dung đang soạn tới
Telegram của chủ sở hữu. **Lưu mẫu** tạo phiên bản mới và giữ bản trước trong
lịch sử. **Khôi phục mặc định** lấy nội dung tiếng Anh từ registry; phần lịch sử
có thể phục hồi từng bản đã lưu.

Mẫu chỉ thay thế placeholder cho phép, không chạy mã hay template engine. Các
giá trị thay thế được escape theo HTML của Telegram. Mẫu bị thiếu hoặc hỏng dùng
lại nội dung mặc định để không làm gián đoạn cảnh báo. Các loại chưa có sender
được đăng ký sẵn và tắt cho đến khi ticket tương ứng nối trigger.

Lệnh SSH chỉ đọc:

```sh
php artisan notifications:list
php artisan notifications:render crawl.dispatch_delayed --sample
```

Xem [vận hành thông báo](../03-operations/notifications.md) và
[ma trận quyền guest](../03-operations/guest-mode.md) để biết sender và quyền
truy cập chi tiết.

## Khi nào dùng

Dùng trang này khi cần sửa cách một cảnh báo được diễn đạt, kiểm tra thay đổi
bằng dữ liệu mẫu hoặc quay lại một phiên bản trước. Mẫu OpenObserve cần được
đồng bộ lại sau khi sửa bằng `php artisan obs:import-alerts`.

## Nếu có vẻ sai

Nếu preview từ chối nội dung, kiểm tra tên placeholder trong danh sách biến và
xóa chuỗi trông giống token hoặc secret. Nếu gửi thử thất bại, kiểm tra trạng
thái Telegram trong Cài đặt; mẫu đã lưu vẫn được giữ. Lỗi template lúc phát
cảnh báo sẽ dùng lại nội dung mặc định từ registry.

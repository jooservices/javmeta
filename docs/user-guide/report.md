# Báo cáo Telegram

Mục **Cài đặt → Báo cáo** cho phép chủ sở hữu bật lịch, chọn chu kỳ, múi giờ,
giờ yên lặng, các phần nội dung và mẫu tiếng Anh. Mặc định báo cáo được gửi
mỗi hai giờ theo các mốc chẵn trong `America/Mexico_City`. Giờ yên lặng bỏ qua
mốc trùng giờ; hệ thống không gửi bù sau đó.

Trang hiển thị bản xem trước trực tiếp, kết quả lần gửi gần nhất và tối đa 20
bản ghi gần đây. **Gửi ngay qua Telegram** gửi một lần mà không thay đổi lịch
định kỳ. Mẫu có thể sửa nhưng báo cáo mặc định và các nhãn dữ liệu được tạo bằng
tiếng Anh.

Số liệu crawl tách theo công việc phim và diễn viên. Dữ liệu máy chủ và worker
được lấy từ mẫu mỗi phút; một nút không có mẫu mới sẽ hiện `no data`. Các lần
thử tải cũ hơn bảy ngày không có hàng đợi tương ứng không được suy đoán loại
công việc.

## Khi nào dùng

Dùng bản xem trước để kiểm tra các phần trước khi bật lịch. Chọn giờ yên lặng
khi không muốn nhận báo cáo ở một khoảng thời gian; để trống cả hai giờ để tắt
tính năng này. Chọn các mục trong **Các phần trong báo cáo** để giới hạn nội
dung, rồi nhấn **Lưu cài đặt**.

## Nếu có vẻ sai

Nếu xem trước không có số liệu cho một nút, kiểm tra `php artisan node:sample`
trên chính nút đó và xác nhận cache Redis dùng chung đang hoạt động. Nếu nút
Telegram báo lỗi, kiểm tra cấu hình Telegram ở Cài đặt và xem kết quả lần gửi
gần nhất. `php artisan javmeta:hourly-report --dry-run` in bản xem trước qua
terminal mà không gửi tin.

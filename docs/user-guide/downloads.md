# Tải về

[Mục lục](README.md)

## Khi nào dùng

Xem magnet/torrent đã lưu trên trang phim, bấm **Tìm link tải** khi chưa có link, và (chỉ chủ sở hữu) gửi torrent sang qBittorrent. Khách cũng thấy magnet và có thể tìm link; nút **Gửi tới qBittorrent** và trang Tải về vẫn chỉ dành cho chủ đã đăng nhập. Tính năng qBittorrent mặc định **TẮT** cho tới khi chủ sở hữu duyệt quyết định JOOXCR-223 rồi bật trong Cài đặt → Tải về.

## Cách dùng

Mở một phim: panel **Tải về** (`#movie-downloads`) liệt kê magnet đã lưu, cho Sao chép / Mở magnet. Nếu chưa có link, bấm **Tìm link tải** — hệ thống hỏi tuần tự các nguồn có `offers_downloads` (có ngân sách và thời gian nghỉ sau khi không tìm thấy). Chủ sở hữu đã bật qBittorrent có thêm **Gửi tới qBittorrent**.

Trang Tải về (menu Mine) chỉ dành cho chủ: phần trăm, tốc độ, ETA; tự làm mới mỗi 5 giây; chỉ quản lý torrent thuộc danh mục đã cấu hình. Dùng Tạm dừng hoặc Tiếp tục. Xoá hỏi xác nhận riêng cho mục tải và tệp. Mục xong có Liên kết; liên kết tệp cần bật Máy chủ tệp, chỉ mở trong mạng cho phép và sẽ hết hạn.

## Nếu có vẻ sai

“Tải về đang tắt” trên trang queue là bình thường trước khi bật qBittorrent. Panel trên trang phim vẫn hiện magnet và nút tìm link khi Cài đặt cho phép. “Đã hết hạn mức tìm” hoặc nghỉ sau không tìm thấy là bảo vệ egress — thử lại sau. Nếu lỗi kết nối qBittorrent, nhờ người quản lý kiểm tra địa chỉ, tài khoản và đường dẫn lưu. Liên kết hết hạn thì lấy lại từ mục xong.

Chỉ các tệp video, âm thanh, phụ đề và ảnh thuộc danh sách phần mở rộng cho phép được mở trực tiếp. HTML, SVG, PDF và các định dạng khác được tải xuống dưới dạng tệp nhị phân. Magnet BTIH dạng base32 được chuẩn hoá thành mã hex để theo dõi và xoá đúng mục tải.

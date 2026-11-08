# Quản trị

[Mục lục](README.md)

## Khi nào dùng

Cấp quyền cho ứng dụng khác đọc dữ liệu hoặc kiểm tra độ đầy đủ của kho phim.

## Cách dùng

Chất lượng dữ liệu hiển thị tỷ lệ các trường đã có, số phim đạt ít nhất 8/11 trường, các tầng đầy đủ và số cần xem lại. Phim theo nguồn cho biết độ phủ từng nguồn. Đây là báo cáo thông tin, không đánh giá chất lượng nội dung.

Trong Khoá API, nhập Tên khoá và giới hạn /phút; đánh dấu quyền cần dùng rồi Tạo khoá. Khoá chỉ hiện đầy đủ một lần: Sao chép và cất vào nơi lưu bí mật của ứng dụng. Không chọn quyền nào nghĩa là được dùng tất cả, nên hãy chọn quyền cụ thể khi có thể.

| Quyền trên UI | Cho phép |
| --- | --- |
| Phim | Danh sách, chi tiết, bộ lọc, lịch, phim liên quan và tìm kiếm tổng hợp |
| Tra hàng loạt | Tra nhiều mã phim một lần |
| Diễn viên | Danh sách, bộ lọc và hồ sơ diễn viên |
| Danh mục & gợi ý | Các danh mục và gợi ý tìm kiếm |
| Trạng thái | Tổng quan và tình trạng nguồn |
| MCP | Gọi công cụ MCP |

Mức dùng cho biết số lệnh, lỗi và thời gian trung bình trong 24 giờ/7 ngày. Thu hồi ngừng khoá đang dùng sau xác nhận; ứng dụng phải dùng khoá khác. Khoá API không cho phép sửa Cài đặt hoặc quản trị chủ sở hữu.

## Nếu có vẻ sai

Mất khoá đầy đủ thì tạo khoá mới rồi thu hồi khoá cũ. Bị từ chối thì kiểm tra quyền và trạng thái; vượt /phút thì đợi rồi giảm tần suất. UI hiện báo cáo chất lượng; báo cáo chi phí và nguồn còn có qua API dành cho người quản lý, không có thêm nút ở trang này.

# Telegram

[Mục lục](README.md)

## Khi nào dùng

Tra nhanh phim, diễn viên và tình trạng hệ thống; nhận tin khi có phim mới của mục đang theo dõi.

## Cách dùng

Người quản lý đặt Token của bot, Chat ID nhận tin và Bật bot hỏi đáp trong Cài đặt → Telegram. Chỉ chat đã cấu hình được dùng. Bot kiểm tra tin mỗi phút, nên câu trả lời có thể đến chậm hơn chat thông thường.

| Lệnh nhắn cho bot | Kết quả |
| --- | --- |
| `/start`, `/help` | Xem trợ giúp |
| `/search Drama` hoặc gõ `Drama` | Gợi ý phim, diễn viên, thể loại, maker và series; tối thiểu 2 ký tự |
| `/movie SSIS-001` | Thông tin của mã phim đã có trong kho và nút Mở |
| `/status` | Số phim, diễn viên, việc chờ và lỗi |
| `/follow maker Mankitsu` | Theo dõi maker có tên đó |
| `/follow series Series One` | Theo dõi series có tên đó |
| `/follow performer Yua Mikami` | Theo dõi diễn viên theo tên romaji hoặc kanji chính xác |
| `/unfollow maker Mankitsu` | Bỏ theo dõi; cũng nhận `series` hoặc `performer` |
| `/follows` | Danh sách đang theo dõi |
| `/download SSIS-001` | Yêu cầu tải phim khi tính năng đã bật |
| `/downloads` | Tối đa 15 mục tải và tiến độ |

Tên và mã ở đây chỉ là ví dụ; thay bằng giá trị trong kho của bạn. Bật Gửi thông báo phim mới của mục đang theo dõi trong nhóm Thông báo theo dõi (Telegram). Cứ 5 phút, hệ thống xét phim mới ghi nhận trong khoảng ngày đã đặt, gửi ảnh bìa nếu có, lý do theo dõi và nút Mở. Nút tải chỉ có khi bật tải về. Giới hạn ngày và giờ yên lặng giữ các tin chưa gửi lại để xét sau; dùng múi giờ báo cáo. Phim quá khoảng ngày xem lại có thể không còn được gửi.

## Nếu có vẻ sai

Kiểm tra đúng chat, token đã đặt, bot đã bật và tiến trình lịch đang chạy. Không thấy diễn viên thì dùng đúng tên, không dùng số ID trong lệnh follow. Không có thông báo có thể do giờ yên lặng, giới hạn ngày hoặc phim đã được báo. Tin trả lời bị mất do lỗi gửi không được tự gửi lại; thử hỏi lại. Khi tải chưa bật, bot trả “Tải về đang tắt trong Cài đặt.”

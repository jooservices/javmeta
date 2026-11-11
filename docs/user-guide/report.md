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

## Độ phủ catalog

Độ phủ catalog tách **phim duy nhất** và **bản ghi nguồn**:

- **Phim duy nhất** là số canonical DVD ID khác nhau sau chuẩn hóa. Một phim có
  cùng ID ở nhiều nguồn chỉ tính một lần. Bản ghi không có canonical DVD ID chưa
  thể phân loại bằng chỉ số này; không suy ID từ tiêu đề hoặc URL.
- **Bản ghi nguồn** là số liên kết phim–nguồn đang được lưu. Một phim có mặt ở
  hai nguồn đóng góp một phim duy nhất và hai bản ghi nguồn. Tải lại cùng phim
  từ cùng nguồn cập nhật liên kết hiện có; đây không phải số lần crawl hay lịch
  sử các lần crawl.
- **Độ phủ theo nguồn** chỉ tính được khi biết tổng catalog bên ngoài của nguồn:
  số canonical DVD ID khác nhau đã thấy ở nguồn đó chia cho số canonical DVD ID
  khác nhau trong catalog bên ngoài của nguồn, nhân 100%. Tổng phải cùng phạm vi
  ID và có thời điểm chụp được ghi lại.
- **Độ phủ toàn cục** (nếu được tính sau này) là số canonical DVD ID khác nhau
  đã thu thập chia cho số ID khác nhau trong hợp catalog bên ngoài. Cả tử và mẫu
  đều phải khử trùng; không thể cộng tổng từng nguồn khi chưa biết phần giao.

Không cộng tổng catalog giữa các nguồn để suy ra tổng phim toàn thị trường: cùng
một ID có thể xuất hiện ở nhiều catalog. Khi chưa biết phần giao giữa các nguồn,
tổng ID trong hợp catalog bên ngoài và độ phủ toàn cục vẫn là **không xác định**.

### Dữ liệu hiện có và giới hạn

Khi phần tương ứng được bật, báo cáo Telegram hiển thị tổng số hàng trong bảng
`movies`, số tăng theo kỳ, thống kê lần thử crawl phim/diễn viên trong cửa sổ báo
cáo và danh sách tối đa năm nguồn bị chặn hoặc hết lượt thử. Tổng `movies` là số
hàng được lưu, không phải chỉ số đã khử trùng theo canonical DVD ID. Báo cáo hiện
không đếm liên kết trong `movie_sources`, không có tổng catalog bên ngoài theo
nguồn và không đo phần giao giữa các catalog. Vì vậy các số liệu này mô tả dữ
liệu và hoạt động crawl của JavMeta, không chứng minh độ phủ của catalog bên
ngoài.

Các lần chạy báo cáo lưu thời điểm tạo và cửa sổ đo. Hãy đọc số tổng trong một
bản cũ như số liệu tại thời điểm của bản đó; không trình bày nó như số hiện tại.

Trước khi công bố phần trăm độ phủ, cần có tổng ID catalog bên ngoài theo từng
nguồn, thời điểm chụp catalog và phép đối chiếu ID đã chuẩn hóa. Hiện chưa có bằng
chứng cho tổng catalog bên ngoài hoặc phần giao giữa các nguồn, nên cả hai vẫn
là **không xác định**. Nếu tổng nguồn chưa biết, tỷ lệ và thời gian hoàn tất đều
không xác định. Nếu tổng đã xác minh bằng 0, tỷ lệ là không áp dụng thay vì chia
cho không. Nếu tốc độ thu thập đo được bằng 0, không ước tính thời gian hoàn tất.
Khi tổng dương đã biết nhưng chưa thấy ID nào, độ phủ quan sát được là 0%; điều
này không tạo ra ước tính thời gian hoàn tất.

Các trường báo cáo được tạo từ [ReportDataBuilder](../../app/Services/Report/ReportDataBuilder.php)
và [ReportDataServiceRepository](../../app/Repositories/ReportDataServiceRepository.php).
Ranh giới giữa bản ghi nguồn và phim được lưu trong [MovieSource](../../app/Models/MovieSource.php)
và lược đồ [movie_sources](../../database/migrations/2026_09_18_120000_add_polyglot_movie_fields.php).
Các loại mã phim, gồm DVD, được định nghĩa trong [MovieCode](../../app/Models/MovieCode.php)
và lược đồ [movie_codes](../../database/migrations/2026_09_18_000001_create_jvmeta_schema.php).

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

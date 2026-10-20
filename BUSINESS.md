# BUSINESS PLAN — "JAV Metadata API" (codename `javmeta`)

> **Current state (October 2026).** The approved business plan below is unchanged; this note records where the POC stands.
> The tools to judge the POC now exist: `php artisan javmeta:report quality|cost|sources` (fill rate of the 11 core fields, conflicts
> between sources, crawl cost per source with a projection to 100k and 400k titles, source freshness), `javmeta:verify-sample <file>`
> (compare a list of codes with the stored data) and `crawler:source-health` (which of the 24 active sources work). Search is
> available by text and by meaning, and the data is served through the REST API and MCP. Billing, takedown handling and the
> commercial roadmap remain deferred as decided; see [README.md](README.md) for the system as it runs.


Status: **Approved** (requirements gate closed, 2026-09-17). Source of truth;
decision log ở mục 9. Root overview:
[`docs/01-projects/jvmeta/BUSINESS.md`](../../docs/01-projects/jvmeta/BUSINESS.md).

**Phase:** requirements (BA round) · **Trọng tâm đã chốt: POC trước, thương mại hoá hoãn**
**Tác giả:** `joo-ba` · bản ghi do `joo-doc-writer` từ nội dung root đã duyệt.
Tên sản phẩm cuối: TBD bởi owner — dùng codename `javmeta` (OQ-13).

---

## 0. Bối cảnh & giới hạn của vòng BA này

- Owner đã **đổi trọng tâm giữa chừng**: sau 2 vòng hỏi về mô hình kinh doanh, owner trả lời nhất quán *"dont care POC first" / "i need POC movie first"*. → Toàn bộ quyết định thương mại hoá **không bị bỏ**, mà được ghi lại ở **Decision Log (mục 9)** và **Roadmap hoãn (REQ-D)** để tái kích hoạt sau khi POC cho kết quả go/no-go.
- BA **không có** quyền truy cập repo / source / web. Mọi nội dung suy ra từ requirement text root inline + 4 vòng hỏi owner (22 câu, log ở mục 12) + cổng requirements gate 2026-09-17 (mục 12, vòng 5).
- BA **không** chọn kiến trúc / DB / framework / API design / schema — thuộc `joo-sa`. Các nhắc đến kỹ thuật của owner (`crawlerx`, server 24/7, proxy Nhật) được ghi **chỉ là ràng buộc**.
- Ngôn ngữ: tài liệu viết tiếng Việt theo rule chat của workspace; identifier kỹ thuật giữ nguyên tiếng Anh. Nếu nội dung này đổ vào JIRA thì **bắt buộc phải dịch sang tiếng Anh** (rule English-only cho JIRA artifact) — xem Open Question OQ-10.

---

## 1. Problem statement

Người dựng thư viện phim (media-server scraper) và nhà phát triển công cụ nội dung người lớn Nhật Bản **không có một nguồn metadata hợp nhất, ổn định, tra cứu được bằng API**. Metadata gốc nằm rải rác: FANZA/DMM (giàu nhất, song ngữ, nhưng **chặn IP ngoài Nhật**), javdb / javlibrary / javdatabase (catalog cộng đồng, mỗi nơi một taxonomy, hay đổi domain), missav / sextb (stream URL). Muốn có một bản ghi đầy đủ cho `SSIS-001`, hôm nay người dùng phải tự crawl nhiều site, tự xử lý geo-block, anti-bot, tự khớp tên diễn viên đa ngôn ngữ, tự dedup — việc này tốn công, dễ vỡ và lặp lại cho từng người.

Đối thủ thương mại đã tồn tại (**javinfo.dev**) chứng minh **có thị trường trả tiền** cho việc này. Khoảng trống còn lại mà owner muốn chiếm: **độ phủ dữ liệu** (DB lớn nhất có thể, gồm cả mảng amateur/FC2 mà nguồn gốc không phủ) và **độ tươi** (crawl nền 24/7).

**Vấn đề cần giải quyết ngay bây giờ (POC):** owner chưa có bằng chứng rằng chuỗi *crawl đa nguồn → chuẩn hoá về một bản ghi → phục vụ qua API* chạy được thật, với chi phí và độ tin cậy chấp nhận được, trên hạ tầng 1 server + proxy Nhật. Chưa có bằng chứng đó thì mọi quyết định định giá / quy mô / pháp lý đều là phỏng đoán.

---

## 2. Goals / business value

### 2.1 Mục tiêu POC (đo được — đây là mục tiêu đang active)

| ID | Mục tiêu | Thước đo |
|---|---|---|
| **G-1** | Chứng minh chuỗi end-to-end: crawl → chuẩn hoá → API lookup/search | Demo chạy được trên **≥2 nguồn thật**, không dùng dữ liệu giả |
| **G-2** | DB POC đạt quy mô đủ để đánh giá chất lượng chuẩn hoá | **1.000–5.000 title** đã chuẩn hoá (owner chốt) |
| **G-3** | Chất lượng dữ liệu đạt ngưỡng dùng được | Với **mẫu đối chiếu 200 code** do owner chọn: ≥**90%** code tìm thấy; ≥**8/11 field lõi** không rỗng ở ≥**80%** bản ghi tìm thấy |
| **G-4** | Đo được **chi phí thật** để ngoại suy quy mô | Số request proxy + giờ crawl + số lỗi **trên mỗi 1.000 title**, tách theo nguồn → ngoại suy được chi phí/thời gian cho 100k và 400k title |
| **G-5** | Đo được **mức độ xung đột** metadata giữa các nguồn | Tỉ lệ bản ghi có ≥1 field mâu thuẫn, tách theo từng field → làm bằng chứng chọn rule "nguồn nào thắng" sau này |
| **G-6** | Chứng minh crawl chạy nền không cần trông | Chạy liên tục **72 giờ** không can thiệp tay, không mất dữ liệu đã crawl |
| **G-7** | Owner ra được quyết định **go/no-go** | Báo cáo POC (REQ-8) đủ số liệu để owner quyết định có đầu tư tiếp thành sản phẩm thương mại hay không |

### 2.2 Mục tiêu sản phẩm (hoãn — owner từ chối chốt mốc ở vòng 3)

- **Khác biệt "largest DB":** owner **chưa chốt mốc số**. BA đề xuất (chưa được duyệt) mốc 2 tầng: GA ≥250k title / ≥80k performer, mới ≤24h → sau 3–6 tháng lên 400k / 100k, mới ≤6h. **Lý do đề xuất:** DMM/FANZA commercial thực tế ~250–350k; muốn tuyên bố "lớn nhất" thật sự thì **bắt buộc phải gộp amateur/FC2/doujin** (lên 500–700k) — mà owner đã chọn gộp mảng này ở vòng 2. Lưu ý: sau D-23, đơn vị đếm là **movie** (mỗi movie một bản ghi, biến thể gộp chung). → **OQ-3**.
- **Phân khúc ưu tiên (đã chốt ở vòng 1):** (1) media-server scrapers (Jellyfin/Plex/Emby/Kodi), (2) data resellers / affiliate marketers.
- **Giá trị kinh doanh:** doanh thu từ API trả phí (hybrid subscription + credit), crypto-only ở v1; lợi thế cạnh tranh = độ phủ + độ tươi, không phải giá.

---

## 3. Actors và workflows

### 3.1 Actors

| Actor | Loại | Vai trò |
|---|---|---|
| **Owner / Operator** | Người | Nghiệm thu POC, quyết định go/no-go, cấp & thu hồi API key tĩnh, đặt ngưỡng lạm dụng, duyệt mẫu đối chiếu |
| **API Consumer (POC)** | Người/hệ thống | Dev được cấp key tĩnh, gọi lookup/search/bulk để đánh giá |
| **Crawler** | Tác nhân hệ thống | Chạy nền 24/7: initial full crawl + incremental re-crawl, tự giảm tốc khi bị chặn |
| **Data Source** | Hệ thống bên ngoài | FANZA/DMM, javdb, javdatabase, javlibrary, missav, sextb, magneto, mgstage/FC2, DLsite/Doujin + **các site `crawlerx` đã hỗ trợ** |
| **Proxy provider (JP)** | Bên thứ ba | Ràng buộc vận hành; hết dung lượng = crawl dừng |
| **Admin / Billing** | Người | **Không có trong POC** (owner làm tay); kích hoạt ở giai đoạn sản phẩm |
| **Requester takedown** | Người | **Không có trong POC**; bắt buộc trước GA |

### 3.2 Workflows (POC)

**WF-1 · Initial full crawl**
- *Trigger:* owner ra lệnh khởi động crawl trên tập nguồn đã chọn.
- *Steps:* (1) lấy danh sách code/title từ nguồn → (2) fetch qua proxy (nếu nguồn geo-block) → (3) trích xuất field thô theo nguồn → (4) chuẩn hoá field; genres theo **union đa nguồn** (AC-1.6); gộp biến thể về **một bản ghi movie** (BR-1) → (5) dedup theo BR-1 → (6) ghi DB kèm provenance (nguồn + thời điểm) → (7) cộng dồn số đo chi phí/lỗi.
- *Rejection paths:* nguồn trả CAPTCHA/soft-404 → đánh dấu "cần xem lại", **không** ghi bản ghi rỗng đè dữ liệu cũ; proxy hết dung lượng → tạm dừng nguồn đó, báo owner, các nguồn khác vẫn chạy; code không parse được → vào hàng đợi "code lạ", không bỏ âm thầm.

**WF-2 · Incremental re-crawl**
- *Trigger:* lịch (chu kỳ do owner đặt) hoặc phát hiện title mới.
- *Steps:* (1) quét nguồn theo thứ tự mới-cập-nật-trước → (2) so với bản ghi hiện có → (3) chỉ fetch phần thay đổi → (4) cập nhật field + `crawled_at` → (5) ghi log khác biệt (field nào đổi, từ nguồn nào).
- *Rejection paths:* nguồn đổi domain → WF-3; title đã bị gỡ khỏi nguồn (delisted) → **không tự xoá**, đánh dấu `delisted_at` (chính sách cuối cùng = **OQ-6**); fetch lỗi N lần liên tiếp → dừng retry, đưa vào hàng đợi xem lại.

**WF-3 · Nguồn chết / đổi domain**
- *Trigger:* tỉ lệ lỗi của một nguồn vượt ngưỡng, hoặc redirect sang domain lạ.
- *Steps:* (1) cô lập nguồn (không làm chết pipeline) → (2) cảnh báo owner ≤24h → (3) owner cập nhật cấu hình nguồn → (4) crawl lại phần bị gián đoạn.
- *Rejection path:* không khôi phục được trong X ngày → nguồn bị loại khỏi tập crawl, báo cáo ghi rõ phần dữ liệu nào mất độ tươi.

**WF-4 · Consumer lookup theo code**
- *Trigger:* consumer gọi API với code + key.
- *Steps:* (1) xác thực key → (2) chuẩn hoá code (BR-9) → (3) tìm bản ghi → (4) trả field lõi + provider extras + provenance → (5) ghi usage log.
- *Rejection paths:* key sai/thu hồi → **401, không trả dữ liệu**; code sai định dạng nhưng chuẩn hoá được → vẫn trả kết quả; code không tồn tại → **404 phân biệt rõ "không có trong DB"**; vượt ngưỡng lạm dụng → **429** kèm lý do.

**WF-5 · Consumer search/query**
- *Trigger:* consumer gọi search với keyword/filter/sort/page.
- *Steps:* (1) xác thực → (2) parse + validate filter → (3) truy vấn → (4) sort → (5) phân trang → (6) trả kết quả + tổng số.
- *Rejection paths:* filter vô nghĩa / query rỗng / ký tự đặc biệt → **400 có thông báo**, không 500; page vượt tổng → danh sách rỗng + tổng số, không lỗi; kết quả quá lớn → giới hạn trang cứng.

**WF-6 · Cấp & thu hồi API key tĩnh**
- *Trigger:* owner quyết định cho ai dùng thử.
- *Steps:* (1) tạo key có prefix nhận diện → (2) ghi hạn mức lạm dụng → (3) gửi tay cho consumer → (4) theo dõi usage log.
- *Rejection paths:* key lộ / lạm dụng → thu hồi **hiệu lực ngay lập tức**, mọi call sau nhận 401.

**WF-7 · Owner nghiệm thu POC → go/no-go**
- *Trigger:* G-2 đạt (1–5k title) và G-6 đạt (72h ổn định).
- *Steps:* (1) owner chọn mẫu đối chiếu 200 code → (2) mở báo cáo REQ-8 → (3) đối chiếu G-3/G-4/G-5 → (4) ra quyết định go / no-go / pivot.
- *Rejection path:* G-3 hoặc G-6 không đạt → **không** go; quay lại WF-1/WF-3 với tập nguồn hẹp hơn, hoặc dừng.

### 3.3 Workflows hoãn (đã mô tả, kích hoạt sau POC)

- **WF-8 · Signup → nạp crypto → trừ quota → hết hạn → khoá key** (điều kiện GA ở vòng 3 owner chọn "all of the above but consider later").
- **WF-9 · Takedown request** (bắt buộc trước GA theo lập trường pháp lý đã chốt).
- **WF-10 · Webhook/feed "mới phát hành"** cho khách đăng ký (owner chọn cho v1 GA, **không** nằm trong POC).

---

## 4. Functional requirements (POC scope)

> Mỗi REQ đều có người nghiệm thu = **Owner**. Kích cỡ coarse ở mục 11.

**REQ-1 · Chuẩn hoá metadata đa nguồn về một bản ghi nghiệp vụ thống nhất**
Một movie chỉ có **một** bản ghi chuẩn, bất kể lấy từ bao nhiêu nguồn và tồn tại bao nhiêu biến thể (BR-1). Field lõi: `code`, `title_jp`, `title_en`, `actresses` (+alias), `cover_url`, `release_date`, `runtime`, `maker`, `label`, `series`, `genres`. Provider extras: `magnets`/PikPak, `hls_stream_urls`, `gallery`, `community_score`. `cover_url`, `gallery`, sample và mọi media khác **chỉ là URL tham chiếu tới site nguồn bên thứ ba** — không cache media (BR-4, D-22). `genres` = **union** nhãn genre của mọi nguồn, không map taxonomy (AC-1.6, D-24). Mỗi giá trị kèm **provenance** (nguồn + thời điểm crawl).
*(BA ghi nhận: owner đã chốt bán cả magnet + HLS stream URL — full parity với đối thủ.)*

**REQ-2 · Nền crawl chạy nền 24/7 (initial + incremental)**
Initial full crawl trên tập nguồn POC đạt 1.000–5.000 title; incremental re-crawl theo lịch; tự giảm tốc/tạm dừng khi nguồn chặn; đo chi phí. **Ưu tiên nguồn theo owner: bắt đầu từ các site `crawlerx` đã hỗ trợ, rồi mở rộng "as much as possible"** theo thứ tự vòng 2: FANZA/DMM → javdb → javdatabase → javlibrary → missav+sextb → amateur/FC2 (mgstage, FC2) → magneto → DLsite/Doujin.
*(Đề xuất BA, chưa duyệt: tách REQ-2 thành 2a initial crawl / 2b incremental scheduler / 2c source resilience — xem mục 11.)*

**REQ-3 · Lookup một title theo code**
Nhận code dạng DVD (`SSIS-001`) hoặc ID amateur (`FC2-PPV-1234567`), trả bản ghi movie chuẩn hoá đầy đủ.

**REQ-4 · Search / query nhiều kết quả + filter + sort + phân trang**
Keyword (title / tên diễn viên romaji-kanji-kana-alias / code một phần); filter theo `genre` (trên danh sách genre union của movie), `actress`, `maker`, `series`, `label`, khoảng `release_date`, khoảng `runtime`, cờ censored; sort theo relevance / release_date / update_date / rating; phân trang ổn định.

**REQ-5 · Performer (idol) catalog — tập con POC**
Hồ sơ diễn viên suy ra từ dữ liệu đã crawl: romaji / kanji / kana / alias, số title liên kết, tìm kiếm theo alias. **Không** đặt ngưỡng số title tối thiểu. Chỉ số cơ thể & filter nâng cao = hoãn sau POC.

**REQ-6 · Bulk lookup nhiều code trong một call**
Giới hạn cứng ≤100 code/call; kết quả từng code kèm trạng thái riêng (found / not-found).

**REQ-7 · API key tĩnh + kiểm soát truy cập + usage log**
Key có prefix, cấp/thu hồi tay, hiệu lực thu hồi ngay; ghi log mọi call (key, endpoint, thời điểm, kết quả) để đo usage — **không thu tiền, không quota trả phí** trong POC; ngưỡng chống lạm dụng do owner đặt.

**REQ-8 · Báo cáo chất lượng dữ liệu & chi phí (công cụ go/no-go)**
Báo cáo cho owner: tổng title theo nguồn; % bản ghi có đủ từng field lõi; tỉ lệ xung đột giá trị giữa các nguồn **tách theo field**; số request proxy + giờ crawl + số lỗi theo nguồn; ngoại suy chi phí/thời gian cho 100k và 400k title; kết quả đối chiếu mẫu 200 code.

**REQ-9 · Health & giám sát crawl tối thiểu**
Endpoint health cho biết hệ thống sống/chết + lần crawl thành công gần nhất của từng nguồn; cảnh báo owner ≤24h khi nguồn chết/đổi domain; title crawl lỗi N lần liên tiếp được đánh dấu "cần xem lại" thay vì bỏ âm thầm.

### 4.1 Roadmap hoãn (đã elicit, **chưa Ready**, không size ở vòng này)

| ID | Nội dung | Lý do hoãn |
|---|---|---|
| REQ-D1 | Billing: hybrid subscription + credit pay-as-you-go, nạp crypto (USDT/USDC/BTC) | Owner: "dont care POC first" |
| REQ-D2 | Free tier: trial credits một lần + rate limit thấp | Đã chốt mô hình, chưa chốt con số |
| REQ-D3 | Reseller license & ToS phân phối lại dữ liệu | Owner: "dont care POC first" |
| REQ-D4 | Rule giải quyết xung đột dữ liệu chính thức (field-level authority) | Owner: "dont care POC first" → POC sẽ **đo** để quyết (G-5) |
| REQ-D5 | Webhook/feed "mới phát hành" | Owner chọn cho v1 GA, không phải POC |
| REQ-D6 | Random endpoint | Owner không chọn ở vòng 1 |
| REQ-D7 | Takedown process + điều khoản 18+ / age attestation | Bắt buộc **trước GA**, không cần cho POC nội bộ |
| REQ-D8 | Public docs + sandbox + onboarding + status page công khai | Chỉ cần khi có khách bên ngoài |
| REQ-D9 | Mốc "largest DB" chính thức + SLA freshness | Owner từ chối chốt (OQ-3) |
| REQ-D10 | Chỉ số cơ thể / filter nâng cao cho idol catalog | Ngoài tập con POC |

---

## 5. Acceptance criteria

**AC-1 (REQ-1)**
- AC-1.1 · Given một movie có mặt ở ≥2 nguồn hoặc tồn tại nhiều biến thể (censored/uncensored/re-release/leak), When truy vấn, Then nhận **đúng 1 bản ghi duy nhất** (không trùng lặp, không bản ghi đôi cho biến thể) chứa đủ 11 field lõi ở dạng có-giá-trị hoặc null-tường-minh.
- AC-1.2 · Given một field có giá trị khác nhau giữa 2 nguồn, Then bản ghi trả về **một** giá trị kèm tên nguồn + thời điểm crawl của giá trị đó, và giá trị bị loại vẫn truy vết được trong báo cáo (AC-8.1).
- AC-1.3 · Given không nguồn nào có một field, Then field trả về `null` — **không** phải chuỗi rỗng, **không** phải giá trị suy đoán.
- AC-1.4 · Given title có magnet / HLS URL / gallery / community score, Then các giá trị này gắn kèm bản ghi dưới dạng **URL tham chiếu / giá trị thô từ nguồn** (không cache media — BR-4) và ghi rõ nguồn + thời điểm lấy.
- AC-1.5 · Given title chỉ có ở nguồn cộng đồng (không có ở nguồn gốc), Then bản ghi vẫn được tạo và được gắn **completeness tier** (mức đầy đủ) để consumer phân biệt được.
- AC-1.6 · Given `genres` của cùng một code từ nhiều nguồn dùng nhãn khác nhau, Then bản ghi chứa **union** genre của **tất cả** nguồn, khử trùng lặp nhãn, **không** map sang canonical taxonomy nào cả. Ví dụ owner chốt: mộtjav cho `ABC-123` genres X/Y/Z, một site khác cho D/C/B → `ABC-123` có X/Y/Z/D/C/B. Danh sách genre union phải truy vết được về từng nguồn (provenance).

**AC-2 (REQ-2)**
- AC-2.1 · Given lệnh initial crawl trên tập nguồn POC, When chạy xong, Then DB có **1.000–5.000 title** chuẩn hoá và có bảng số title **theo từng nguồn**.
- AC-2.2 · Given crawl đang chạy, When **không ai can thiệp trong 72 giờ liên tục**, Then crawl vẫn tiến triển và **không title nào bị mất dữ liệu cũ** do ghi đè.
- AC-2.3 · Given một nguồn công bố title mới, When chu kỳ incremental chạy, Then title mới xuất hiện trong DB — kiểm chứng trên **mẫu ≥20 title mới**.
- AC-2.4 · Given một nguồn giới hạn tốc độ / chặn / trả CAPTCHA, Then crawler **tự giảm tốc hoặc tạm dừng riêng nguồn đó**, ghi nhận sự kiện, và **các nguồn khác vẫn chạy bình thường**.
- AC-2.5 · Given mỗi 1.000 title crawl xong, Then hệ thống ghi lại **số request proxy, giờ chạy, số lần thất bại** — tách theo nguồn.
- AC-2.6 · Given một nguồn trong tập ưu tiên chưa crawl được (ví dụ thiếu proxy Nhật), Then báo cáo nêu rõ nguồn đó **chưa được phủ** thay vì âm thầm bỏ qua.

**AC-3 (REQ-3)**
- AC-3.1 · Given code hợp lệ `SSIS-001` + key hợp lệ, When gọi lookup, Then nhận **200** và bản ghi đủ field lõi, **p95 ≤500 ms** ở quy mô POC.
- AC-3.2 · Given code sai định dạng (`ssis001`, `SSIS-0001`, `" ssis-001 "`, `SSIS-1`), When gọi, Then hệ thống chuẩn hoá và **vẫn trả đúng title** nếu title đó tồn tại.
- AC-3.3 · Given code không tồn tại, Then nhận **404** với thông báo phân biệt được "không có trong DB" — **không** phải 500, **không** phải bản ghi rỗng.
- AC-3.4 · Given key sai / thiếu / đã thu hồi, Then nhận **401** và **không** trả bất kỳ phần dữ liệu nào.

**AC-4 (REQ-4)**
- AC-4.1 · Given keyword là tên diễn viên (romaji **hoặc** kanji **hoặc** alias), When search, Then kết quả chứa các title có diễn viên đó.
- AC-4.2 · Given kết hợp filter `genre` + khoảng `release_date` + khoảng `runtime` + cờ censored, Then **100%** kết quả thoả **tất cả** filter.
- AC-4.3 · Given kết quả vượt giới hạn trang, When gọi trang 2, Then **không trùng và không sót** bản ghi so với trang 1, và response có **tổng số kết quả**.
- AC-4.4 · Given sort theo relevance / release_date / update_date / rating, Then thứ tự đúng tiêu chí và **ổn định giữa 2 lần gọi liên tiếp** với cùng tham số.
- AC-4.5 · Given query rỗng, chỉ ký tự đặc biệt, hoặc filter vô nghĩa (ngày kết thúc < ngày bắt đầu), Then nhận **400 có thông báo**, không 500.
- AC-4.6 · Given page vượt quá tổng số trang, Then nhận danh sách rỗng + tổng số, **không** lỗi.

**AC-5 (REQ-5)**
- AC-5.1 · Given tên một diễn viên đã có trong DB POC, When tra cứu, Then nhận hồ sơ gồm các tên (romaji/kanji/kana/alias — tuỳ nguồn có) + **số title liên kết**.
- AC-5.2 · Given 2 diễn viên **trùng tên**, Then trả về **2 hồ sơ riêng**, không gộp.
- AC-5.3 · Given tìm theo **alias**, Then vẫn ra đúng hồ sơ.
- AC-5.4 · Given diễn viên chỉ xuất hiện trong **1** title của POC, Then hồ sơ vẫn tồn tại (không ngưỡng tối thiểu).
- AC-5.5 · Given một title có nhiều diễn viên, Then **mọi** diễn viên đều liên kết ngược được tới title đó.

**AC-6 (REQ-6)**
- AC-6.1 · Given danh sách ≤100 code trong 1 call, Then nhận kết quả **cho từng code kèm trạng thái riêng** trong một response duy nhất.
- AC-6.2 · Given danh sách >100 code, Then nhận **400/413** nêu rõ giới hạn — **không** xử lý một phần.
- AC-6.3 · Given 30% code trong danh sách không tồn tại, Then response vẫn **200** và chỉ rõ code nào not-found.
- AC-6.4 · Given danh sách có code trùng nhau, Then kết quả không nhân đôi vô nghĩa (hoặc nêu rõ hành vi trong docs).

**AC-7 (REQ-7)**
- AC-7.1 · Given owner tạo key, Then key có **prefix nhận diện được** và owner liệt kê được các key đang hoạt động.
- AC-7.2 · Given owner thu hồi key, Then **mọi call sau đó nhận 401 ngay lập tức** (không có độ trễ hiệu lực đáng kể).
- AC-7.3 · Given mỗi call, Then log ghi **key + endpoint + thời điểm + mã kết quả**, và owner truy vấn được số call theo key.
- AC-7.4 · Given một key vượt ngưỡng lạm dụng owner đặt, Then key bị giới hạn tạm thời với **429** và owner thấy được lý do + thời điểm.

**AC-8 (REQ-8)**
- AC-8.1 · Given POC hoàn tất, When owner mở báo cáo, Then thấy đủ 4 nhóm số liệu: (a) tổng title theo nguồn, (b) % bản ghi có đủ **từng** field lõi, (c) tỉ lệ xung đột giá trị giữa các nguồn **tách theo field**, (d) request proxy + giờ crawl + lỗi **theo nguồn**.
- AC-8.2 · Given mẫu đối chiếu **200 code** do owner chọn, Then báo cáo nêu: bao nhiêu code tìm thấy, bao nhiêu field lõi **đúng** so với nguồn gốc, liệt kê các code sai/thiếu.
- AC-8.3 · Given các số đo trên, Then owner **tự ngoại suy được** chi phí và thời gian để đạt 100k và 400k title (báo cáo trình bày sẵn phép ngoại suy).
- AC-8.4 · Given báo cáo, Then owner xác định được **nguồn nào đáng đầu tư tiếp** và nguồn nào nên loại (dựa trên chi phí/lỗi/độ phủ).

**AC-9 (REQ-9)**
- AC-9.1 · Given endpoint health, When gọi, Then biết được hệ thống sống/chết + **lần crawl thành công gần nhất của từng nguồn**.
- AC-9.2 · Given một nguồn chết hoặc đổi domain, Then owner nhận **cảnh báo trong ≤24h** qua kênh owner chọn.
- AC-9.3 · Given một title crawl lỗi **N lần liên tiếp** (N do owner đặt), Then title được đánh dấu **"cần xem lại"** và đếm được trong báo cáo — không bị bỏ âm thầm.
- AC-9.4 · Given consumer gọi health **không cần key**, Then chỉ trả trạng thái hệ thống, **không** lộ dữ liệu title/diễn viên.

---

## 6. Non-functional requirements (business terms)

| Nhóm | Yêu cầu |
|---|---|
| **Performance** | Lookup p95 ≤**500 ms**; search p95 ≤**1,5 s**; bulk 100 code ≤**3 s** — ở quy mô POC (1–5k title, ≤10 consumer đồng thời). |
| **Scalability (nghiệp vụ)** | Hợp đồng dữ liệu với consumer (tên field + ý nghĩa) **không được đổi** khi mở rộng từ 5k → 400–600k title và 100k+ performer. Thêm nguồn mới **không** được làm thay đổi ý nghĩa field cũ. |
| **Reliability** | Crawl 24/7 không cần can thiệp (G-6: 72h ở POC; mốc GA 14 ngày đã elicit nhưng hoãn). **Một nguồn chết không được làm chết pipeline.** Dữ liệu đã crawl **không được mất** khi crawl lại. |
| **Data volume** | POC: 1.000–5.000 title. Sản phẩm (hoãn): 400–600k title + 100k+ performer. |
| **Cost visibility** | Đo được **chi phí proxy + giờ crawl trên mỗi 1.000 title**, tách theo nguồn. Đây là NFR **bắt buộc** vì owner chưa chốt ngân sách (OQ-4). |
| **Security** | Mọi endpoint dữ liệu yêu cầu API key; key thu hồi ngay được; log không chứa nội dung nhạy cảm ngoài mức cần thiết. Theo lập trường pháp lý đã chốt: **không cache/lưu bất kỳ file media hay ảnh nào — kể cả thumbnail**; chỉ lưu **URL tham chiếu** (cover, gallery, sample) tới site nguồn bên thứ ba (BR-4, D-22). |
| **Privacy** | Chỉ dùng thông tin nghề nghiệp **đã công khai** của diễn viên; không thu thập/suy luận thông tin cá nhân ngoài phạm vi đó. |
| **Ops / Maintainability** | Nguồn đổi domain phải xử lý được bằng **cấu hình**, không cần viết lại nghiệp vụ crawl. Thêm nguồn mới là việc **bổ sung**, không phải sửa dữ liệu cũ. |
| **Compliance** | Chỉ crawl **trang công khai**, **không** đăng nhập, **không** vượt paywall, **không** rehost media. Trước GA phải có takedown process + điều khoản 18+. |
| **Ràng buộc hạ tầng** | Chạy trên **1 server 24/7 owner đã có** (không thiết kế hosting — thuộc joo-sa). Proxy Nhật **chưa có**, owner có thể mua. |
| **Ràng buộc tài sản nội bộ** | **`crawlerx`** — dự án nội bộ của owner, hỗ trợ 20+ site, được owner chỉ định dùng cho crawling/fetching. **Mức độ sẵn có với site JAV chưa rõ → OQ-1 (blocking).** |

---

## 7. Business rules & constraints

| ID | Rule | Nguồn |
|---|---|---|
| **BR-1** | **Đơn vị định danh là "movie" (tác phẩm).** Mỗi movie có **đúng một bản ghi**; các biến thể **censored / uncensored / re-release / leak** được **gộp (merge) vào cùng bản ghi movie đó**, kèm provenance cho từng giá trị. Code vẫn là định danh tra cứu: FC2/amateur dùng ID gốc có tiền tố (`FC2-PPV-1234567`), các hãng uncensored dùng mã riêng của họ; nhiều code biến thể của cùng một movie trỏ về một bản ghi duy nhất. Việc gộp/nhận diện biến thể là hành vi nghiệp vụ phải đo được ở POC (G-5, REQ-8). | **Owner chốt tại requirements gate 2026-09-17** (OQ-5, D-23) — thay đề xuất "code là khoá chính + liên kết mềm" của BA |
| **BR-2** | Mỗi field có **một giá trị chính** kèm provenance (nguồn + thời điểm). Trong POC, thứ tự ưu tiên tạm thời = thứ tự ưu tiên nguồn owner đã chốt (FANZA/DMM trước). Rule chính thức (field-level authority) quyết **sau** khi có số liệu G-5. | Owner hoãn (REQ-D4) + BA đề xuất tạm |
| **BR-3** | Chỉ crawl trang **công khai**; không đăng nhập; không vượt paywall; không truy cập khu vực riêng tư/bảo vệ. | Owner chốt vòng 2 |
| **BR-4** | **Không rehost, không cache** file video/ảnh dưới bất kỳ hình thức nào (kể cả thumbnail) — chỉ lưu **URL tham chiếu** về nguồn. Không có ngoại lệ cache. | Owner chốt vòng 2 + xác nhận strict tại gate (D-22) |
| **BR-5** | Nội dung 18+: consumer phải xác nhận độ tuổi (điều khoản) — **áp dụng trước GA**, POC nội bộ chưa cần. | Suy ra từ lập trường pháp lý |
| **BR-6** | **Crawl lịch sự:** mỗi nguồn có trần tốc độ riêng; khi bị chặn thì giảm tốc/tạm dừng **riêng nguồn đó**, không tăng tốc để "cho kịp". | NFR reliability + chi phí proxy |
| **BR-7** | Mọi bản ghi đều có **mốc thời gian crawl** (`crawled_at`) để consumer biết dữ liệu bao nhiêu tuổi. | BA đề xuất (phục vụ G-5, OQ-8) |
| **BR-8** | **POC data không được bán lại / phân phối.** Key tĩnh cấp cho mục đích đánh giá, owner thu hồi bất kỳ lúc nào. | Suy ra từ REQ-D3 (reseller policy hoãn) |
| **BR-9** | Code input được **chuẩn hoá** trước khi tra (hoa/thường, dấu cách, độ dài số, prefix) — nhưng chuẩn hoá **không** được gộp nhầm 2 code khác nhau. | AC-3.2 |
| **BR-10** | Title bị gỡ khỏi nguồn (**delisted**) thì **không tự xoá**; đánh dấu `delisted_at`. | BA đề xuất — OQ-6 |
| **BR-11** | **Không bịa dữ liệu.** Field không có nguồn nào cung cấp → `null`, không suy luận, không điền mặc định. | AC-1.3 |
| **C-1** | Ràng buộc: 1 server 24/7 có sẵn; không thiết kế hosting. | Owner |
| **C-2** | Ràng buộc: proxy Nhật chưa có nhưng mua được; chi phí là biến số kinh doanh. | Owner |
| **C-3** | Ràng buộc: dùng `crawlerx` (tài sản nội bộ) cho crawling/fetching. | Owner |
| **C-4** | Ràng buộc: một số nguồn đổi domain thường xuyên (missav, javdb) → phải có cơ chế vận hành, không phải sự cố bất ngờ. | Requirement text |
| **C-5** | Ràng buộc pháp lý: nguồn JAV phần lớn aggregator/pirate-adjacent → **vùng xám**, owner chấp nhận **trong ranh giới BR-3/BR-4**. | Owner chốt vòng 2 |

---

## 8. Edge cases & failure modes

**Định danh & dedup**
1. Code không tồn tại → 404 tường minh (AC-3.3).
2. Code sai định dạng / thiếu số 0 / dính khoảng trắng / chữ thường (AC-3.2).
3. **Prefix collision:** `FC2-PPV-1234567` vs `FC2-1234567`; `SSIS-001` vs `1pondo`/`caribbean`/`heyzo` mã số trùng hình dạng.
4. Một tác phẩm có **nhiều code** (re-release, bản 4K, box set, bản uncensored leak mã khác) → theo BR-1 (D-23) tất cả biến thể phải **gộp về một bản ghi movie**; rủi ro nghiệp vụ là **gộp nhầm / tách nhầm** biến thể — POC phải đo được qua provenance + báo cáo REQ-8.
5. Hai nguồn dùng **hai code khác nhau cho cùng một title** → phải hội tụ về cùng một bản ghi movie (BR-1); bản ghi đôi là lỗi cần bắt được trong báo cáo.

**Diễn viên**
6. Trùng tên giữa 2 diễn viên khác nhau (AC-5.2).
7. Diễn viên đổi alias / có nhiều cách viết romaji / chỉ có kanji không có romaji.
8. Tên diễn viên bị **máy dịch** sai ở nguồn tiếng Anh.
9. Title ghi "unknown"/ẩn tên diễn viên.

**Nguồn & crawl**
10. Nguồn đổi domain giữa chừng (missav/javdb) → link cũ chết hàng loạt (WF-3).
11. Nguồn trả **soft-404** (HTTP 200 nhưng nội dung "không tìm thấy") → nguy cơ ghi bản ghi rỗng đè dữ liệu tốt.
12. CAPTCHA / block theo IP → proxy bị "đốt" mà không có dữ liệu.
13. **Proxy Nhật hết dung lượng giữa crawl** → crawl dừng nửa vời, dữ liệu lệch.
14. Nguồn rate-limit → crawl không kịp cửa sổ, freshness vỡ.
15. Nguồn đổi cấu trúc HTML → field trích ra sai âm thầm (không lỗi, chỉ sai) — **nguy hiểm nhất** vì không có exception.
16. Cùng một title crawl 2 lần cho 2 kết quả khác nhau (nguồn A/B test hoặc dữ liệu động).

**Chất lượng dữ liệu**
17. `release_date` ở **tương lai** (title đã công bố, chưa phát hành) → filter khoảng ngày phải xử lý được.
18. `runtime` = 0 / thiếu / ghi "N/A" ở nguồn.
19. Genre taxonomy khác nhau giữa các nguồn, một nguồn có genre mà nguồn khác không có → xử lý bằng **union per code** (AC-1.6, D-24): giữ nhãn genre nguyên bản từng nguồn, khử trùng lặp; **không** map canonical. Hệ quả chấp nhận: cùng khái niệm có thể xuất hiện dưới 2 nhãn khác nhau.
20. `cover_url` chết hoặc **chặn hotlink** → media-server scraper không hiển thị được ảnh. **Owner đã chốt (OQ-2, D-22):** chỉ lưu URL tham chiếu, không cache media kể cả thumbnail; **chấp nhận ảnh có thể không hotlink-render được — đây là POC.**
21. **HLS stream URL hết hạn** (thường có TTL) → trả về URL đã chết. Cần `crawled_at` + kỳ vọng freshness riêng cho loại field này (OQ-8).
22. Magnet link chết / trỏ tới nội dung khác.
23. Title chỉ có ở javlibrary (không có ở DMM) → thiếu cover, thiếu runtime → completeness tier thấp (AC-1.5).
24. Community score thay đổi liên tục → giá trị nào là "đúng"?

**API & vận hành**
25. Consumer gửi bulk >100 code (AC-6.2).
26. Key bị lộ / commit lên repo công khai → cần thu hồi ngay (AC-7.2).
27. Consumer spam search với query rất rộng (1 ký tự) → tải nặng.
28. Phân trang + dữ liệu đang crawl đồng thời → kết quả dịch chuyển giữa 2 trang.
29. Server 24/7 restart / mất điện giữa crawl → phải resume, không corrupt.
30. Consumer diễn giải sai `null` thành "không có dữ liệu" vs "chưa crawl".

**Pháp lý / vận hành kinh doanh (hoãn nhưng đã nhận diện)**
31. Nhận yêu cầu gỡ nội dung (takedown) mà chưa có quy trình → REQ-D7.
32. Nguồn gửi cảnh báo pháp lý / cease-and-desist → chưa có lập trường phản hồi.
33. Reseller dùng dữ liệu để dựng đối thủ cạnh tranh → REQ-D3.

---

## 9. Decision log

| # | Câu hỏi | Các lựa chọn | Quyết định | Lý do chọn / loại |
|---|---|---|---|---|
| D-1 | Phân khúc khách hàng ưu tiên | 6 phân khúc (scraper / downloader / indie dev / studio B2B / AI dataset / reseller) | **(1) media-server scrapers, (2) data resellers** | Chọn: scraper = lượng call lớn, đều, ít hỗ trợ, đúng audience đã được đối thủ chứng minh. Reseller = doanh thu lớn. **Loại** (tạm): studio B2B (sales phức tạp, cần SLA/hóa đơn — không hợp crypto-only v1); AI dataset (cần bulk export, không phải realtime). *Hệ quả chưa giải quyết:* reseller cần REQ-D3. |
| D-2 | Mô hình doanh thu | per-call credits / subscription / **hybrid** / data dump | **Hybrid subscription + credit overage** | Chọn: subscription cho doanh thu dự đoán + credit cho nhóm scraper dao động. Loại per-call thuần (không có cam kết từ khách, khó dự đoán); loại data dump (mất lợi thế "cập nhật liên tục", dễ rò rỉ). **Trạng thái: HOÃN (REQ-D1).** |
| D-3 | Kênh thanh toán | crypto-only / crypto+MoR / crypto+high-risk PSP / +chuyển khoản VN | **Crypto-only v1 (USDT/USDC/BTC)** | Chọn: không chargeback, không KYC, không bị AUP chặn vì adult content, ra mắt nhanh. Loại MoR (Paddle/LS cấm adult → rủi ro khoá tài khoản + giữ tiền); loại high-risk PSP (phí 5–12%, cần pháp nhân, duyệt lâu). **HOÃN (REQ-D1).** |
| D-4 | Năng lực API cho GA v1 | lookup / search / idol / random / bulk / webhook | **lookup + search + idol + bulk + webhook**; loại random | Chọn theo giá trị cho 2 phân khúc D-1: scraper cần lookup+bulk; reseller/aggregator cần search+idol+webhook. Loại random (tiện ích demo, không lõi). **POC chỉ làm lookup + search + idol(subset) + bulk**; webhook → REQ-D5. |
| D-5 | Dữ liệu "nóng" pháp lý | metadata+magnet (không stream) / **full parity** / metadata-only / không bao giờ bán link | **Full parity: metadata + magnet + HLS stream URL** | Owner chọn giá trị thương mại cao nhất, parity với javinfo.dev. **BA đã khuyến nghị phương án trung gian (bỏ HLS) và bị loại** — lý do loại của owner: không nêu rõ, suy ra là muốn parity đầy đủ. *Hệ quả:* rủi ro pháp lý cao nhất + phụ thuộc nguồn dễ chết domain + TTL của stream URL (edge case 21, OQ-8). |
| D-6 | Lập trường rủi ro pháp lý | vùng xám có ranh giới / chấp nhận tối đa kể cả rehost / chỉ nguồn license / kết hợp | **Vùng xám có ranh giới rõ** | Chọn: chỉ trang công khai, không đăng nhập, không vượt paywall, **không rehost media/ảnh**, có takedown process → BR-3, BR-4, REQ-D7. Loại "chỉ nguồn license" (DB nhỏ, mất khác biệt "largest"); loại "rehost" (rủi ro bản quyền tăng mạnh). Mâu thuẫn phát sinh BR-4 vs nhu cầu cover hotlink (OQ-2) → **đã resolve tại gate 2026-09-17 — xem D-22.** |
| D-7 | Thứ tự ưu tiên nguồn | 8 nhóm nguồn | **Chọn TẤT CẢ**, thứ tự: FANZA/DMM → javdb → javdatabase → javlibrary → missav+sextb → amateur/FC2 → magneto → DLsite/Doujin; **bắt đầu từ site `crawlerx` đã hỗ trợ** | Owner muốn độ phủ tối đa ("as much as possible") và tận dụng tài sản nội bộ. *Hệ quả:* phạm vi crawl POC **rộng hơn khuyến nghị của BA** (BA đề xuất chỉ 2 nguồn) → rủi ro POC kéo dài; giảm nhẹ bằng cách giới hạn **số title** (1–5k) thay vì giới hạn số nguồn. |
| D-8 | Mốc "largest DB" đo được | 250k/80k ≤24h (2 tầng) / 400k/100k ≤6h / 150k ≤1h / 500k không SLA | **CHƯA CHỐT** — owner: "i dont think need care this time. i need POC movie first" | BA đã giải thích: DMM commercial ~250–350k; thêm FC2/mgstage/doujin → 500–700k. **Thay bằng mục tiêu POC G-2/G-3/G-4** (1–5k title + chất lượng + chi phí đo được). → **OQ-3, REQ-D9.** |
| D-9 | Mốc thời gian MVP/GA | 6–8 tuần MVP + GA 3 tháng / GA khi 400k / bán sau 4 tuần / BA đề xuất | **Không theo ngày — theo độ ổn định** | Owner: "dont care this one until app stable". BA chuyển mốc thời gian thành **điều kiện đo được**: POC = G-1..G-7; GA = bộ điều kiện owner đã chọn "all of the above" (crawl ổn định 14 ngày, DB đạt mốc, API đạt NFR dưới tải thật, billing end-to-end có đối soát, 5–10 khách beta, takedown+ToS 18+ sẵn sàng) nhưng **"consider later"**. |
| D-10 | Ngân sách proxy/hạ tầng | <$100 / $100–300 / $300–1000 / >$1000 | **CHƯA CHỐT** — "dont care yet" | Không có trần chi phí → BA chuyển thành **NFR Cost visibility + G-4**: POC phải **đo** chi phí/1.000 title để owner có cơ sở đặt trần. → **OQ-4.** |
| D-11 | Free tier | trial credits một lần / freemium vĩnh viễn / không free / free non-commercial | **Trial credits một lần + rate limit thấp** | Chọn: ít bị lạm dụng, đủ để dev thử tích hợp, giống đối thủ. Loại freemium (tốn tài nguyên, dễ tạo nhiều tài khoản); loại "không free" (khó thuyết phục dev thử). **HOÃN (REQ-D2).** |
| D-12 | `crawlerx` là gì | tool crawl cụ thể / đối thủ thương mại / best practice chung / bỏ qua | **Dự án nội bộ của owner, hỗ trợ 20+ site, dùng để crawl/fetch** | Ghi thành **ràng buộc C-3**, không thiết kế theo. Mức độ sẵn có với site JAV: owner chọn **"Chưa rõ — cần kiểm tra repo"** → **OQ-1 (blocking cho REQ-2).** |
| D-13 | Chính sách reseller | cấm mặc định + gói reseller / cho tự do / cấm tuyệt đối / cho có điều kiện | **CHƯA CHỐT** — "dont care POC first" | BA khuyến nghị "cấm mặc định + gói Reseller license riêng" (bảo vệ doanh thu, giảm rủi ro bị cạnh tranh ngược). Ghi tạm **BR-8** cho POC (không bán lại). → **REQ-D3.** |
| D-14 | Rule xung đột dữ liệu | field-level authority / mới nhất thắng / đa số / giữ tất cả / chính+provenance | **CHƯA CHỐT** — "dont care POC first" | BA khuyến nghị field-level authority + provenance. **Chuyển thành mục tiêu đo lường G-5**: POC sẽ đo tỉ lệ xung đột theo field để owner quyết **bằng số liệu** thay vì phỏng đoán. Áp dụng tạm BR-2 (ưu tiên theo thứ tự nguồn D-7). → **REQ-D4.** |
| D-15 | Định danh "một title" (vòng BA) | code là khoá / gộp thành movie+biến thể / code + liên kết mềm / fuzzy match | ~~BA đề xuất: code là khoá chính + liên kết mềm giữa biến thể~~ → **bị thay bởi D-23** | Lý do đề xuất tại thời điểm BA: giữ đếm đơn giản, không mất thông tin, tránh fuzzy match. **Owner không duyệt đề xuất này** — quyết định cuối tại gate: xem D-23. |
| D-16 | Điều kiện GA | 6 điều kiện (multiple) | **"All of above but consider later"** | Owner chấp nhận cả 6 điều kiện làm cổng GA nhưng chưa kích hoạt. BA giữ nguyên trong REQ-D + WF-8/9 để không mất. |
| D-17 | **POC nghĩa là gì** | end-to-end / spike crawl / spike quy mô / API parity demo | **End-to-end: crawl → DB chuẩn hoá → API lookup/search** | Chọn: đây là chuỗi rủi ro cao nhất và là thứ owner cần thấy trước khi quyết định đầu tư tiếp. Loại các spike đơn lẻ (không cho bức tranh đủ để go/no-go). |
| D-18 | Quy mô POC | 1–5k / 10–50k / full 100k+ / không mốc | **1.000–5.000 title** | Chọn: đủ để kiểm tra chuẩn hoá + dedup + tốc độ API, chạy nhanh, ít tốn proxy. Loại 10–50k và full (đó là việc của giai đoạn sản phẩm). |
| D-19 | Nguồn trong POC | 2 nguồn / 1 nguồn / 3 nguồn / 4–5 nguồn / site crawlerx có sẵn | **Càng nhiều càng tốt, bắt đầu từ `crawlerx`** | Owner chọn rộng hơn khuyến nghị của BA (2 nguồn). BA **chấp nhận** nhưng kiểm soát rủi ro bằng cách neo vào **số title (D-18)** và yêu cầu **AC-2.6** (báo cáo nêu rõ nguồn nào chưa phủ được). |
| D-20 | Billing trong POC | không billing / đếm usage không thu tiền / billing crypto tối thiểu | **Không billing — chỉ API key tĩnh** | Chọn: giữ POC ngắn. **BA bổ sung AC-7.3 (usage log)** để vẫn có số liệu định giá sau này mà không tốn công làm billing. Loại billing crypto (kéo dài POC đáng kể). |
| D-21 | Người nghiệm thu POC | owner tự đánh giá / khách beta / bằng chứng chi phí nội bộ | **Owner tự đánh giá → go/no-go** | Chọn: POC là công cụ ra quyết định đầu tư, không phải sản phẩm cho khách. → AC-8 được thiết kế **hướng tới báo cáo cho owner**, không phải dashboard cho consumer. |
| **D-22** | **Media/covers (OQ-2):** chỉ URL tham chiếu hay cache thumbnail? *(owner chốt tại requirements gate 2026-09-17 — RESOLVED)* | chỉ lưu URL (đúng BR-4) / cache thumbnail làm ngoại lệ BR-4 (BA đề xuất) | **KHÔNG cache media — chỉ lưu URL tham chiếu (cover, gallery, sample) tới site nguồn.** BR-4 giữ nguyên strict, không có ngoại lệ. | **Đề xuất cache thumbnail của BA bị owner loại**: owner quyết giữ nguyên ranh giới pháp lý đã chốt ở D-6, không nới BR-4. Chấp nhận hệ quả: ảnh có thể không hotlink-render được ở consumer; POC không cần ảnh hiển thị hoàn hảo. Cập nhật: NFR Security, BR-4, AC-1.4, edge case 20, REQ-1. |
| **D-23** | **Định danh title (OQ-5):** code-as-key + soft link hay gộp movie+biến thể? *(owner chốt tại requirements gate 2026-09-17 — RESOLVED)* | code là khoá chính + liên kết mềm (đề xuất BA ở D-15) / **gộp movie + variants** / fuzzy match | **MỘT bản ghi per "movie"; biến thể censored/uncensored/re-release/leak gộp vào đúng bản ghi movie đó.** | Owner chọn trực tiếp phương án "merge movie + variants", **thay** đề xuất code-as-primary-key + soft links của BA. Hệ quả: số title đếm theo movie (không phải theo code); logic dedup/gộp phức tạp hơn → POC phải đo rủi ro gộp nhầm/tách nhầm (BR-1, edge cases 4–5). Cập nhật: BR-1, AC-1.1, REQ-1/REQ-3, WF-1, edge cases. |
| **D-24** | **Genres (OQ-7):** canonical taxonomy (FANZA-based) hay union? *(owner chốt tại requirements gate 2026-09-17 — RESOLVED)* | map về canonical taxonomy gốc FANZA (BA đề xuất) / **union đơn giản per code** | **Genres = union của mọi nguồn trên cùng một code.** Ví dụ owner: mộtjav cho `ABC-123` X/Y/Z, site khác cho D/C/B → `ABC-123` có X/Y/Z/D/C/B. **Không** canonical mapping. | Owner loại đề xuất FANZA-based taxonomy của BA — đơn giản hoá REQ-1, bỏ dependency OQ-7 khỏi AC-1.6. Hệ quả chấp nhận: cùng khái niệm có thể mang 2 nhãn khác nhau; độ tươi genre theo từng nguồn vẫn giữ nguyên. Cập nhật: REQ-1, AC-1.6, edge case 19, REQ-4 (filter trên union). |

---

## 10. Open questions / decisions needed (escalate to root)

| ID | Câu hỏi | Mức độ | Trạng thái / Khuyến nghị |
|---|---|---|---|
| **OQ-1** | **`crawlerx` sẵn có tới đâu với các nguồn JAV?** Đã có adapter cho FANZA/javdb/missav chưa? Có xử lý proxy + anti-bot chưa? Là thư viện, service, hay dự án độc lập xuất dữ liệu? | 🔴 **BLOCKING** cho REQ-2 — owner không rõ, cần root/`joo-sa` kiểm tra repo (BA không có quyền) | Root cho `joo-sa`/auditor khảo sát `crawlerx` **trước** khi lên kế hoạch REQ-2. Nếu chưa có adapter JAV → REQ-2 tăng size và cần spike riêng. |
| **OQ-2** | ~~Cover art: chỉ lưu URL tham chiếu hay cache bản sao?~~ | ✅ **RESOLVED — owner chốt 2026-09-17 (D-22)** | **KHÔNG cache media.** Chỉ lưu URL tham chiếu (cover, gallery, sample) về site nguồn. BR-4 strict, không ngoại lệ thumbnail. Chấp nhận ảnh có thể không hotlink-render (POC). |
| **OQ-3** | Mốc "largest DB" chính thức + SLA freshness cho GA? | 🟡 Cần trước khi marketing/định giá, **không** cần cho POC | Mốc 2 tầng: GA ≥250k title / ≥80k performer / mới ≤24h → sau 3–6 tháng 400k / 100k / ≤6h. **Bắt buộc gộp amateur/FC2** mới tuyên bố "lớn nhất" được. Lưu ý D-23: đơn vị đếm là movie. |
| **OQ-4** | Trần ngân sách tháng cho proxy Nhật? | 🟡 Cần trước khi mở rộng crawl quá 5k title | Chưa có trần → POC phải ra số đo G-4 trước. BA đề xuất root hỏi lại owner **sau** khi có báo cáo POC, kèm 3 kịch bản chi phí (thấp/vừa/cao) suy ra từ số đo thật. |
| **OQ-5** | ~~Duyệt BR-1 (code là khoá chính + liên kết mềm)?~~ | ✅ **RESOLVED — owner chốt 2026-09-17 (D-23)** | Owner chọn **merge movie + variants** (một bản ghi per movie), **không** theo đề xuất code-as-primary-key + soft links của BA. BR-1 đã viết lại theo quyết định này. |
| **OQ-6** | Title bị nguồn gỡ (**delisted**) thì giữ hay xoá? | 🟡 | Giữ + đánh dấu `delisted_at` (BR-10). Xoá sẽ làm mất độ phủ và phá vỡ consumer đang tham chiếu code đó. |
| **OQ-7** | ~~Danh sách genre chuẩn (canonical taxonomy) ai định nghĩa, dựa trên nguồn nào?~~ | ✅ **RESOLVED — owner chốt 2026-09-17 (D-24)** | **Union đơn giản per code** qua tất cả nguồn (ví dụ X/Y/Z ∪ D/C/B → X/Y/Z/D/C/B). Không canonical taxonomy FANZA-based. AC-1.6 và REQ-4 đã viết lại theo hành vi union. |
| **OQ-8** | **HLS stream URL có TTL** — kỳ vọng freshness của riêng field này là gì? (Consumer nhận URL đã chết thì coi như lỗi sản phẩm.) | 🟡 | Đề xuất: field `hls_stream_urls` có `crawled_at` riêng + cảnh báo trong docs rằng URL có thể hết hạn; re-crawl field này với chu kỳ **ngắn hơn** metadata (giờ, không phải ngày). |
| **OQ-9** | Pháp nhân / jurisdiction để vận hành và bán API (kể cả khi crypto-only)? | 🟠 Hoãn tới trước GA | Chưa elicit được (owner "dont care POC first"). Ghi nhận: crypto-only **không** loại bỏ rủi ro jurisdiction, chỉ loại bỏ KYC của PSP. |
| **OQ-10** | Ngôn ngữ của `BUSINESS.md`? | 🟢 | Tài liệu này tiếng Việt (đúng rule chat). **Nếu đổ vào JIRA → bắt buộc tiếng Anh** (rule English-only). Đề xuất `joo-doc-writer` giữ bản tiếng Việt cho owner duyệt, và tạo bản tiếng Anh khi vào JIRA. |
| **OQ-11** | Kênh cảnh báo owner chọn cho AC-9.2 là gì (email / Telegram / log)? | 🟢 | Chưa hỏi — cần chốt trước khi implement REQ-9. |
| **OQ-12** | Ngưỡng lạm dụng (AC-7.4) và ngưỡng retry (AC-9.3) đặt bao nhiêu? | 🟢 | Đề xuất để owner cấu hình được, giá trị mặc định chốt ở vòng planning. |
| **OQ-13** | Tên sản phẩm? | 🟢 | Owner nói TBD — dùng codename `javmeta`. |

---

## 11. Definition of Ready (INVEST) + coarse size

> Sizing ở đây là **coarse estimate của BA**, không phải cam kết effort. Breakdown chi tiết thuộc `joo-team-lead` / planning. Trạng thái OQ-2/OQ-5/OQ-7 đã cập nhật theo requirements gate 2026-09-17.

| REQ | I | N | V | E | S | T | AC | NFR | Deps | Size | Ready? |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **REQ-1** Chuẩn hoá đa nguồn | ⚠️ phụ thuộc REQ-2 có dữ liệu | ✅ | ✅ | ⚠️ chưa rõ `crawlerx` | ❌ quá lớn | ✅ | ✅ 6 AC | ✅ | OQ-1 | **L** | ⚠️ **GẦN READY** — OQ-2/OQ-5/OQ-7 đã chốt; còn chờ tách story + trả lời OQ-1 |
| **REQ-2** Crawl nền 24/7 | ❌ | ✅ | ✅ | ❌ OQ-1 blocking | ❌ XL | ✅ | ✅ 6 AC | ✅ | **OQ-1**, C-2, C-3 | **XL** | ❌ **CHƯA** — blocking OQ-1 + phải tách |
| **REQ-3** Lookup theo code | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ 4 AC | ✅ | REQ-1 | **S** | ✅ **READY** |
| **REQ-4** Search/query | ✅ | ✅ | ✅ | ✅ | ⚠️ nhiều filter | ✅ | ✅ 6 AC | ✅ | REQ-1 | **M** | ✅ **READY** — genre filter = union per code (OQ-7 đã chốt) |
| **REQ-5** Performer catalog (subset) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ 5 AC | ✅ | REQ-1 | **M** | ✅ **READY** |
| **REQ-6** Bulk lookup | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ 4 AC | ✅ | REQ-3 | **S** | ✅ **READY** |
| **REQ-7** API key tĩnh + usage log | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ 4 AC | ✅ | — | **S** | ✅ **READY** (chờ OQ-12 cho giá trị mặc định) |
| **REQ-8** Báo cáo chất lượng & chi phí | ✅ | ✅ | ✅ **cao nhất** | ✅ | ✅ | ✅ | ✅ 4 AC | ✅ | REQ-1, REQ-2 | **M** | ✅ **READY** |
| **REQ-9** Health & giám sát crawl | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ 4 AC | ✅ | REQ-2, OQ-11 | **S** | ⚠️ **GẦN READY** — chờ OQ-11 |

### Đề xuất tách story (theo skill: split khi không đạt "Small")

**REQ-1 → 3 slice** (mỗi slice độc lập có giá trị):
- **REQ-1a** · Canonical field mapping + gộp biến thể movie cho **1 nguồn duy nhất** (FANZA/DMM hoặc nguồn `crawlerx` sẵn có; genre giữ nguyên nhãn, không taxonomy map — D-24) — **M**
- **REQ-1b** · Hợp nhất **nguồn thứ 2 trở đi** vào bản ghi movie đã có (merge/identity theo BR-1 + provenance theo BR-2) — **M**
- **REQ-1c** · Provider extras (magnet / HLS / gallery / score — all reference URLs/values, no media cache) + completeness tier — **S**

**REQ-2 → 3 slice:**
- **REQ-2a** · Initial full crawl trên tập nguồn POC đạt 1–5k title (AC-2.1, 2.5, 2.6) — **L**
- **REQ-2b** · Incremental re-crawl theo lịch (AC-2.3) — **M**
- **REQ-2c** · Source resilience: rate-limit backoff, soft-404/CAPTCHA detection, domain-change isolation, resume sau restart (AC-2.2, 2.4) — **M**

**Spike đề xuất (kết quả = quyết định, không phải code ship được):**
- **SPIKE-1** · *Khảo sát `crawlerx`* → trả lời OQ-1: những site JAV nào đã có adapter, proxy/anti-bot xử lý tới đâu, tích hợp kiểu gì. **Blocking cho REQ-2a.** Size **S**. *(BA đề xuất root giao `joo-sa` + auditor qua root, vì BA không có quyền đọc repo.)*
- **SPIKE-2** · *Đo khả năng crawl FANZA/DMM qua proxy Nhật* → trả lời C-2 + cấp số liệu sớm cho G-4. Size **S**. Có thể gộp vào SPIKE-1 nếu `crawlerx` đã có sẵn.

### Thứ tự đề xuất (chỉ ở mức business, không phải technical plan)
`SPIKE-1` → `REQ-7` (key + log, độc lập) → `REQ-1a` + `REQ-2a` → `REQ-3` → `REQ-8` → `REQ-4` → `REQ-1b`/`REQ-1c`/`REQ-2b`/`REQ-2c` → `REQ-5` → `REQ-6` → `REQ-9` → **báo cáo go/no-go (WF-7)**.
*Lý do REQ-8 sớm:* nó là thứ owner dùng để nghiệm thu (D-21); làm sớm để đo được chi phí ngay từ 1–2 nguồn đầu, không phải đợi tới cuối.

---

## 12. Log câu hỏi đã đặt cho owner + câu trả lời

**Vòng 1 — thị trường, doanh thu, phạm vi v1**
1. *Phân khúc khách hàng ưu tiên?* → **Media-server scrapers (Jellyfin/Plex/Emby/Kodi)** [ưu tiên 1] + **Data resellers / affiliate marketers** [ưu tiên 2].
2. *Mô hình tính tiền chính?* → **Hybrid: subscription + credit trả theo call khi vượt quota.**
3. *Kênh thanh toán v1?* → **Crypto-only (USDT/USDC, BTC).**
4. *Năng lực API bắt buộc cho GA v1?* → **Lookup theo code + Search/query + Performer catalog + Bulk lookup + Webhook/feed mới phát hành.** (Không chọn Random.)
5. *Magnet/HLS stream có trong v1?* → **Đầy đủ như đối thủ: metadata + magnet + HLS stream URL.**

**Vòng 2 — dữ liệu, pháp lý, ngân sách, mốc ra mắt**
6. *Lập trường rủi ro pháp lý?* → **Chấp nhận vùng xám, nhưng có ranh giới rõ** (trang công khai, không đăng nhập, không vượt paywall, không rehost media/ảnh, có takedown process).
7. *Mốc "largest DB" đo được cho GA?* → **"explain"** → BA giải thích lại ở vòng 3.
8. *Thứ tự ưu tiên nguồn crawl v1?* → **Chọn tất cả:** FANZA/DMM → javdb → javdatabase → javlibrary → missav+sextb → Amateur/FC2 (mgstage, FC2) → magneto → DLsite/FANZA Doujin, kèm ghi chú **"learn from crawlerx"**.
9. *Mốc MVP/GA?* → **"dont care this one until app stable"**.
10. *Ngân sách tháng cho proxy + hạ tầng?* → **"dont care yet"**.
11. *Chính sách free tier?* → **Trial credits một lần.**

**Vòng 3 — làm rõ mốc DB, crawlerx, business rule**
12. *Mốc DB sau khi BA giải thích (2 tầng 250k→400k / một tầng 400k / freshness-first / completeness-first)?* → **"i dont think need care this time. i need POC movie first"**.
13. *`crawlerx` là gì?* → **"crawlerx is one of our project. its support more than 20 or more sites. use it for crawling / fetching data"**.
14. *Chính sách bán lại dữ liệu (reseller)?* → **"dont care POC first"**.
15. *Rule khi nhiều nguồn xung đột giá trị?* → **"Dont care POC first"**.
16. *Cái gì được tính là MỘT title?* → **"suggest me"** → BA đề xuất code-as-key + soft link (D-15), chờ owner duyệt (OQ-5). → **Owner chốt khác tại gate — xem vòng 5 / D-23.**
17. *Điều kiện bắt buộc trước GA?* → **"All of above but consider later. I need POC movie first"** (chấp nhận cả 6 điều kiện, chưa kích hoạt).

**Vòng 4 — phạm vi POC**
18. *"POC movie" nghĩa là gì?* → **End-to-end: crawl → DB chuẩn hoá → API lookup/search.**
19. *POC crawl nguồn nào?* → **"as much as possible. maybe start with crawlerx first because we already have done w/ it"**.
20. *POC cần bao nhiêu title?* → **~1.000–5.000 title.**
21. *Mức độ sẵn có của `crawlerx` với site JAV?* → **"Chưa rõ — cần kiểm tra repo (open question)"** → **OQ-1 (blocking).**
22. *POC có cần billing/quota?* → **Không billing — chỉ API key tĩnh.**
23. *Ai nghiệm thu POC, phục vụ quyết định gì?* → **Owner tự đánh giá → go/no-go.**

**Vòng 5 — requirements gate (2026-09-17): owner duyệt toàn bộ kế hoạch + chốt 3 OQ**
24. *OQ-2 media/covers?* → **Không cache media; chỉ lưu URL tham chiếu về site nguồn; BR-4 strict.** (D-22)
25. *OQ-5 định danh title?* → **Merge movie + variants** — một bản ghi per "movie", biến thể censored/uncensored/re-release/leak gộp vào bản ghi đó. (D-23, thay đề xuất BA ở D-15)
26. *OQ-7 genres?* → **Union đơn giản per code** qua mọi nguồn (X/Y/Z ∪ D/C/B → X/Y/Z/D/C/B); không canonical taxonomy mapping. (D-24)

---

## 13. Kết luận BA & đề xuất gửi root

*(Ghi chú gate: ba việc mục 13.2/13.3 dưới đây đã được owner chốt tại requirements gate 2026-09-17 — D-22, D-23, D-24. Việc còn mở: OQ-1.)*

**Đánh giá tổng thể:** yêu cầu **đủ rõ để bắt đầu POC**. Sau gate: 6/9 REQ Ready (REQ-3, 4, 5, 6, 7, 8 — REQ-4 hết chờ OQ-7); REQ-9 gần Ready chờ OQ-11; REQ-1 gần Ready (OQ-2/OQ-5/OQ-7 đã chốt, còn chờ tách story); REQ-2 vẫn **blocked bởi OQ-1**.

**Ba việc root cần làm trước khi chuyển sang planning (trạng thái tại gate):**
1. **Giải OQ-1 (blocking, vẫn mở):** cho `joo-sa` (+ auditor qua root) khảo sát repo `crawlerx` — BA không có quyền đọc repo. Không có câu trả lời này thì REQ-2 không estimable.
2. ~~Xin owner quyết OQ-2~~ → **ĐÃ CHỐT (D-22):** không cache media, chỉ URL tham chiếu.
3. ~~Duyệt BR-1 (OQ-5)~~ → **ĐÃ CHỐT (D-23):** merge movie+variants. Riêng đề xuất tách story + SPIKE-1/SPIKE-2 ở mục 11 chuyển sang planning để root/`joo-team-lead` duyệt.

**Rủi ro BA muốn root lưu ý (không tự quyết được):**
- **R-1 · Phạm vi POC rộng hơn khuyến nghị.** Owner muốn "as much as possible" nguồn ngay trong POC, trong khi `crawlerx` readiness chưa rõ và proxy Nhật chưa có. Neo kiểm soát duy nhất hiện nay là **số title 1–5k** (G-2) và **AC-2.6** (báo cáo nguồn chưa phủ). Nếu không kỷ luật, POC sẽ không bao giờ "xong".
- **R-2 · Mâu thuẫn D-5 vs D-6.** Owner chọn bán HLS stream URL (rủi ro cao nhất) đồng thời chọn lập trường "không rehost, vùng xám có ranh giới". Hai quyết định này **căng nhau**: stream URL là thứ trực tiếp nhất dẫn tới nội dung. BA đã khuyến nghị bỏ HLS ở v1 và bị loại — ghi nhận quyết định của owner; gate 2026-09-17 owner duyệt mà không đổi D-5 → lập trường full parity đứng vững, R-2 ghi nhận là rủi ro được chấp nhận.
- **R-3 · Toàn bộ mô hình kinh doanh đang hoãn.** Đã elicit được 6 quyết định thương mại (D-2, D-3, D-4, D-6, D-11, D-13) nhưng owner hoãn áp dụng. **Rủi ro:** POC có thể được thiết kế theo hướng không phục vụ được mô hình billing/reseller sau này (ví dụ usage log không đủ chi tiết để định giá). BA đã giảm nhẹ bằng **AC-7.3** và **REQ-8**, nhưng root nên nhắc owner rằng **OQ-3/OQ-4/OQ-9 phải chốt trước GA**, không phải sau.

**Cổng phê duyệt:** Requirements gate **ĐÓNG — Approved 2026-09-17** bởi owner. Bước tiếp theo: OQ-1 (SPIKE-1 qua `joo-sa`/auditor) → planning (`joo-team-lead`).

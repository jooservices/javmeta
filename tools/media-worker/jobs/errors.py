"""Only these safe classifications may cross the worker/API boundary."""
MESSAGES = {
    'drm': 'Từ chối luồng có DRM hoặc mã hóa không được hỗ trợ (Widevine/PlayReady/FairPlay/SAMPLE-AES).',
    'minors': 'Từ chối: thông tin có dấu hiệu liên quan người chưa thành niên.',
    'disabled': 'Ghi luồng đang tắt trong Cài đặt.',
    'prerequisite': 'Thiếu dữ liệu đầu vào: hãy tách cảnh hoặc tạo phụ đề trước.',
    'output': 'Thư mục output không hợp lệ hoặc nằm trong thư viện chỉ đọc.',
    'failed': 'Xử lý media thất bại. Kiểm tra runtime, mô hình và giới hạn trong Cài đặt.',
}


def classify(error):
    text = str(error).lower()
    if 'drm' in text or 'encrypted stream' in text or 'aes-128 key' in text: return 'drm'
    if 'minors' in text: return 'minors'
    if 'switched off' in text: return 'disabled'
    if 'before translation' in text or 'before describing' in text: return 'prerequisite'
    if 'read-only library' in text or 'outside media output' in text: return 'output'
    return 'failed'


class JobError(RuntimeError):
    def __init__(self, code):
        super().__init__(MESSAGES.get(code, MESSAGES['failed']))

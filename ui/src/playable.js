// hosts measured on 2026-11-25 to serve a video file a browser can play directly; the others answer 403 or 410
const OK = /(^|\.)(tokyo-hot\.com|caribbeancompr\.com|obox\.jp)$/;
export const isPlayable = u => { try { return OK.test(new URL(u).host) && !/\.m3u8/i.test(u); } catch { return false; } };
export const playableList = m => (m.watch || []).filter(w => isPlayable(w.url));
export const canPreview = m => (m.hls || 0) > 0 || playableList(m).length > 0;

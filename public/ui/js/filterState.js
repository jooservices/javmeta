import { esc } from './util.js';

export const values = value => [...new Set(String(value || '').split(',').filter(Boolean))];
export const toggleValue = (value, item) => values(value).includes(String(item)) ? values(value).filter(v => v !== String(item)).join(',') : [...values(value), String(item)].join(',');
export const movieKeys = 'q code genre genre_all actress actress_id maker series label source released_from released_to runtime_min runtime_max score_min tier_min censored on_disk has_cover has_magnet has_performers sort'.split(' ');
export const performerKeys = 'q cup blood_type location age_min age_max height_min height_max bust_min bust_max waist_min waist_max hip_min hip_max debut_from debut_to status has_image has_bio movies_min source sort'.split(' ');
export const multiKeys = ['genre', 'actress_id', 'source', 'cup', 'blood_type'];
export const labels = { q: 'Từ khoá', code: 'Mã phim', genre: 'Thể loại', genre_all: 'Tất cả thể loại', actress: 'Tên chứa…', actress_id: 'Diễn viên', maker: 'Hãng', series: 'Series', label: 'Nhãn', source: 'Nguồn', released_from: 'Từ năm', released_to: 'Đến năm', runtime_min: 'Dài ≥ (phút)', runtime_max: 'Dài ≤ (phút)', score_min: 'Điểm tối thiểu', tier_min: 'Độ đầy đủ tối thiểu', censored: 'Kiểm duyệt', on_disk: 'Trên máy', has_cover: 'Có ảnh bìa', has_magnet: 'Có magnet', has_performers: 'Có diễn viên', cup: 'Cup', blood_type: 'Nhóm máu', location: 'Nơi sống', age_min: 'Tuổi từ', age_max: 'Tuổi đến', height_min: 'Cao ≥ (cm)', height_max: 'Cao ≤ (cm)', bust_min: 'Ngực từ', bust_max: 'Ngực đến', waist_min: 'Eo từ', waist_max: 'Eo đến', hip_min: 'Hông từ', hip_max: 'Hông đến', debut_from: 'Ra mắt từ năm', debut_to: 'Ra mắt đến năm', status: 'Hoạt động', has_image: 'Có ảnh', has_bio: 'Có tiểu sử', movies_min: 'Số phim tối thiểu' };
export function movieParams(query) {
  const p = Object.fromEntries(movieKeys.filter(k => query[k] !== undefined && query[k] !== '').map(k => [k, query[k]]));
  // Preserve links made by catalog and older UI versions.
  if (query.year) { p.released_from = query.year + '-01-01'; p.released_to = query.year + '-12-31'; }
  else { if (query.from) p.released_from = query.from; if (query.to) p.released_to = query.to; }
  if (query.rmin) p.runtime_min = query.rmin;
  if (query.rmax) p.runtime_max = query.rmax;
  for (const [k, suffix] of [['released_from', '-01-01'], ['released_to', '-12-31']]) if (/^\d{4}$/.test(p[k] || '')) p[k] += suffix;
  return p;
}
export function performerParams(query) {
  const p = Object.fromEntries(performerKeys.filter(k => query[k] !== undefined && query[k] !== '').map(k => [k, query[k]]));
  return p;
}
export function activeFilters(query, names = {}) {
  return Object.entries(query).filter(([k, v]) => labels[k] && v !== '' && v !== undefined).flatMap(([k, v]) => (multiKeys.includes(k) ? values(v) : [String(v)]).map(value => ({ key: k, value, label: labels[k] + ': ' + (k === 'actress_id' ? names[value] || '#' + value : value) })));
}
export function removeFilter(query, key, value) {
  const next = { ...query };
  if (multiKeys.includes(key) && value !== undefined) next[key] = values(next[key]).filter(v => v !== value).join(',');
  else delete next[key];
  if (key === 'genre' && !next.genre) delete next.genre_all;
  return next;
}
export const chipsHtml = (query, names) => activeFilters(query, names).map(f => `<button type="button" class="chip on" data-drop="${esc(f.key)}" data-value="${esc(f.value)}" aria-label="Bỏ ${esc(f.label)}">${esc(f.label)} <span aria-hidden="true">×</span></button>`).join('');

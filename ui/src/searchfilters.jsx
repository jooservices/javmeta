import { useEffect, useState } from 'react';
import { Radio, Accordion, Stack, Group, Text, MultiSelect, Select, NumberInput, Switch, Slider, SegmentedControl, TextInput, Badge } from '@mantine/core';
import { fmt, getPerformer, profileFill, PERF_FILTERS as PERF_KEYS } from './shim.js';

// Every option below is counted with all the other filters applied. An option that would give nothing is not offered
// (the one already chosen stays so it can be cleared), and a control with nothing left to choose is hidden.
const LBL = { censored: { censored: 'Có che', uncensored: 'Không che' }, has_magnet: { 1: 'Có', 0: 'Không' }, tier_min: { 2: '≥ tầng 2', 3: 'Tầng 3 (đầy đủ)' }, crawled_days: { 1: 'Trong 24 giờ', 7: 'Trong 7 ngày', 30: 'Trong 30 ngày' }, status: { active: 'Đang hoạt động', retired: 'Đã nghỉ' }, has_performers: { 1: 'Có' }, has_watch: { 1: 'Có' }, has_image: { 1: 'Đã có', 0: 'Chưa có' }, has_bio: { 1: 'Đã có', 0: 'Chưa có' }, has_profile: { 1: 'Đã có', 0: 'Chưa có' } };
const PROFILE_KEYS = ['age_min', 'age_max', 'blood_type', 'location', 'born_from', 'born_to', 'debut_from', 'debut_to', 'cup', 'height_min', 'height_max', 'bust_min', 'bust_max', 'waist_min', 'waist_max', 'hip_min', 'hip_max'];
// a radio with Bất kỳ + the choices that still give something (the chosen one always stays)
const radio = (p, set, counts, k, label, clear = {}) => { const left = Object.entries(counts[k] || {}).filter(([v, n]) => n > 0 || p[k] === v); if (!left.length) return null; return <Radio.Group size="xs" label={label} value={p[k] || ''} onChange={v => set({ [k]: v, ...(v === '0' ? clear : {}) })}><Stack gap={8} mt={7}><Radio size="xs" value="" label="Bất kỳ" />{left.map(([v, n]) => <Radio key={v} size="xs" value={v} label={`${LBL[k][v]} (${fmt(n)})`} />)}</Stack></Radio.Group>; };
const clearProfile = Object.fromEntries(PROFILE_KEYS.map(k => [k, ''])); const clearBio = { bio_q: '' };
const enumSelect = (p, set, counts, k, label) => {
  const left = Object.entries(counts[k] || {}).filter(([v, n]) => n > 0 || p[k] === v); if (!left.length) return null;
  return <Select size="xs" label={label} value={p[k] || ''} onChange={v => set({ [k]: v })} data={[{ value: '', label: 'Bất kỳ' }, ...left.map(([v, n]) => ({ value: v, label: `${LBL[k][v]} (${fmt(n)})` }))]} allowDeselect={false} />;
};
const choices = (list, chosen) => { const have = new Set(list.map(x => x.name)); return [...list.map(x => ({ value: x.name, label: `${x.name} (${fmt(x.n)})` })), ...chosen.filter(v => !have.has(v)).map(v => ({ value: v, label: v }))]; };
const csv = v => (v ? v.split(',') : []);
const range = (p, set, a, b, la, lb, extra = {}) => <Group grow gap={8}><NumberInput size="xs" label={la} value={p[a] || ''} onChange={v => set({ [a]: v })} hideControls {...extra} /><NumberInput size="xs" label={lb} value={p[b] || ''} onChange={v => set({ [b]: v })} hideControls {...extra} /></Group>;
const RUN = { '': ['', ''], lt60: ['', '59'], mid: ['60', '120'], gt120: ['121', ''] };
const RUN_LABEL = { lt60: '<60', mid: '60–120', gt120: '>120' };

export function SearchFilters({ p, set, fc: fc0, pfc, tab, kind = 'all' }) {
  const perfOnly = kind === 'performers';
  const fc = perfOnly ? { genres: fc0.genres, genresNot: [], makers: fc0.makers, series: fc0.series, labels: fc0.labels, sources: [], people: [], years: fc0.years, runtime: {}, enums: {} } : fc0;
  const [open, setOpen] = useState(perfOnly ? ['played', 'perf'] : ['basic', 'time']); useEffect(() => { if (PERF_KEYS.some(k => p[k])) setOpen(o => (o.includes('perf') ? o : [...o, 'perf'])); }, []); // eslint-disable-line
  const [fill, setFill] = useState({ total: 0 }); useEffect(() => { profileFill().then(setFill); }, []); const n = k => `${fmt(fill[k])}/${fmt(fill.total)}`;
  const cur = `${p.runtime_min || ''}|${p.runtime_max || ''}`; const preset = Object.entries(RUN).find(([, [a, b]]) => `${a}|${b}` === cur)?.[0] || 'custom';
  const runData = [{ value: '', label: 'Bất kỳ' }, ...Object.keys(RUN_LABEL).filter(k => fc.runtime[k] > 0 || preset === k).map(k => ({ value: k, label: `${RUN_LABEL[k]}` }))];
  const setRun = v => { const [a, b] = RUN[v]; set({ runtime_min: a, runtime_max: b }); };
  const people = csv(p.actress_id); const peopleData = [...fc.people.map(x => ({ value: String(x.id), label: `${x.name} (${x.n})` })), ...people.filter(i => !fc.people.some(x => String(x.id) === i)).map(i => ({ value: i, label: getPerformer(i)?.name || i }))];
  const yr = fc.years ? { placeholder: fc.years[0] === fc.years[1] ? String(fc.years[0]) : `${fc.years[0]}–${fc.years[1]}`, min: fc.years[0], max: fc.years[1] } : {};
  // only "Chưa có" hides the detail filters (there is nothing to filter on); "Bất kỳ" and "Đã có" show them all
  const profileOn = p.has_profile !== '0'; const bioOn = p.has_bio !== '0';
  const ageHint = pfc.ages ? { placeholder: `${pfc.ages[0]}–${pfc.ages[1]}` } : {};
  const perfItem = (
    <Accordion.Item value="perf"><Accordion.Control><Text size="sm" fw={600}>Hồ sơ diễn viên</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      <Text size="xs" c="dimmed">Lọc theo diễn viên đóng trong phim: phim, ảnh và diễn viên trong kết quả chỉ giữ những gì có diễn viên khớp.</Text>
      {radio(p, set, pfc.enums, 'has_image', 'Ảnh diễn viên')}
      {radio(p, set, pfc.enums, 'has_profile', 'Hồ sơ diễn viên (ngày sinh, số đo, nhóm máu…)', clearProfile)}
      {profileOn ? <Stack gap="sm">
        <Text size="xs" c="dimmed">Chỉ tính diễn viên đã có hồ sơ. Dữ liệu còn thưa: ngày sinh {n('birth')}, nhóm máu {n('blood')}, nơi ở {n('location')}, chiều cao {n('height')}, số đo {n('bust')}, ra mắt {n('debut')}.</Text>
        {range(p, set, 'age_min', 'age_max', 'Tuổi từ', 'đến', { min: 18, max: 80, ...ageHint })}
        {(pfc.blood.length > 0 || p.blood_type) && <MultiSelect size="xs" label="Nhóm máu" clearable value={csv(p.blood_type)} onChange={v => set({ blood_type: v.join(',') })} data={choices(pfc.blood, csv(p.blood_type))} />}
        {(pfc.locations.length > 0 || p.location) && <Select size="xs" label="Nơi ở / nơi sinh (theo nguồn)" searchable clearable value={p.location || null} onChange={v => set({ location: v })} data={choices(pfc.locations, p.location ? [p.location] : [])} />}
        {range(p, set, 'born_from', 'born_to', 'Sinh từ năm', 'đến năm', { min: 1950, max: 2012 })}{range(p, set, 'debut_from', 'debut_to', 'Ra mắt từ năm', 'đến năm', { min: 1990, max: 2030 })}
        {(pfc.cups.length > 0 || p.cup) && <MultiSelect size="xs" label="Cup" clearable value={csv(p.cup)} onChange={v => set({ cup: v.join(',') })} data={choices(pfc.cups, csv(p.cup))} />}
        {range(p, set, 'height_min', 'height_max', 'Cao từ (cm)', 'đến')}
        {range(p, set, 'bust_min', 'bust_max', 'Ngực từ', 'đến')}{range(p, set, 'waist_min', 'waist_max', 'Eo từ', 'đến')}{range(p, set, 'hip_min', 'hip_max', 'Mông từ', 'đến')}
      </Stack> : p.has_profile === '0' && <Text size="xs" c="dimmed">Diễn viên chưa có hồ sơ nên không có tuổi, nhóm máu, nơi ở, số đo… để lọc.</Text>}
      {radio(p, set, pfc.enums, 'has_bio', 'Tiểu sử diễn viên', clearBio)}
      {bioOn ? <TextInput size="xs" label="Tìm trong tiểu sử" value={p.bio_q || ''} onChange={e => set({ bio_q: e.target.value }, true, true)} placeholder="ví dụ: model, debut, Osaka" /> : p.has_bio === '0' && <Text size="xs" c="dimmed">Diễn viên chưa có tiểu sử nên không có chữ để tìm.</Text>}
      {enumSelect(p, set, pfc.enums, 'status', 'Hoạt động')}
      <NumberInput size="xs" label="Số phim tối thiểu" value={p.movies_min || ''} onChange={v => set({ movies_min: v })} hideControls />
    </Stack></Accordion.Panel></Accordion.Item>
  );
  const playedItem = (
    <Accordion.Item value="played"><Accordion.Control><Text size="sm" fw={600}>Đã đóng phim</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      <Text size="xs" c="dimmed">Chỉ giữ diễn viên đã đóng ít nhất một phim thỏa mọi điều kiện dưới đây cùng lúc. Số trong ngoặc là số diễn viên.</Text>
      <MultiSelect size="xs" label="Thể loại" searchable clearable value={csv(p.genre)} onChange={v => set({ genre: v.join(',') })} data={choices(fc.genres, csv(p.genre))} limit={60} placeholder="Chọn…" nothingFoundMessage="Không có" />
      {csv(p.genre).length > 1 && <Switch size="xs" label="Phim phải có tất cả thể loại" checked={p.genre_all === '1'} onChange={e => set({ genre_all: e.currentTarget.checked })} />}
      {(fc.makers.length > 0 || p.maker) && <Select size="xs" label="Hãng" searchable clearable value={p.maker || null} onChange={v => set({ maker: v })} data={choices(fc.makers, p.maker ? [p.maker] : [])} />}
      {(fc.series.length > 0 || p.series) && <Select size="xs" label="Series" searchable clearable value={p.series || null} onChange={v => set({ series: v })} data={choices(fc.series, p.series ? [p.series] : [])} />}
      {(fc.labels.length > 0 || p.label) && <Select size="xs" label="Nhãn" searchable clearable value={p.label || null} onChange={v => set({ label: v })} data={choices(fc.labels, p.label ? [p.label] : [])} />}
      {range(p, set, 'released_from', 'released_to', 'Phim từ năm', 'đến năm', yr)}
    </Stack></Accordion.Panel></Accordion.Item>
  );
  return <Accordion multiple variant="separated" radius="md" value={open} onChange={setOpen} chevronPosition="right" styles={{ content: { paddingTop: 4 } }}>
    {perfOnly && playedItem}
    {!perfOnly && <>
    <Accordion.Item value="basic"><Accordion.Control><Text size="sm" fw={600}>Phim</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      <MultiSelect size="xs" label="Thể loại" searchable clearable value={csv(p.genre)} onChange={v => set({ genre: v.join(',') })} data={choices(fc.genres, csv(p.genre))} limit={60} placeholder="Chọn…" nothingFoundMessage="Không có" />
      {csv(p.genre).length > 1 && <Switch size="xs" label="Phải có tất cả thể loại" checked={p.genre_all === '1'} onChange={e => set({ genre_all: e.currentTarget.checked })} />}
      <MultiSelect size="xs" label="Loại trừ thể loại" searchable clearable value={csv(p.genre_not)} onChange={v => set({ genre_not: v.join(',') })} data={choices(fc.genresNot, csv(p.genre_not))} limit={60} placeholder="Không loại trừ" nothingFoundMessage="Không có" />
      {(fc.makers.length > 0 || p.maker) && <Select size="xs" label="Hãng" searchable clearable value={p.maker || null} onChange={v => set({ maker: v })} data={choices(fc.makers, p.maker ? [p.maker] : [])} />}
      {(fc.series.length > 0 || p.series) && <Select size="xs" label="Series" searchable clearable value={p.series || null} onChange={v => set({ series: v })} data={choices(fc.series, p.series ? [p.series] : [])} />}
      {(fc.labels.length > 0 || p.label) && <Select size="xs" label="Nhãn" searchable clearable value={p.label || null} onChange={v => set({ label: v })} data={choices(fc.labels, p.label ? [p.label] : [])} />}
      {(peopleData.length > 0) && <MultiSelect size="xs" label="Diễn viên (cùng đóng)" searchable clearable value={people} onChange={v => set({ actress_id: v.join(',') })} data={peopleData} limit={40} placeholder="Gõ tên…" nothingFoundMessage="Không có" />}
      <TextInput size="xs" label="Mã phim" value={p.code || ''} onChange={e => set({ code: e.target.value }, true, true)} />
    </Stack></Accordion.Panel></Accordion.Item>
    <Accordion.Item value="time"><Accordion.Control><Text size="sm" fw={600}>Thời gian và độ dài</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      {range(p, set, 'released_from', 'released_to', 'Từ năm', 'Đến năm', yr)}
      {runData.length > 1 && <div><Text size="xs" fw={500} mb={4}>Thời lượng</Text><SegmentedControl size="xs" fullWidth value={preset === 'custom' ? '' : preset} onChange={setRun} data={runData} /></div>}
      {range(p, set, 'runtime_min', 'runtime_max', 'Phút tối thiểu', 'Phút tối đa')}
      {enumSelect(p, set, fc.enums, 'crawled_days', 'Thu thập')}
    </Stack></Accordion.Panel></Accordion.Item>
    <Accordion.Item value="quality"><Accordion.Control><Text size="sm" fw={600}>Chất lượng và nguồn</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      <div><Text size="xs" fw={500}>Điểm tối thiểu: {p.score_min || 0}</Text><Slider size="sm" min={0} max={10} step={0.5} value={+p.score_min || 0} onChangeEnd={v => set({ score_min: v || '' })} /></div>
      {enumSelect(p, set, fc.enums, 'tier_min', 'Độ đầy đủ dữ liệu')}{enumSelect(p, set, fc.enums, 'censored', 'Kiểm duyệt')}
      {enumSelect(p, set, fc.enums, 'has_magnet', 'Có link tải')}{enumSelect(p, set, fc.enums, 'has_performers', 'Có diễn viên')}{enumSelect(p, set, fc.enums, 'has_watch', 'Có thể xem thử')}
      {(fc.sources.length > 0 || p.source) && <MultiSelect size="xs" label="Nguồn" searchable clearable value={csv(p.source)} onChange={v => set({ source: v.join(',') })} data={choices(fc.sources, csv(p.source))} />}
    </Stack></Accordion.Panel></Accordion.Item>
    </>}
    {perfItem}
  </Accordion>;
}

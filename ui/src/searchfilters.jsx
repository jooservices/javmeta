import { useEffect, useState } from 'react';
import { Radio, Accordion, Stack, Group, Text, MultiSelect, Select, NumberInput, Switch, Slider, SegmentedControl, TextInput, Badge } from '@mantine/core';
import { fmt, getPerformer, profileFill, PERF_FILTERS as PERF_KEYS } from './shim.js'; import { t, tk } from './i18n/index.js';

// Every option below is counted with all the other filters applied. An option that would give nothing is not offered
// (the one already chosen stays so it can be cleared), and a control with nothing left to choose is hidden.
const LBL = { censored: { censored: tk('movie.censored'), uncensored: tk('movie.uncensored') }, has_magnet: { 1: tk('flt.yes'), 0: tk('flt.no') }, tier_min: { 2: tk('flt.tier2'), 3: tk('admin.tier3') }, crawled_days: { 1: tk('flt.within24h'), 7: tk('flt.within7d'), 30: tk('flt.within30d') }, status: { active: tk('perf.active'), retired: tk('perf.retired') }, has_performers: { 1: tk('flt.yes') }, has_watch: { 1: tk('flt.yes') }, has_image: { 1: tk('flt.have'), 0: tk('flt.notHave') }, has_bio: { 1: tk('flt.have'), 0: tk('flt.notHave') }, has_profile: { 1: tk('flt.have'), 0: tk('flt.notHave') } };
const PROFILE_KEYS = ['age_min', 'age_max', 'blood_type', 'location', 'born_from', 'born_to', 'debut_from', 'debut_to', 'cup', 'height_min', 'height_max', 'bust_min', 'bust_max', 'waist_min', 'waist_max', 'hip_min', 'hip_max'];
// a radio with "Any" + the choices that still give something (the chosen one always stays)
const radio = (p, set, counts, k, label, clear = {}) => { const left = Object.entries(counts[k] || {}).filter(([v, n]) => n > 0 || p[k] === v); if (!left.length) return null; return <Radio.Group size="xs" label={label} value={p[k] || ''} onChange={v => set({ [k]: v, ...(v === '0' ? clear : {}) })}><Stack gap={8} mt={7}><Radio size="xs" value="" label={t('flt.any')} />{left.map(([v, n]) => <Radio key={v} size="xs" value={v} label={`${t(LBL[k][v])} (${fmt(n)})`} />)}</Stack></Radio.Group>; };
const clearProfile = Object.fromEntries(PROFILE_KEYS.map(k => [k, ''])); const clearBio = { bio_q: '' };
const enumSelect = (p, set, counts, k, label) => {
  const left = Object.entries(counts[k] || {}).filter(([v, n]) => n > 0 || p[k] === v); if (!left.length) return null;
  return <Select size="xs" label={label} value={p[k] || ''} onChange={v => set({ [k]: v })} data={[{ value: '', label: t('flt.any') }, ...left.map(([v, n]) => ({ value: v, label: `${t(LBL[k][v])} (${fmt(n)})` }))]} allowDeselect={false} />;
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
  const runData = [{ value: '', label: t('flt.any') }, ...Object.keys(RUN_LABEL).filter(k => fc.runtime[k] > 0 || preset === k).map(k => ({ value: k, label: `${RUN_LABEL[k]}` }))];
  const setRun = v => { const [a, b] = RUN[v]; set({ runtime_min: a, runtime_max: b }); };
  const people = csv(p.actress_id); const peopleData = [...fc.people.map(x => ({ value: String(x.id), label: `${x.name} (${x.n})` })), ...people.filter(i => !fc.people.some(x => String(x.id) === i)).map(i => ({ value: i, label: getPerformer(i)?.name || i }))];
  const yr = fc.years ? { placeholder: fc.years[0] === fc.years[1] ? String(fc.years[0]) : `${fc.years[0]}–${fc.years[1]}`, min: fc.years[0], max: fc.years[1] } : {};
  // only "Not available" hides the detail filters (there is nothing to filter on); "Any" and "Available" show them all
  const profileOn = p.has_profile !== '0'; const bioOn = p.has_bio !== '0';
  const ageHint = pfc.ages ? { placeholder: `${pfc.ages[0]}–${pfc.ages[1]}` } : {};
  const perfItem = (
    <Accordion.Item value="perf"><Accordion.Control><Text size="sm" fw={600}>{t('flt.performerProfile')}</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      <Text size="xs" c="dimmed">{t('flt.performerHint')}</Text>
      {radio(p, set, pfc.enums, 'has_image', t('flt.performerImage'))}
      {radio(p, set, pfc.enums, 'has_profile', t('flt.performerProfileFull'), clearProfile)}
      {profileOn ? <Stack gap="sm">
        <Text size="xs" c="dimmed">{t('flt.sparse', { birth: n('birth'), blood: n('blood'), location: n('location'), height: n('height'), bust: n('bust'), debut: n('debut') })}</Text>
        {range(p, set, 'age_min', 'age_max', t('flt.ageFrom'), t('flt.to'), { min: 18, max: 80, ...ageHint })}
        {(pfc.blood.length > 0 || p.blood_type) && <MultiSelect size="xs" label={t('facts.bloodType')} clearable value={csv(p.blood_type)} onChange={v => set({ blood_type: v.join(',') })} data={choices(pfc.blood, csv(p.blood_type))} />}
        {(pfc.locations.length > 0 || p.location) && <Select size="xs" label={t('flt.residenceBirth')} searchable clearable value={p.location || null} onChange={v => set({ location: v })} data={choices(pfc.locations, p.location ? [p.location] : [])} />}
        {range(p, set, 'born_from', 'born_to', t('flt.bornFromYear'), t('flt.toYear'), { min: 1950, max: 2012 })}{range(p, set, 'debut_from', 'debut_to', t('flt.debutFromYear'), t('flt.toYear'), { min: 1990, max: 2030 })}
        {(pfc.cups.length > 0 || p.cup) && <MultiSelect size="xs" label="Cup" clearable value={csv(p.cup)} onChange={v => set({ cup: v.join(',') })} data={choices(pfc.cups, csv(p.cup))} />}
        {range(p, set, 'height_min', 'height_max', t('flt.heightFrom'), t('flt.to'))}
        {range(p, set, 'bust_min', 'bust_max', t('flt.bustFrom'), t('flt.to'))}{range(p, set, 'waist_min', 'waist_max', t('flt.waistFrom'), t('flt.to'))}{range(p, set, 'hip_min', 'hip_max', t('flt.hipFrom'), t('flt.to'))}
      </Stack> : p.has_profile === '0' && <Text size="xs" c="dimmed">{t('flt.noProfile')}</Text>}
      {radio(p, set, pfc.enums, 'has_bio', t('flt.performerBio'), clearBio)}
      {bioOn ? <TextInput size="xs" label={t('flt.searchBio')} value={p.bio_q || ''} onChange={e => set({ bio_q: e.target.value }, true, true)} placeholder={t('flt.bioPh')} /> : p.has_bio === '0' && <Text size="xs" c="dimmed">{t('flt.noBio')}</Text>}
      {enumSelect(p, set, pfc.enums, 'status', t('flt.label.status'))}
      <NumberInput size="xs" label={t('flt.minMovies')} value={p.movies_min || ''} onChange={v => set({ movies_min: v })} hideControls />
    </Stack></Accordion.Panel></Accordion.Item>
  );
  const playedItem = (
    <Accordion.Item value="played"><Accordion.Control><Text size="sm" fw={600}>{t('flt.playedIn')}</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      <Text size="xs" c="dimmed">{t('flt.playedHint')}</Text>
      <MultiSelect size="xs" label={t('omnibox.kind.genre')} searchable clearable value={csv(p.genre)} onChange={v => set({ genre: v.join(',') })} data={choices(fc.genres, csv(p.genre))} limit={60} placeholder={t('flt.choose')} nothingFoundMessage={t('flt.none')} />
      {csv(p.genre).length > 1 && <Switch size="xs" label={t('flt.allGenresMovie')} checked={p.genre_all === '1'} onChange={e => set({ genre_all: e.currentTarget.checked })} />}
      {(fc.makers.length > 0 || p.maker) && <Select size="xs" label={t('movie.maker')} searchable clearable value={p.maker || null} onChange={v => set({ maker: v })} data={choices(fc.makers, p.maker ? [p.maker] : [])} />}
      {(fc.series.length > 0 || p.series) && <Select size="xs" label="Series" searchable clearable value={p.series || null} onChange={v => set({ series: v })} data={choices(fc.series, p.series ? [p.series] : [])} />}
      {(fc.labels.length > 0 || p.label) && <Select size="xs" label={t('movie.label')} searchable clearable value={p.label || null} onChange={v => set({ label: v })} data={choices(fc.labels, p.label ? [p.label] : [])} />}
      {range(p, set, 'released_from', 'released_to', t('flt.moviesFromYear'), t('flt.toYear'), yr)}
    </Stack></Accordion.Panel></Accordion.Item>
  );
  return <Accordion multiple variant="separated" radius="md" value={open} onChange={setOpen} chevronPosition="right" styles={{ content: { paddingTop: 4 } }}>
    {perfOnly && playedItem}
    {!perfOnly && <>
    <Accordion.Item value="basic"><Accordion.Control><Text size="sm" fw={600}>{t('common.movies')}</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      <MultiSelect size="xs" label={t('omnibox.kind.genre')} searchable clearable value={csv(p.genre)} onChange={v => set({ genre: v.join(',') })} data={choices(fc.genres, csv(p.genre))} limit={60} placeholder={t('flt.choose')} nothingFoundMessage={t('flt.none')} />
      {csv(p.genre).length > 1 && <Switch size="xs" label={t('flt.allGenres')} checked={p.genre_all === '1'} onChange={e => set({ genre_all: e.currentTarget.checked })} />}
      <MultiSelect size="xs" label={t('flt.excludeGenres')} searchable clearable value={csv(p.genre_not)} onChange={v => set({ genre_not: v.join(',') })} data={choices(fc.genresNot, csv(p.genre_not))} limit={60} placeholder={t('flt.noExclusion')} nothingFoundMessage={t('flt.none')} />
      {(fc.makers.length > 0 || p.maker) && <Select size="xs" label={t('movie.maker')} searchable clearable value={p.maker || null} onChange={v => set({ maker: v })} data={choices(fc.makers, p.maker ? [p.maker] : [])} />}
      {(fc.series.length > 0 || p.series) && <Select size="xs" label="Series" searchable clearable value={p.series || null} onChange={v => set({ series: v })} data={choices(fc.series, p.series ? [p.series] : [])} />}
      {(fc.labels.length > 0 || p.label) && <Select size="xs" label={t('movie.label')} searchable clearable value={p.label || null} onChange={v => set({ label: v })} data={choices(fc.labels, p.label ? [p.label] : [])} />}
      {(peopleData.length > 0) && <MultiSelect size="xs" label={t('flt.coStars')} searchable clearable value={people} onChange={v => set({ actress_id: v.join(',') })} data={peopleData} limit={40} placeholder={t('flt.typeName')} nothingFoundMessage={t('flt.none')} />}
      <TextInput size="xs" label={t('sort.code')} value={p.code || ''} onChange={e => set({ code: e.target.value }, true, true)} />
    </Stack></Accordion.Panel></Accordion.Item>
    <Accordion.Item value="time"><Accordion.Control><Text size="sm" fw={600}>{t('flt.timeLength')}</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      {range(p, set, 'released_from', 'released_to', t('flt.label.released_from'), t('flt.label.released_to'), yr)}
      {runData.length > 1 && <div><Text size="xs" fw={500} mb={4}>{t('movie.runtime')}</Text><SegmentedControl size="xs" fullWidth value={preset === 'custom' ? '' : preset} onChange={setRun} data={runData} /></div>}
      {range(p, set, 'runtime_min', 'runtime_max', t('flt.minMinutes'), t('flt.maxMinutes'))}
      {enumSelect(p, set, fc.enums, 'crawled_days', t('movie.crawled'))}
    </Stack></Accordion.Panel></Accordion.Item>
    <Accordion.Item value="quality"><Accordion.Control><Text size="sm" fw={600}>{t('flt.qualitySource')}</Text></Accordion.Control><Accordion.Panel><Stack gap="sm">
      <div><Text size="xs" fw={500}>{t('flt.minScore', { n: p.score_min || 0 })}</Text><Slider size="sm" min={0} max={10} step={0.5} value={+p.score_min || 0} onChangeEnd={v => set({ score_min: v || '' })} /></div>
      {enumSelect(p, set, fc.enums, 'tier_min', t('stats.completeness'))}{enumSelect(p, set, fc.enums, 'censored', t('flt.label.censored'))}
      {enumSelect(p, set, fc.enums, 'has_magnet', t('stats.hasLinks'))}{enumSelect(p, set, fc.enums, 'has_performers', t('stats.hasCast'))}{enumSelect(p, set, fc.enums, 'has_watch', t('flt.canPreview'))}
      {(fc.sources.length > 0 || p.source) && <MultiSelect size="xs" label={t('movie.sources')} searchable clearable value={csv(p.source)} onChange={v => set({ source: v.join(',') })} data={choices(fc.sources, csv(p.source))} />}
    </Stack></Accordion.Panel></Accordion.Item>
    </>}
    {perfItem}
  </Accordion>;
}

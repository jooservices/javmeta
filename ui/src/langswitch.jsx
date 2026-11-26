import { SegmentedControl } from '@mantine/core';
import { LANGS, setLang, useLang, t } from './i18n/index.js';

// the VI | EN switch of the app header
export function LangSwitch() {
  const lang = useLang();
  return <SegmentedControl size="xs" value={lang} onChange={setLang} data={LANGS.map(l => ({ value: l, label: l.toUpperCase() }))} aria-label={t('lang.switch')} />;
}

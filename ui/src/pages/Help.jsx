import { Stack, Table, Text, Paper, Title, Code, SimpleGrid, Badge } from '@mantine/core';
import { PageHead } from '../components.jsx'; import { ShortcutTable } from '../features.jsx'; import { t } from '../i18n/index.js';
export default function Help() {
  return <Stack gap="lg"><PageHead title={t('nav.help')} />
    <SimpleGrid cols={{ base: 1, md: 2 }}><Paper className="tile"><Title order={4} mb="xs">{t('app.shortcuts')}</Title><ShortcutTable /></Paper>
      <Paper className="tile"><Title order={4} mb="xs">Telegram bot</Title><Table withRowBorders={false} verticalSpacing={8}><Table.Tbody>{[['/search Drama', t('help.cmd.search')], ['/movie SSIS-001', t('help.cmd.movie')], ['/status', t('help.cmd.status')], [`/follow maker ${t('help.cmd.nameArg')}`, t('help.cmd.follow')], ['/unfollow …', t('mine.unfollow')], ['/follows', t('help.cmd.follows')], ['/download SSIS-001', t('help.cmd.download')], ['/downloads', t('help.cmd.downloads')]].map(([k, d]) => <Table.Tr key={k}><Table.Td><Code>{k}</Code></Table.Td><Table.Td><Text size="sm">{d}</Text></Table.Td></Table.Tr>)}</Table.Tbody></Table></Paper>
      <Paper className="tile"><Title order={4} mb="xs">{t('help.domains')}</Title><Text size="sm">{t('help.domains1')}<Code>jav.soulevil.com</Code>{t('help.domains2')}<Code>jav-cc.soulevil.com</Code>{t('help.domains3')}</Text></Paper>
      <Paper className="tile"><Title order={4} mb="xs">{t('help.ifWrong')}</Title><Text size="sm">{t('help.ifWrongBody')}</Text></Paper></SimpleGrid></Stack>;
}

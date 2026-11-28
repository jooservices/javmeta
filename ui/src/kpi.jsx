import { Box, Progress, Group, Text, Badge, Paper } from '@mantine/core';
import { Sparkline } from '@mantine/charts';

export const Kpi = ({ label, value, delta, data, bar, color = 'teal.6', wide }) => <Paper className={`tile kpi${wide ? ' kpi-wide' : ''}`}><Group justify="space-between" align="flex-end" wrap="nowrap" gap="md"><div style={{ minWidth: 0 }}><Text size="xs" c="dimmed" tt="uppercase" fw={600}>{label}</Text><Text fz={26} fw={600} className="mono" lh={1.2}>{value}</Text><Badge variant="light" color="gray" size="md" style={{ textTransform: 'none' }}>{delta}</Badge></div>
  {data && data.length > 1 && <Box style={{ flex: '1 1 40%', minWidth: 80, maxWidth: 220 }}><Sparkline w="100%" h={56} data={data} curveType="monotone" color={color} fillOpacity={0.18} strokeWidth={2} /></Box>}</Group>{bar != null && <Progress value={bar} size="sm" color="teal" mt="sm" />}</Paper>;

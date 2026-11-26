import { Stack, Table, Text, Paper, Title, Code, SimpleGrid, Badge } from '@mantine/core';
import { PageHead } from '../components.jsx'; import { ShortcutTable } from '../features.jsx';
export default function Help() {
  return <Stack gap="lg"><PageHead title="Trợ giúp & phím tắt" />
    <SimpleGrid cols={{ base: 1, md: 2 }}><Paper className="tile"><Title order={4} mb="xs">Phím tắt</Title><ShortcutTable /></Paper>
      <Paper className="tile"><Title order={4} mb="xs">Telegram bot</Title><Table withRowBorders={false} verticalSpacing={8}><Table.Tbody>{[['/search Drama', 'Gợi ý phim, diễn viên, thể loại, hãng, series'], ['/movie SSIS-001', 'Thông tin một mã phim'], ['/status', 'Số phim, diễn viên, việc chờ và lỗi'], ['/follow maker Tên', 'Theo dõi hãng / series / diễn viên'], ['/unfollow …', 'Bỏ theo dõi'], ['/follows', 'Danh sách đang theo dõi'], ['/download SSIS-001', 'Yêu cầu tải (khi bật)'], ['/downloads', 'Tối đa 15 mục tải']].map(([k, d]) => <Table.Tr key={k}><Table.Td><Code>{k}</Code></Table.Td><Table.Td><Text size="sm">{d}</Text></Table.Td></Table.Tr>)}</Table.Tbody></Table></Paper>
      <Paper className="tile"><Title order={4} mb="xs">Hai tên miền</Title><Text size="sm">Giao diện và API: <Code>jav.soulevil.com</Code>. Command Center: <Code>jav-cc.soulevil.com</Code>. Trong UI mới cả hai gộp thành một ứng dụng, Command Center là nhóm “Vận hành”.</Text></Paper>
      <Paper className="tile"><Title order={4} mb="xs">Nếu có vẻ sai</Title><Text size="sm">Đợi trạng thái tải xong. Nếu thấy lỗi: kiểm tra kết nối, bấm Thử lại. Danh sách rỗng không chứng minh phim không tồn tại ở nguồn; thử bỏ bộ lọc, rồi xem “Thu thập & nguồn”. Khi báo lỗi: gửi tên trang, thời điểm và mã phim, không gửi khoá hay mật khẩu.</Text></Paper></SimpleGrid></Stack>;
}

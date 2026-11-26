import React from 'react'; import { createRoot } from 'react-dom/client'; import { BrowserRouter } from 'react-router-dom';
import { MantineProvider, createTheme } from '@mantine/core'; import { Notifications } from '@mantine/notifications'; import { ModalsProvider } from '@mantine/modals';
import '@mantine/core/styles.css'; import '@mantine/notifications/styles.css'; import '@mantine/charts/styles.css'; import '@mantine/dates/styles.css'; import '@mantine/spotlight/styles.css';
import './app.css'; import App from './App.jsx';
const theme = createTheme({
  primaryColor: 'teal', defaultRadius: 'md', spacing: { xs: '8px', sm: '12px', md: '16px', lg: '24px', xl: '32px' }, fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif', fontFamilyMonospace: 'ui-monospace, "JetBrains Mono", Menlo, monospace',
  colors: { dark: ['#dde4ec', '#b8c2cf', '#8a96a4', '#5a6572', '#3a434e', '#252c35', '#181d23', '#12161b', '#0e1216', '#0b0e11'] },
  components: { Button: { defaultProps: { size: 'sm' } }, Badge: { defaultProps: { radius: 'sm' } }, Anchor: { defaultProps: { underline: 'never', c: 'teal.3' } }, Table: { defaultProps: { verticalSpacing: 8, horizontalSpacing: 12 } } },
});
createRoot(document.getElementById('root')).render(<MantineProvider theme={theme} defaultColorScheme="dark"><ModalsProvider><Notifications position="bottom-right" /><BrowserRouter><App /></BrowserRouter></ModalsProvider></MantineProvider>);

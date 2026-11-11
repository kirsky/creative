import '@mantine/core/styles.css';
import '@mantine/dates/styles.css';
import './styles/base.scss';
import './i18n';
import { createRoot } from 'react-dom/client';
import { App } from './app/App';

const container = document.getElementById('root');
if (container === null) {
  throw new Error('index.html has no #root element');
}
createRoot(container).render(<App />);

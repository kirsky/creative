import '@mantine/core/styles.css';
import './styles/base.scss';
import { createRoot } from 'react-dom/client';
import { App } from './app/App';

const container = document.getElementById('root');
if (container === null) {
  throw new Error('index.html has no #root element');
}
createRoot(container).render(<App />);

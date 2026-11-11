import { createTheme } from '@mantine/core';

/**
 * Mantine theme. Colours stay on Mantine's tokens so light and dark both work; never hard-code
 * a text or background colour anywhere in the app.
 */
export const theme = createTheme({
  primaryColor: 'indigo',
  defaultRadius: 'md',
});

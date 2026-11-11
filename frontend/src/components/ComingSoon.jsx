import { Stack, Text, Title } from '@mantine/core';

// All user-visible text of this component.
const TEXT = { comingSoon: 'Coming soon' };

/**
 * Marks a route whose page is built in a later task.
 * @param {{name: string}} props the page name
 */
export function ComingSoon({ name }) {
  return (
    <Stack gap="xs" data-testid="coming-soon">
      <Title order={1}>{name}</Title>
      <Text c="dimmed">{TEXT.comingSoon}</Text>
    </Stack>
  );
}

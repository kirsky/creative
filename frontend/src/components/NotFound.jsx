import { Anchor, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router';

// All user-visible text of this component.
const TEXT = { title: 'Page not found', home: 'Go to your start page' };

export function NotFound() {
  return (
    <Stack gap="xs">
      <Title order={1}>{TEXT.title}</Title>
      <Text>
        <Anchor component={Link} to="/">
          {TEXT.home}
        </Anchor>
      </Text>
    </Stack>
  );
}

import { useParams } from 'react-router';
import { ComingSoon } from './ComingSoon';

/** The `/tasks/:id` route until the task page is built. */
export function TaskPlaceholder() {
  const { id } = useParams();
  return <ComingSoon titleKey="task:placeholderTitle" values={{ id: id ?? '' }} />;
}

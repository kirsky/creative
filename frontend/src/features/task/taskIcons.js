import {
  ArrowUUpLeft,
  CheckCircle,
  CircleDashed,
  Eye,
  Flag,
  Timer,
  XCircle,
} from '@phosphor-icons/react';

/** One icon per task status, shown beside the status wording. @type {Record<string, import('../../components/Icon').IconComponent>} */
export const STATUS_ICONS = {
  new: CircleDashed,
  in_progress: Timer,
  under_review: Eye,
  revisions_required: ArrowUUpLeft,
  completed: CheckCircle,
  cancelled: XCircle,
};

/** The priority icon; the colour of the badge says which priority it is. */
export const PRIORITY_ICON = Flag;

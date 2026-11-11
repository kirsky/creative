import { Button } from '@mantine/core';
import {
  ArrowCounterClockwise,
  ArrowUUpLeft,
  CheckCircle,
  Coins,
  HandGrabbing,
  PaperPlaneTilt,
  PencilSimple,
  Prohibit,
} from '@phosphor-icons/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '../../../components/Icon';
import { useAuth } from '../../auth/useAuth';
import { usePickUpAction } from '../../board/usePickUpAction';
import './ActionBar.scss';
import { AiUsageDialog } from './AiUsageDialog';
import { ApproveDialog, CancelDialog, ReleaseDialog } from './ConfirmActions';
import { EditBriefDialog } from './EditBriefDialog';
import { RevisionsDialog } from './RevisionsDialog';
import { SubmitDialog } from './SubmitDialog';

/** @typedef {'submit' | 'aiUsage' | 'approve' | 'revisions' | 'release' | 'editBrief' | 'cancel'} DialogName */

/**
 * The round whose AI usage the signed-in user may still correct: the latest one, if the buyer has
 * not decided it and this user submitted it. The API's `report_ai_usage` is coarser than that.
 * @param {import('../api').TaskDetail} task
 * @param {number | undefined} userId
 * @returns {import('../api').Round | null}
 */
export function editableRound(task, userId) {
  if (userId === undefined || !task.allowedActions.includes('report_ai_usage')) {
    return null;
  }
  const latest = task.rounds.reduce(
    /** @param {import('../api').Round | null} best @param {import('../api').Round} round */
    (best, round) => (best === null || round.number > best.number ? round : best),
    null,
  );
  return latest !== null && latest.decision === null && latest.submittedBy.id === userId
    ? latest
    : null;
}

/**
 * The buttons at the end of the main column. They show exactly what the API says the user may do
 * (`allowedActions`), plus the AI-usage rule above; the server enforces everything, this only hides
 * what is impossible. A user with nothing to do sees nothing.
 * @param {{task: import('../api').TaskDetail}} props
 */
export function ActionBar({ task }) {
  const { t } = useTranslation('task');
  const { user } = useAuth();
  const { pickUp, pendingId } = usePickUpAction();
  const [dialog, setDialog] = useState(/** @type {DialogName | null} */ (null));
  // The round being edited as it was when the dialog opened, so a refetch does not pull it away.
  const [editing, setEditing] = useState(/** @type {import('../api').Round | null} */ (null));
  const opener = useRef(/** @type {HTMLElement | null} */ (null));

  // A dialog that was dismissed gives the focus back to the button that opened it.
  useEffect(() => {
    if (dialog === null && opener.current !== null) {
      if (opener.current.isConnected) {
        opener.current.focus();
      }
      opener.current = null;
    }
  }, [dialog]);

  const can = (/** @type {string} */ action) => task.allowedActions.includes(action);
  const round = editableRound(task, user?.id);

  /**
   * @param {DialogName} name
   * @param {import('react').MouseEvent<HTMLElement>} event the click on the button that opens it
   */
  function open(name, event) {
    opener.current = event.currentTarget;
    setDialog(name);
  }
  const close = () => setDialog(null);

  const hasButtons =
    can('pick_up') ||
    can('submit') ||
    round !== null ||
    can('approve') ||
    can('request_revisions') ||
    can('release') ||
    can('edit_brief') ||
    can('cancel');
  if (!hasButtons) {
    return null;
  }

  return (
    <>
      <div className="action-bar" role="group" aria-label={t('actions.label')}>
        {can('pick_up') && (
          <Button
            variant="gradient"
            leftSection={<Icon icon={HandGrabbing} />}
            loading={pendingId === task.id}
            disabled={pendingId !== null}
            onClick={() => void pickUp(task)}
          >
            {t('actions.pickUp')}
          </Button>
        )}
        {can('submit') && (
          <Button
            variant="gradient"
            leftSection={<Icon icon={PaperPlaneTilt} />}
            onClick={(event) => open('submit', event)}
          >
            {t('actions.submit')}
          </Button>
        )}
        {round !== null && (
          <Button
            variant="default"
            leftSection={<Icon icon={Coins} />}
            onClick={(event) => {
              setEditing(round);
              open('aiUsage', event);
            }}
          >
            {t('actions.editAiUsage')}
          </Button>
        )}
        {can('approve') && (
          <Button
            variant="gradient"
            leftSection={<Icon icon={CheckCircle} />}
            onClick={(event) => open('approve', event)}
          >
            {t('actions.approve')}
          </Button>
        )}
        {can('request_revisions') && (
          <Button
            variant="default"
            leftSection={<Icon icon={ArrowUUpLeft} />}
            onClick={(event) => open('revisions', event)}
          >
            {t('actions.requestRevisions')}
          </Button>
        )}
        {can('release') && (
          <Button
            variant="default"
            leftSection={<Icon icon={ArrowCounterClockwise} />}
            onClick={(event) => open('release', event)}
          >
            {t('actions.release')}
          </Button>
        )}
        {can('edit_brief') && (
          <Button
            variant="default"
            leftSection={<Icon icon={PencilSimple} />}
            onClick={(event) => open('editBrief', event)}
          >
            {t('actions.editBrief.open')}
          </Button>
        )}
        {can('cancel') && (
          <Button
            className="action-bar__danger"
            color="red"
            variant="outline"
            leftSection={<Icon icon={Prohibit} />}
            onClick={(event) => open('cancel', event)}
          >
            {t('actions.cancelTask')}
          </Button>
        )}
      </div>
      {dialog === 'submit' && <SubmitDialog taskId={task.id} onClose={close} />}
      {dialog === 'aiUsage' && editing !== null && (
        <AiUsageDialog
          taskId={task.id}
          round={editing.number}
          lines={editing.aiUsage}
          onClose={close}
        />
      )}
      {dialog === 'approve' && <ApproveDialog taskId={task.id} onClose={close} />}
      {dialog === 'revisions' && <RevisionsDialog taskId={task.id} onClose={close} />}
      {dialog === 'release' && <ReleaseDialog taskId={task.id} onClose={close} />}
      {dialog === 'editBrief' && <EditBriefDialog task={task} onClose={close} />}
      {dialog === 'cancel' && <CancelDialog taskId={task.id} onClose={close} />}
    </>
  );
}

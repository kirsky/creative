<?php

declare(strict_types=1);

namespace App\History;

/**
 * What happened to a task. The values are the codes the API sends and task_events.type stores.
 *
 * Each event carries a structured payload, never display text, so that the history renders in the
 * viewer's language. The payload each type carries is fixed here as a contract for the code that
 * records events (later tasks) and the UI that renders them. TaskEventLog does not check payloads
 * against it; it only needs a payload that is a JSON object.
 *
 * Payloads:
 *
 *   task_created         no fields are fixed (written when a task is created)
 *   task_picked_up       {assignmentId: int}
 *   task_released        {assignmentId: int}
 *   round_submitted      {round: int, fileCount: int}
 *   revisions_requested  {round: int, reason: string}
 *   task_approved        {round: int, note: ?string}
 *   task_cancelled       {reason: ?string}
 *   brief_edited         {changes: list<{field: string, old: mixed, new: mixed}>}
 *   ai_usage_changed     {round: int, lines: list<{provider: string, credits: string}>}
 *
 * The same changes of a brief_edited event are also stored row by row through FieldAuditLog.
 */
enum TaskEventType: string
{
    /** Payload: none fixed. */
    case TaskCreated = 'task_created';

    /** Payload: {assignmentId: int}. */
    case TaskPickedUp = 'task_picked_up';

    /** Payload: {assignmentId: int}. */
    case TaskReleased = 'task_released';

    /** Payload: {round: int, fileCount: int}. */
    case RoundSubmitted = 'round_submitted';

    /** Payload: {round: int, reason: string}. */
    case RevisionsRequested = 'revisions_requested';

    /** Payload: {round: int, note: ?string}; null when the buyer gave no note. */
    case TaskApproved = 'task_approved';

    /** Payload: {reason: ?string}; null when the buyer gave no reason. */
    case TaskCancelled = 'task_cancelled';

    /** Payload: {changes: list<{field: string, old: mixed, new: mixed}>}. */
    case BriefEdited = 'brief_edited';

    /** Payload: {round: int, lines: list<{provider: string, credits: string}>}. */
    case AiUsageChanged = 'ai_usage_changed';
}

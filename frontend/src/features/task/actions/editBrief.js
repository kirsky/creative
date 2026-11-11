import dayjs from 'dayjs';
import { z } from 'zod';
import { localDateTimeToUtcIso } from '../../../i18n/format';
import { parseLocalDateTime, quantityValue, taskSchema } from '../../tasks/schema';

/**
 * What the edit-brief form holds: the brief of the create form without its references (the API
 * does not let them be edited).
 * @typedef {Omit<import('../../tasks/schema').TaskFormValues, 'urls'>} BriefFormValues
 */

/**
 * The part of a brief PATCH /api/tasks/{id} accepts; only what changed is sent.
 * @typedef {object} BriefChanges
 * @property {string} [offer]
 * @property {string[]} [geo]
 * @property {string} [creativeType]
 * @property {number} [quantity]
 * @property {string} [description]
 * @property {string} [priority]
 * @property {string} [deadline] ISO 8601, UTC
 */

const LOCAL_DATE_TIME = 'YYYY-MM-DD HH:mm:ss';

/** @param {string[]} codes */
const sorted = (codes) => [...codes].sort();

/**
 * The form filled from the task as it is now; the deadline in the browser's time zone, as the
 * picker writes values.
 * @param {import('../api').TaskDetail} task
 * @returns {BriefFormValues}
 */
export function briefFormValues(task) {
  return {
    offer: task.offer,
    geo: [...task.geo],
    creativeType: task.creativeType,
    quantity: task.quantity,
    priority: task.priority,
    description: task.description,
    deadline: dayjs(task.deadlineAt).format(LOCAL_DATE_TIME),
  };
}

/**
 * The fields of a valid form that differ from the task, as the API wants them: the offer trimmed,
 * the GEO sorted, the deadline in UTC. Values are compared in that normalised form, so re-ordering
 * the GEO or adding spaces around the offer is no change. The description is compared and sent as
 * typed, because the server stores it as sent.
 * @param {import('../api').TaskDetail} task
 * @param {BriefFormValues} values
 * @returns {BriefChanges}
 */
export function briefChanges(task, values) {
  /** @type {BriefChanges} */
  const changes = {};
  const offer = values.offer.trim();
  if (offer !== task.offer) {
    changes.offer = offer;
  }
  const geo = sorted(values.geo);
  if (geo.join(',') !== sorted(task.geo).join(',')) {
    changes.geo = geo;
  }
  if (values.creativeType !== task.creativeType) {
    changes.creativeType = values.creativeType;
  }
  const quantity = quantityValue(values.quantity);
  if (quantity !== task.quantity) {
    changes.quantity = quantity;
  }
  if (values.description !== task.description) {
    changes.description = values.description;
  }
  if (values.priority !== task.priority) {
    changes.priority = values.priority;
  }
  const deadline = parseLocalDateTime(values.deadline);
  if (deadline !== null && deadline.getTime() !== Date.parse(task.deadlineAt)) {
    changes.deadline = localDateTimeToUtcIso(deadline);
  }
  return changes;
}

/** The deadline rules of the create form: required, a date, in the future, within ten years. */
const newDeadline = taskSchema.shape.deadline;

/**
 * The rules of the create form for the brief fields. The deadline has to be in the future only
 * when it is changed: a task whose deadline has passed can still be edited without moving it, as
 * the API allows.
 * @param {string | null} initialDeadline the deadline the form started with (local, as the picker writes it)
 */
export function editBriefSchema(initialDeadline) {
  return taskSchema.omit({ urls: true }).extend({
    deadline: z
      .string()
      .nullable()
      .superRefine((value, ctx) => {
        if (value !== null && value === initialDeadline) {
          return;
        }
        const result = newDeadline.safeParse(value);
        for (const problem of result.error?.issues ?? []) {
          ctx.addIssue({ code: 'custom', message: problem.message });
        }
      }),
  });
}

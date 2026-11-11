import { z } from 'zod';
import { issueMessage } from '../../tasks/schema';

/** The limits of the task actions (openapi.yaml: NewRound, ReplaceAiUsageRequest, the decisions). */
export const LIMITS = {
  text: 2000,
  files: 25,
  aiLines: 20,
  provider: 50,
};

/** What the server accepts as an amount of credits: 0 to 9999999.99, no sign, exponent or spaces. */
export const CREDITS_PATTERN = /^\d{1,7}(\.\d{1,2})?$/;

/** Characters as a person counts them (an emoji is one), like the server does. */
const length = (/** @type {string} */ text) => Array.from(text).length;

/** @param {string} message @returns {{code: 'custom', message: string}} */
const issue = (message) => ({ code: 'custom', message });

/**
 * A free text of at most 2000 characters. It is judged and sent trimmed.
 * @param {{required: boolean}} options
 */
function textSchema({ required }) {
  return z.string({ error: 'required' }).superRefine((value, ctx) => {
    const text = value.trim();
    if (text === '') {
      if (required) {
        ctx.addIssue(issue('required'));
      }
    } else if (length(text) > LIMITS.text) {
      ctx.addIssue(issue(issueMessage('too_long', { max: LIMITS.text })));
    }
  });
}

export const optionalTextSchema = textSchema({ required: false });
export const requiredTextSchema = textSchema({ required: true });

/**
 * The text of a free field (the note, the reason) as the API wants it.
 * @param {string} text
 * @returns {string | null} the problem as an issue message, or null when the text is fine
 */
export function textProblem(text) {
  const result = optionalTextSchema.safeParse(text);
  return result.success ? null : (result.error.issues[0]?.message ?? 'invalid');
}

const aiLineSchema = z.object({
  provider: z.string({ error: 'required' }).superRefine((value, ctx) => {
    const name = value.trim();
    if (name === '') {
      ctx.addIssue(issue('required'));
    } else if (length(name) > LIMITS.provider) {
      ctx.addIssue(issue(issueMessage('too_long', { max: LIMITS.provider })));
    } else if (/\p{Cc}/u.test(name)) {
      ctx.addIssue(issue('invalid'));
    }
  }),
  // Judged as typed: a space, a sign or an exponent is an error to fix, not something to repair.
  credits: z.string({ error: 'required' }).superRefine((value, ctx) => {
    if (value === '') {
      ctx.addIssue(issue('required'));
    } else if (!CREDITS_PATTERN.test(value)) {
      ctx.addIssue(issue('invalid_amount'));
    }
  }),
});

/** Zero lines mean "No AI used" and are valid. */
export const aiUsageSchema = z
  .array(aiLineSchema)
  .max(LIMITS.aiLines, issueMessage('too_many', { max: LIMITS.aiLines }));

export const aiUsageFormSchema = z.object({ aiUsage: aiUsageSchema });

export const submitFormSchema = z.object({
  note: optionalTextSchema,
  aiUsage: aiUsageSchema,
});

export const revisionsFormSchema = z.object({ reason: requiredTextSchema });

/**
 * The lines of the form as the API wants them: the provider trimmed, the credits untouched (they
 * are strings, never numbers).
 * @param {{provider: string, credits: string}[]} lines
 */
export function toApiLines(lines) {
  return lines.map(({ provider, credits }) => ({ provider: provider.trim(), credits }));
}

/**
 * @typedef {object} AiUsageFormValues
 * @property {{provider: string, credits: string}[]} aiUsage
 */

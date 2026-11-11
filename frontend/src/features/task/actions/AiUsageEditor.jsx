import { Autocomplete, Button, CloseButton, Input, Text, TextInput } from '@mantine/core';
import { Controller, get, useFieldArray, useFormState } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { translateIssue } from '../../tasks/schema';
import { useAiProviders } from './api';
import './AiUsageEditor.scss';
import { LIMITS } from './schemas';

/**
 * The AI usage of a round as lines of provider and credits, inside a React Hook Form form whose
 * values have an `aiUsage` array (aiUsageSchema judges it). No lines is valid and means "No AI
 * used". The provider is free text with the managed providers as suggestions; the credits are
 * text too and stay strings all the way to the API.
 * @param {{control: any, disabled?: boolean}} props `control` is that of the form (any form
 *   whose values have an `aiUsage` array; React Hook Form's own type cannot express that)
 */
export function AiUsageEditor({ control, disabled = false }) {
  const { t } = useTranslation(['task', 'errors']);
  const { fields, append, remove } = useFieldArray({ control, name: 'aiUsage' });
  const { errors } = useFormState({ control });
  const providers = useAiProviders();
  const names = providers.data?.providers.map((provider) => provider.name) ?? [];

  const listError = get(errors, 'aiUsage.root.message') ?? get(errors, 'aiUsage.message');

  return (
    <Input.Wrapper
      labelElement="div"
      label={t('task:actions.aiUsage.title')}
      description={t('task:actions.aiUsage.hint')}
      error={typeof listError === 'string' ? translateIssue(t, listError) : undefined}
    >
      <div className="ai-usage">
        {fields.length === 0 && (
          <Text size="sm" c="dimmed">
            {t('task:actions.aiUsage.none')}
          </Text>
        )}
        {fields.map((field, index) => (
          <div key={field.id} className="ai-usage__line">
            <Controller
              control={control}
              name={`aiUsage.${index}.provider`}
              render={({ field: input, fieldState }) => (
                <Autocomplete
                  className="ai-usage__provider"
                  ref={input.ref}
                  // A label, not aria-label: Mantine would copy that onto the suggestion list too.
                  label={t('task:actions.aiUsage.providerRow', { number: index + 1 })}
                  classNames={{ label: 'ai-usage__label' }}
                  placeholder={t('task:actions.aiUsage.provider')}
                  data={names}
                  value={input.value}
                  onChange={input.onChange}
                  onBlur={input.onBlur}
                  disabled={disabled}
                  autoComplete="off"
                  error={
                    fieldState.error?.message === undefined
                      ? undefined
                      : translateIssue(t, fieldState.error.message)
                  }
                />
              )}
            />
            <Controller
              control={control}
              name={`aiUsage.${index}.credits`}
              render={({ field: input, fieldState }) => (
                <TextInput
                  className="ai-usage__credits"
                  ref={input.ref}
                  aria-label={t('task:actions.aiUsage.creditsRow', { number: index + 1 })}
                  placeholder={t('task:actions.aiUsage.credits')}
                  inputMode="decimal"
                  autoComplete="off"
                  spellCheck={false}
                  value={input.value}
                  onChange={input.onChange}
                  onBlur={input.onBlur}
                  disabled={disabled}
                  error={
                    fieldState.error?.message === undefined
                      ? undefined
                      : translateIssue(t, fieldState.error.message)
                  }
                />
              )}
            />
            <CloseButton
              className="ai-usage__remove"
              aria-label={t('task:actions.aiUsage.remove', { number: index + 1 })}
              disabled={disabled}
              onClick={() => remove(index)}
            />
          </div>
        ))}
        <Button
          variant="default"
          className="ai-usage__add"
          disabled={disabled || fields.length >= LIMITS.aiLines}
          onClick={() => append({ provider: '', credits: '' }, { shouldFocus: true })}
        >
          {t('task:actions.aiUsage.add')}
        </Button>
      </div>
    </Input.Wrapper>
  );
}

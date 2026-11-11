import { zodResolver } from '@hookform/resolvers/zod';
import {
  Alert,
  Button,
  Center,
  Group,
  Input,
  Loader,
  NumberInput,
  SegmentedControl,
  Select,
  Stack,
  Text,
  TextInput,
  Textarea,
} from '@mantine/core';
import { DateTimePicker } from '@mantine/dates';
import dayjs from 'dayjs';
import { useRef, useState } from 'react';
import { Controller, get, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { ApiRequestError } from '../../../api/ApiRequestError';
import { translateApiError } from '../../../i18n/errors';
import { useOptions } from '../../tasks/api';
import { GeoSelect } from '../../tasks/GeoSelect';
import { LIMITS, translateIssue } from '../../tasks/schema';
import { ActionModal } from './ActionModal';
import { useEditBrief } from './api';
import { briefChanges, briefFormValues, editBriefSchema } from './editBrief';
import { mapEditBriefError } from './serverErrors';
import { useActionFlow } from './useActionFlow';

/** @typedef {import('./editBrief').BriefFormValues} BriefFormValues */

/**
 * The form of the dialog, once the options are loaded. It starts from the task as it was when the
 * dialog opened, so a refetch does not overwrite what is being typed.
 * @param {{
 *   task: import('../api').TaskDetail,
 *   options: import('../../tasks/api').Options,
 *   onClose: () => void,
 *   edit: ReturnType<typeof useEditBrief>,
 * }} props
 */
function EditBriefForm({ task, options, onClose, edit }) {
  const { t } = useTranslation(['task', 'common', 'errors']);
  const flow = useActionFlow({ onClose, success: t('task:actions.editBrief.success') });
  const [original] = useState(() => ({ task, values: briefFormValues(task) }));
  const [schema] = useState(() => editBriefSchema(original.values.deadline));
  // The picker offers dates from now (or the current deadline, if that has passed) to ten years on.
  const [dateBounds] = useState(() => {
    const now = Date.now();
    const current = Date.parse(task.deadlineAt);
    return {
      min: new Date(Math.min(now, Number.isNaN(current) ? now : current)),
      max: dayjs(now).add(LIMITS.deadlineYears, 'year').toDate(),
    };
  });
  const sending = useRef(false);

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm({
    resolver: /** @type {any} */ (zodResolver(schema)),
    defaultValues: original.values,
  });

  /** @param {string} name @returns {string | undefined} */
  function errorOf(name) {
    const message = get(errors, `${name}.message`);
    return typeof message === 'string' ? translateIssue(t, message) : undefined;
  }

  /** @param {BriefFormValues} values */
  async function onSubmit(values) {
    const changes = briefChanges(original.task, values);
    if (Object.keys(changes).length === 0) {
      onClose();
      return;
    }
    await flow.run(() => edit.mutateAsync(changes), {
      onInvalid: (error) => {
        const { fields, form } = mapEditBriefError(error);
        fields.forEach(({ name, message }, index) => {
          setError(
            /** @type {any} */ (name),
            { type: 'server', message },
            { shouldFocus: index === 0 },
          );
        });
        return form;
      },
    });
  }

  /**
   * One request at a time, even for a second click that arrives before the button is disabled.
   * @param {import('react').FormEvent<HTMLFormElement>} event
   */
  function submit(event) {
    if (sending.current) {
      event.preventDefault();
      return;
    }
    sending.current = true;
    void handleSubmit(onSubmit)(event).finally(() => {
      sending.current = false;
    });
  }

  /** @param {string} code */
  const creativeTypeLabel = (code) => t(`task:creativeTypes.${code}`, { defaultValue: code });

  return (
    <form onSubmit={submit} noValidate>
      <Stack gap="md">
        {flow.error !== null && (
          <Alert color="red" variant="light" role="alert">
            {flow.error}
          </Alert>
        )}
        <TextInput
          data-autofocus
          label={t('task:create.offer.label')}
          withAsterisk
          autoComplete="off"
          error={errorOf('offer')}
          {...register('offer')}
        />
        <Controller
          name="creativeType"
          control={control}
          render={({ field }) => (
            <Select
              ref={field.ref}
              label={t('task:create.creativeType.label')}
              withAsterisk
              allowDeselect={false}
              data={options.creativeTypes.map((code) => ({
                value: code,
                label: creativeTypeLabel(code),
              }))}
              value={field.value === '' ? null : field.value}
              onChange={(value) => field.onChange(value ?? '')}
              onBlur={field.onBlur}
              error={errorOf('creativeType')}
            />
          )}
        />
        <Controller
          name="quantity"
          control={control}
          render={({ field }) => (
            <NumberInput
              ref={field.ref}
              label={t('task:create.quantity.label')}
              withAsterisk
              clampBehavior="none"
              allowDecimal={false}
              min={LIMITS.quantity.min}
              max={LIMITS.quantity.max}
              value={field.value}
              onChange={field.onChange}
              onBlur={field.onBlur}
              error={errorOf('quantity')}
            />
          )}
        />
        <Controller
          name="priority"
          control={control}
          render={({ field }) => (
            <Input.Wrapper
              labelElement="div"
              label={t('task:create.priority.label')}
              withAsterisk
              error={errorOf('priority')}
            >
              <SegmentedControl
                fullWidth
                mt={4}
                aria-label={t('task:create.priority.label')}
                data={options.priorities.map((code) => ({
                  value: code,
                  label: t(`task:priorities.${code}`, { defaultValue: code }),
                }))}
                value={field.value}
                onChange={field.onChange}
              />
            </Input.Wrapper>
          )}
        />
        <Controller
          name="geo"
          control={control}
          render={({ field }) => (
            <GeoSelect
              ref={field.ref}
              regions={options.regions}
              countries={options.countries}
              value={field.value}
              onChange={field.onChange}
              onBlur={field.onBlur}
              error={errorOf('geo')}
            />
          )}
        />
        <Textarea
          label={t('task:create.description.label')}
          withAsterisk
          autosize
          minRows={4}
          maxRows={12}
          error={errorOf('description')}
          {...register('description')}
        />
        <Controller
          name="deadline"
          control={control}
          render={({ field }) => (
            <DateTimePicker
              ref={field.ref}
              label={t('task:create.deadline.label')}
              placeholder={t('task:create.deadline.placeholder')}
              withAsterisk
              valueFormat={t('task:create.deadline.format')}
              minDate={dateBounds.min}
              maxDate={dateBounds.max}
              timePickerProps={{
                hoursInputLabel: t('task:create.deadline.hours'),
                minutesInputLabel: t('task:create.deadline.minutes'),
              }}
              submitButtonProps={{ 'aria-label': t('task:create.deadline.confirm') }}
              value={field.value}
              onChange={field.onChange}
              onBlur={field.onBlur}
              error={errorOf('deadline')}
            />
          )}
        />
        <Text size="sm" c="dimmed">
          {t('task:actions.editBrief.referencesNote')}
        </Text>
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={edit.isPending}>
            {t('common:cancel')}
          </Button>
          <Button
            type="submit"
            variant="gradient"
            loading={edit.isPending}
            disabled={edit.isPending}
          >
            {t('task:actions.editBrief.save')}
          </Button>
        </Group>
      </Stack>
    </form>
  );
}

/**
 * The buyer who created the task corrects its brief. Everything but the references can be changed
 * (the API does not accept them on an edit); only the fields that changed are sent, and nothing
 * at all when nothing changed. Every change is recorded in the activity history.
 * @param {{task: import('../api').TaskDetail, onClose: () => void}} props
 */
export function EditBriefDialog({ task, onClose }) {
  const { t } = useTranslation(['task', 'common', 'errors']);
  const options = useOptions();
  const edit = useEditBrief(task.id);

  let content;
  if (options.isPending) {
    content = (
      <Center py="xl" role="status" aria-label={t('common:loading')}>
        <Loader />
      </Center>
    );
  } else if (options.isError) {
    content = (
      <Alert color="red" variant="light" role="alert">
        <Stack gap="xs" align="flex-start">
          <span>
            {options.error instanceof ApiRequestError
              ? translateApiError(t, options.error)
              : t('errors:unknown')}
          </span>
          <Button variant="default" size="xs" onClick={() => void options.refetch()}>
            {t('common:retry')}
          </Button>
        </Stack>
      </Alert>
    );
  } else {
    content = <EditBriefForm task={task} options={options.data} onClose={onClose} edit={edit} />;
  }

  return (
    <ActionModal
      title={t('task:actions.editBrief.title')}
      pending={edit.isPending}
      onClose={onClose}
      size="lg"
    >
      {content}
    </ActionModal>
  );
}

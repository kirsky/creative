import { zodResolver } from '@hookform/resolvers/zod';
import {
  Alert,
  Button,
  Center,
  CloseButton,
  Input,
  Loader,
  NumberInput,
  SegmentedControl,
  Select,
  Stack,
  Text,
  TextInput,
  Textarea,
  Title,
} from '@mantine/core';
import { DateTimePicker } from '@mantine/dates';
import dayjs from 'dayjs';
import { useRef, useState } from 'react';
import { Controller, get, useFieldArray, useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { ApiRequestError } from '../../api/ApiRequestError';
import { translateApiError, translateFieldError } from '../../i18n/errors';
import { localDateTimeToUtcIso } from '../../i18n/format';
import { useCreateTask, useOptions } from './api';
import './CreateTaskPage.scss';
import { FileUploader } from './FileUploader';
import { attachableFileCount, failedFileCount, isUploading, MAX_REFERENCES } from './files';
import { GeoSelect } from './GeoSelect';
import {
  defaultDeadline,
  issueMessage,
  LIMITS,
  parseLocalDateTime,
  quantityValue,
  taskSchema,
  translateIssue,
} from './schema';
import { mapCreateTaskError } from './serverErrors';

/** @typedef {import('./schema').TaskFormValues} TaskFormValues */
/** @typedef {import('./serverErrors').ReferenceSource} ReferenceSource */

/**
 * The block of links and files has no input of its own, so its problems are kept under this name.
 * @type {any}
 */
const REFERENCES_ERROR = 'references';

/** @returns {TaskFormValues} */
function initialValues() {
  return {
    offer: '',
    creativeType: '',
    quantity: 1,
    priority: 'normal',
    geo: [],
    description: '',
    deadline: defaultDeadline(Date.now()),
    urls: [],
  };
}

/**
 * The references of the request: the links (blank rows left out), then the uploaded files. Files
 * that failed to upload are simply not sent; the form tells the user how many there are.
 * @param {TaskFormValues['urls']} urls
 * @param {import('./files').UploadItem[]} files
 * @returns {{references: import('./api').NewTask['references'], sources: ReferenceSource[]}}
 */
function buildReferences(urls, files) {
  /** @type {import('./api').NewTask['references']} */
  const references = [];
  /** @type {ReferenceSource[]} */
  const sources = [];
  urls.forEach(({ value }, row) => {
    const url = value.trim();
    if (url !== '') {
      references.push({ type: 'url', url });
      sources.push({ kind: 'url', row });
    }
  });
  for (const item of files) {
    if (item.status === 'uploaded' && item.id !== undefined) {
      references.push({ type: 'file', fileId: item.id });
      sources.push({ kind: 'file', key: item.key });
    }
  }
  return { references, sources };
}

/**
 * What the form shows in its alert: field problems that belong to no input, or a failed call.
 * @typedef {object} FormFailure
 * @property {unknown} error
 * @property {import('../../api/ApiRequestError').FieldError[]} issues
 */

/**
 * @param {import('i18next').TFunction} t
 * @param {FormFailure} failure
 * @returns {string[]}
 */
function failureMessages(t, { error, issues }) {
  if (issues.length > 0) {
    return [...new Set(issues.map((issue) => translateFieldError(t, issue)))];
  }
  return [error instanceof ApiRequestError ? translateApiError(t, error) : t('errors:unknown')];
}

/** @param {{options: import('./api').Options}} props */
function CreateTaskForm({ options }) {
  const { t } = useTranslation(['task', 'common', 'errors']);
  const createTask = useCreateTask();
  const pending = useRef(false);
  const [defaults] = useState(initialValues);
  // The picker offers no date before opening the form or more than ten years after it.
  const [dateBounds] = useState(() => ({
    min: new Date(Date.now()),
    max: dayjs(Date.now()).add(LIMITS.deadlineYears, 'year').toDate(),
  }));
  const [files, setFiles] = useState(/** @type {import('./files').UploadItem[]} */ ([]));
  const [failure, setFailure] = useState(/** @type {FormFailure | null} */ (null));

  const {
    register,
    control,
    handleSubmit,
    setError,
    clearErrors,
    formState: { errors, isSubmitting },
  } = useForm({
    resolver: /** @type {any} */ (zodResolver(taskSchema)),
    defaultValues: defaults,
  });
  const urls = useFieldArray({ control, name: 'urls' });

  const uploading = isUploading(files);
  const referenceCount = urls.fields.length + attachableFileCount(files);
  const failedFiles = failedFileCount(files);

  /** @param {string} name @returns {string | undefined} */
  function errorOf(name) {
    const message = get(errors, `${name}.message`);
    return typeof message === 'string' ? translateIssue(t, message) : undefined;
  }

  const referencesError =
    errorOf('references') ?? errorOf('urls') ?? errorOf('urls.root') ?? undefined;

  /** @param {TaskFormValues} values */
  async function onSubmit(values) {
    setFailure(null);
    const { references, sources } = buildReferences(values.urls, files);
    const deadline = parseLocalDateTime(values.deadline);
    if (references.length > MAX_REFERENCES || deadline === null) {
      setError(REFERENCES_ERROR, {
        type: 'client',
        message: issueMessage('too_many', LIMITS.references),
      });
      return;
    }
    try {
      await createTask.mutateAsync({
        offer: values.offer.trim(),
        geo: values.geo,
        creativeType: values.creativeType,
        quantity: quantityValue(values.quantity),
        description: values.description,
        priority: values.priority,
        deadline: localDateTimeToUtcIso(deadline),
        references,
      });
    } catch (error) {
      if (!(error instanceof ApiRequestError) || error.code !== 'validation_failed') {
        setFailure({ error, issues: [] });
        return;
      }
      const { fields, form } = mapCreateTaskError(error, sources);
      fields.forEach(({ name, message }, index) => {
        setError(
          /** @type {any} */ (name),
          { type: 'server', message },
          { shouldFocus: index === 0 },
        );
      });
      if (fields.length === 0 || form.length > 0) {
        setFailure({ error, issues: form });
      }
    }
  }

  /**
   * One request at a time, even for submits that arrive before the disabled button has rendered,
   * and none while a file is still on its way (its id is not known yet).
   * @param {import('react').SyntheticEvent<HTMLFormElement>} event
   */
  function handleFormSubmit(event) {
    if (pending.current || uploading) {
      event.preventDefault();
      return;
    }
    pending.current = true;
    void handleSubmit(onSubmit)(event).finally(() => {
      pending.current = false;
    });
  }

  /** @param {string} code */
  const creativeTypeLabel = (code) => t(`task:creativeTypes.${code}`, { defaultValue: code });

  const alertMessages = failure === null ? [] : failureMessages(t, failure);

  return (
    <form className="create-task__card" onSubmit={handleFormSubmit} noValidate>
      <Stack gap="xl">
        <Title order={1} size="h2">
          {t('task:create.title')}
        </Title>
        {alertMessages.length > 0 && (
          <Alert color="red" variant="light" role="alert">
            {alertMessages.map((message) => (
              <div key={message}>{message}</div>
            ))}
          </Alert>
        )}

        <section className="create-task__section">
          <Title order={2} size="h4">
            {t('task:create.sections.basics')}
          </Title>
          <TextInput
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
                placeholder={t('task:create.creativeType.placeholder')}
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
                // A number out of range is an error to show, not something to fix silently.
                clampBehavior="none"
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
        </section>

        <section className="create-task__section">
          <Title order={2} size="h4">
            {t('task:create.sections.target')}
          </Title>
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
        </section>

        <section className="create-task__section">
          <Title order={2} size="h4">
            {t('task:create.sections.details')}
          </Title>
          <Textarea
            label={t('task:create.description.label')}
            withAsterisk
            autosize
            minRows={4}
            maxRows={14}
            error={errorOf('description')}
            {...register('description')}
          />
        </section>

        <section className="create-task__section">
          <Title order={2} size="h4">
            {t('task:create.sections.deadline')}
          </Title>
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
        </section>

        <section className="create-task__section">
          <Title order={2} size="h4">
            {t('task:create.sections.references')}
          </Title>
          {referencesError !== undefined && (
            <Text size="sm" c="red">
              {referencesError}
            </Text>
          )}
          <Input.Wrapper labelElement="div" label={t('task:create.references.linksLabel')}>
            <div className="create-task__links">
              {urls.fields.map((item, index) => (
                <div key={item.id} className="create-task__link-row">
                  <TextInput
                    className="create-task__link-input"
                    aria-label={t('task:create.references.link', { number: index + 1 })}
                    placeholder={t('task:create.references.linkPlaceholder')}
                    inputMode="url"
                    autoComplete="off"
                    spellCheck={false}
                    error={errorOf(`urls.${index}.value`)}
                    {...register(`urls.${index}.value`)}
                  />
                  <CloseButton
                    mt={4}
                    aria-label={t('task:create.references.removeLink', { number: index + 1 })}
                    onClick={() => {
                      urls.remove(index);
                      clearErrors(REFERENCES_ERROR);
                    }}
                  />
                </div>
              ))}
              <Button
                variant="default"
                className="create-task__add-link"
                disabled={referenceCount >= MAX_REFERENCES}
                onClick={() => urls.append({ value: '' }, { shouldFocus: true })}
              >
                {t('task:create.references.addLink')}
              </Button>
            </div>
          </Input.Wrapper>
          <FileUploader
            value={files}
            onChange={(update) => {
              clearErrors(REFERENCES_ERROR);
              setFiles(update);
            }}
            maxFiles={MAX_REFERENCES - urls.fields.length}
          />
        </section>

        <div className="create-task__actions">
          {failedFiles > 0 && (
            <Text size="sm" c="dimmed" className="create-task__notice">
              {t('task:create.references.failedNotice', { count: failedFiles })}
            </Text>
          )}
          <Button component={Link} to="/board" variant="default">
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={uploading} loading={isSubmitting || uploading}>
            {t('task:create.submit')}
          </Button>
        </div>
      </Stack>
    </form>
  );
}

/**
 * The page of a buyer's brief (route /tasks/new, buyers only): loads the options, then shows the
 * form. Creating the task opens its page.
 */
export function CreateTaskPage() {
  const { t } = useTranslation(['common', 'errors']);
  const options = useOptions();

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
    content = <CreateTaskForm options={options.data} />;
  }
  return <div className="create-task">{content}</div>;
}

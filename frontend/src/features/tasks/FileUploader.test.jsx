import { MantineProvider } from '@mantine/core';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { setCsrfToken } from '../../api/client';
import { errorResponse, installFetch, mockResponse } from '../../test/http';
import { FileUploader } from './FileUploader';
import { failedFileCount, isUploading, uploadedFileIds } from './files';

/** @param {string} name @param {number} [size] */
function makeFile(name, size = 3) {
  const file = new File(['abc'], name, { type: 'image/png' });
  if (size !== 3) {
    Object.defineProperty(file, 'size', { value: size });
  }
  return file;
}

/** @param {{maxFiles?: number}} props */
function Harness({ maxFiles }) {
  const [items, setItems] = useState(/** @type {import('./files').UploadItem[]} */ ([]));
  return (
    <MantineProvider>
      <FileUploader value={items} onChange={setItems} maxFiles={maxFiles} />
      <output data-testid="ids">{uploadedFileIds(items).join(',')}</output>
      <output data-testid="busy">{String(isUploading(items))}</output>
      <output data-testid="failed">{failedFileCount(items)}</output>
    </MantineProvider>
  );
}

const fileInput = () =>
  /** @type {HTMLInputElement} */ (document.querySelector('input[type="file"]'));
const row = (/** @type {string} */ name) =>
  /** @type {HTMLElement} */ (screen.getByText(name).closest('li'));

beforeEach(() => {
  setCsrfToken('c'.repeat(64));
});

describe('FileUploader', () => {
  it('uploads at once, one request per file in turn, and reports each status', async () => {
    /** @type {Array<() => void>} */
    const releases = [];
    let id = 0;
    const fetchMock = installFetch({
      'POST /api/files': () =>
        new Promise((resolve) => {
          id += 1;
          const mine = id;
          releases.push(() =>
            resolve(mockResponse(201, { id: mine, name: `f${mine}`, mime: 'image/png', size: 3 })),
          );
        }),
    });
    render(<Harness />);

    await userEvent.upload(fileInput(), [makeFile('a.png'), makeFile('b.png')]);

    // The first file is on its way, the second waits for it.
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(within(row('a.png')).getByText('Uploading')).toBeInTheDocument();
    expect(within(row('b.png')).getByText('Waiting')).toBeInTheDocument();
    expect(screen.getByTestId('busy')).toHaveTextContent('true');

    await act(async () => releases[0]());
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(within(row('a.png')).getByText('Uploaded')).toBeInTheDocument();

    await act(async () => releases[1]());
    expect(await within(row('b.png')).findByText('Uploaded')).toBeInTheDocument();
    expect(screen.getByTestId('ids')).toHaveTextContent('1,2');
    expect(screen.getByTestId('busy')).toHaveTextContent('false');

    for (const call of fetchMock.mock.calls) {
      const body = /** @type {FormData} */ (call[1].body);
      expect([...body.keys()]).toEqual(['file']);
    }
    expect(
      /** @type {File} */ (/** @type {FormData} */ (fetchMock.mock.calls[0][1].body).get('file'))
        .name,
    ).toBe('a.png');
  });

  it('rejects a file over 99 MB without a request', async () => {
    const fetchMock = installFetch({});
    render(<Harness />);
    await userEvent.upload(fileInput(), makeFile('big.png', 99_000_001));
    expect(within(row('big.png')).getByText(/maximum size is 99 MB/)).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByTestId('failed')).toHaveTextContent('1');
  });

  it('accepts a file of exactly 99 MB', async () => {
    const fetchMock = installFetch({
      'POST /api/files': mockResponse(201, {
        id: 4,
        name: 'ok.png',
        mime: 'image/png',
        size: 99000000,
      }),
    });
    render(<Harness />);
    await userEvent.upload(fileInput(), makeFile('ok.png', 99_000_000));
    expect(await within(row('ok.png')).findByText('Uploaded')).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it.each([
    [
      'file_too_large (server)',
      errorResponse(413, 'file_too_large', { params: { maxBytes: 99000000 } }),
      /99 MB/,
    ],
    [
      'an nginx HTML 413',
      mockResponse(413, '<html>413 Request Entity Too Large</html>'),
      /upload is too large/,
    ],
    [
      'file_type_not_allowed',
      errorResponse(422, 'file_type_not_allowed'),
      /type of file is not allowed/,
    ],
    ['file_empty', errorResponse(422, 'file_empty'), /file is empty/],
    ['a server error', errorResponse(500, 'internal_error'), /went wrong on our side/],
  ])('shows %s next to the file and keeps going', async (_name, response, message) => {
    installFetch({ 'POST /api/files': () => response });
    render(<Harness />);
    await userEvent.upload(fileInput(), makeFile('bad.png'));
    expect(await within(row('bad.png')).findByText(message)).toBeInTheDocument();
    expect(screen.getByTestId('ids')).toHaveTextContent('');
    expect(screen.getByTestId('busy')).toHaveTextContent('false');
    expect(screen.getByTestId('failed')).toHaveTextContent('1');
  });

  it('an errored file does not stop the next one and is not among the ids', async () => {
    let call = 0;
    installFetch({
      'POST /api/files': () => {
        call += 1;
        return call === 1
          ? errorResponse(422, 'file_empty')
          : mockResponse(201, { id: 9, name: 'b.png', mime: 'image/png', size: 3 });
      },
    });
    render(<Harness />);
    await userEvent.upload(fileInput(), [makeFile('a.png'), makeFile('b.png')]);
    expect(await within(row('b.png')).findByText('Uploaded')).toBeInTheDocument();
    expect(screen.getByTestId('ids')).toHaveTextContent('9');
    expect(screen.getByTestId('failed')).toHaveTextContent('1');
  });

  it('removing a file only detaches it, and aborts an upload in flight', async () => {
    /** @type {() => void} */
    let release = () => {};
    const fetchMock = installFetch({
      'POST /api/files': () =>
        new Promise((resolve) => {
          release = () =>
            resolve(mockResponse(201, { id: 3, name: 'a.png', mime: 'image/png', size: 3 }));
        }),
    });
    render(<Harness />);
    await userEvent.upload(fileInput(), makeFile('a.png'));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    await userEvent.click(screen.getByRole('button', { name: 'Remove a.png' }));

    expect(screen.queryByText('a.png')).not.toBeInTheDocument();
    expect(fetchMock.mock.calls[0][1]?.signal?.aborted).toBe(true);
    await act(async () => release());
    expect(screen.queryByText('a.png')).not.toBeInTheDocument();
    expect(screen.getByTestId('ids')).toHaveTextContent('');
    expect(screen.getByTestId('busy')).toHaveTextContent('false');
    // No DELETE request exists: removing is client-side only.
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('removing an uploaded file takes its id out', async () => {
    installFetch({
      'POST /api/files': mockResponse(201, { id: 3, name: 'a.png', mime: 'image/png', size: 3 }),
    });
    render(<Harness />);
    await userEvent.upload(fileInput(), makeFile('a.png'));
    await within(row('a.png')).findByText('Uploaded');
    await userEvent.click(screen.getByRole('button', { name: 'Remove a.png' }));
    expect(screen.getByTestId('ids')).toHaveTextContent('');
  });

  it('refuses files beyond the room left, without a request for them', async () => {
    let id = 0;
    const fetchMock = installFetch({
      'POST /api/files': () => {
        id += 1;
        return mockResponse(201, { id, name: 'x', mime: 'image/png', size: 3 });
      },
    });
    render(<Harness maxFiles={2} />);
    await userEvent.upload(fileInput(), [makeFile('a.png'), makeFile('b.png'), makeFile('c.png')]);
    expect(await within(row('c.png')).findByText('No more than 2 items')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByTestId('ids')).toHaveTextContent('1,2'));
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('shows the size limit and sizes in the language of the page', async () => {
    installFetch({
      'POST /api/files': mockResponse(201, {
        id: 1,
        name: 'a.png',
        mime: 'image/png',
        size: 1500000,
      }),
    });
    render(<Harness />);
    expect(screen.getByText(/99 MB/)).toBeInTheDocument();
    await userEvent.upload(fileInput(), makeFile('a.png', 1_500_000));
    expect(within(row('a.png')).getByText('1.5 MB')).toBeInTheDocument();
  });
});

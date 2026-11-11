<?php

declare(strict_types=1);

namespace App\Tests\Files;

use App\Identity\Role;
use App\Identity\User;
use App\Files\Http\UploadAction;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

use function DI\autowire;

/**
 * POST /api/files: what is stored, where, under which name, and what is refused. The assertions look
 * at the real upload directory and the real files table, not only at the response.
 */
final class UploadTest extends ApiTestCase
{
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->createUser('anna', Role::Buyer);
    }

    public function test_png_upload_returns_201_and_file_is_outside_web_root(): void
    {
        $png = Samples::png();

        $response = $this->upload($png, 'banner.png', as: $this->buyer);

        self::assertSame(201, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        $body = self::decodeJson($response);
        self::assertIsArray($body);
        self::assertSame(['id', 'name', 'mime', 'size'], array_keys($body));
        self::assertSame('banner.png', $body['name']);
        self::assertSame('image/png', $body['mime']);
        self::assertSame(strlen($png), $body['size']);

        $stored = $this->storedFiles();
        self::assertCount(1, $stored);
        $path = $stored[0];

        // UPLOAD_DIR/<yyyy>/<mm>/<random id>, on the volume and nowhere near a document root.
        self::assertMatchesRegularExpression('~^' . preg_quote($this->uploadDir, '~') . '/2026/01/[0-9a-f]{32}$~', $path);
        $publicDirectory = realpath(__DIR__ . '/../../public');
        self::assertIsString($publicDirectory);
        self::assertFalse(str_starts_with($path, $publicDirectory . '/'));
        self::assertFalse(str_starts_with($this->uploadDir, '/usr/share/nginx/html'));

        self::assertSame($png, file_get_contents($path));
        self::assertSame('0640', substr(sprintf('%o', fileperms($path)), -4));
        self::assertSame('0750', substr(sprintf('%o', fileperms(dirname($path))), -4));
        self::assertSame('0750', substr(sprintf('%o', fileperms(dirname($path, 2))), -4));

        self::assertSame(
            [[
                'id' => $body['id'],
                'uploader_id' => $this->buyer->id,
                'name' => 'banner.png',
                'mime' => 'image/png',
                'size' => strlen($png),
                'path' => '2026/01/' . basename($path),
                'created_at' => '2026-01-15 09:00:00',
            ]],
            $this->fetchRows('SELECT id, uploader_id, name, mime, size, path, created_at FROM files'),
        );
    }

    public function test_php_source_named_evil_png_is_rejected(): void
    {
        $response = $this->upload(Samples::phpSource(), 'evil.png', as: $this->buyer, clientType: 'image/png');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('{"error":{"code":"file_type_not_allowed","fields":[]}}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
        $this->assertNothingStored();
    }

    public function test_real_png_named_exe_is_accepted_and_stored_as_png(): void
    {
        $png = Samples::png();

        $response = $this->upload($png, 'x.exe', as: $this->buyer, clientType: 'application/x-msdownload');

        self::assertSame(201, $response->getStatusCode());
        $body = self::decodeJson($response);
        self::assertIsArray($body);
        self::assertSame('image/png', $body['mime']);
        self::assertSame('x.exe', $body['name']);
        self::assertSame('image/png', $this->fetchValue('SELECT mime FROM files'));
        self::assertSame($png, file_get_contents($this->storedFiles()[0]));
    }

    public function test_the_client_media_type_is_not_believed(): void
    {
        $response = $this->upload(Samples::html(), 'page.png', as: $this->buyer, clientType: 'image/png');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('file_type_not_allowed', $this->errorCode($response));
    }

    #[DataProvider('refusedContents')]
    public function test_types_outside_the_allow_list_are_rejected(string $contents, string $clientName): void
    {
        $response = $this->upload($contents, $clientName, as: $this->buyer);

        self::assertSame(422, $response->getStatusCode(), $clientName);
        self::assertSame('{"error":{"code":"file_type_not_allowed","fields":[]}}', (string) $response->getBody());
        $this->assertNothingStored();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedContents(): array
    {
        return [
            'svg, which can carry script' => [Samples::svg(), 'logo.svg'],
            'svg named like a png' => [Samples::svg(), 'logo.png'],
            'svg with an xml prolog' => ["<?xml version=\"1.0\"?>\n" . Samples::svg(), 'logo.svg'],
            'html' => [Samples::html(), 'page.html'],
            'html named like a pdf' => [Samples::html(), 'brief.pdf'],
            'plain text' => ["just some words\n", 'notes.txt'],
            'unknown binary (octet-stream)' => ["\x01\x02\x03\xfe\xfd\xfc" . str_repeat("\x9a", 100), 'data.bin'],
            'windows executable' => ['MZ' . str_repeat("\0", 60), 'setup.exe'],
            'php source' => [Samples::phpSource(), 'shell.php'],
        ];
    }

    #[DataProvider('allowedContents')]
    public function test_every_allowed_type_is_accepted_by_content(string $contents, string $expectedMime): void
    {
        // The name says nothing: the type comes from the bytes.
        $response = $this->upload($contents, 'upload', as: $this->buyer);

        self::assertSame(201, $response->getStatusCode(), $expectedMime);
        $body = self::decodeJson($response);
        self::assertIsArray($body);
        self::assertSame($expectedMime, $body['mime']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allowedContents(): array
    {
        return [
            'png' => [Samples::png(), 'image/png'],
            'jpeg' => [Samples::jpeg(), 'image/jpeg'],
            'gif' => [Samples::gif(), 'image/gif'],
            'webp' => [Samples::webp(), 'image/webp'],
            'pdf' => [Samples::pdf(), 'application/pdf'],
            'zip (and so .fig, .sketch, .xd)' => [Samples::zip(), 'application/zip'],
            'photoshop' => [Samples::photoshop(), 'image/vnd.adobe.photoshop'],
            'mp4 video' => [Samples::mp4(), 'video/mp4'],
            'webm video' => [Samples::webm(), 'video/webm'],
            'mp3 audio' => [Samples::mp3(), 'audio/mpeg'],
            'wav audio' => [Samples::wav(), 'audio/x-wav'],
        ];
    }

    public function test_zero_byte_file_is_rejected(): void
    {
        $response = $this->upload('', 'empty.png', as: $this->buyer);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('{"error":{"code":"file_empty","fields":[]}}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
        $this->assertNothingStored();
    }

    public function test_file_over_limit_is_413_file_too_large(): void
    {
        $this->bootApp($this->config->with(['MAX_UPLOAD_BYTES' => '1024']));

        $response = $this->upload(Samples::pngOfLength(1025), 'big.png', as: $this->buyer);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('{"error":{"code":"file_too_large","fields":[],"params":{"maxBytes":1024}}}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
        $this->assertNothingStored();
    }

    public function test_a_file_of_exactly_the_limit_is_accepted(): void
    {
        $this->bootApp($this->config->with(['MAX_UPLOAD_BYTES' => '1024']));

        $response = $this->upload(Samples::pngOfLength(1024), 'edge.png', as: $this->buyer);

        self::assertSame(201, $response->getStatusCode());
        $body = self::decodeJson($response);
        self::assertIsArray($body);
        self::assertSame(1024, $body['size']);
    }

    public function test_the_limit_is_enforced_on_the_bytes_and_not_on_the_size_the_client_reported(): void
    {
        $this->bootApp($this->config->with(['MAX_UPLOAD_BYTES' => '1024']));

        $liar = $this->uploadedFile(Samples::pngOfLength(5000), 'big.png', size: 10);
        $response = $this->uploadFiles(['file' => $liar], $this->buyer);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('file_too_large', $this->errorCode($response));
        $this->assertNothingStored();
    }

    public function test_the_size_stored_is_the_number_of_bytes_written(): void
    {
        $png = Samples::pngOfLength(700);

        $response = $this->uploadFiles(['file' => $this->uploadedFile($png, 'a.png', size: 5)], $this->buyer);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(700, $this->fetchValue('SELECT size FROM files'));
        self::assertSame(700, filesize($this->storedFiles()[0]));
    }

    public function test_hostile_and_long_names_are_stored_safely(): void
    {
        $png = Samples::png();
        // What the client calls the file => the name that is stored.
        $names = [
            '../../x' => 'x',
            '..\\..\\windows\\system32\\x.png' => 'x.png',
            '/etc/passwd' => 'passwd',
            "evil\r\nSet-Cookie: a=b\".png" => 'evil Set-Cookie: a=b".png',
            str_repeat('я', 296) . '.png' => str_repeat('я', 251) . '.png',
            "nul\0byte.png" => 'nul byte.png',
            '..' => 'file',
            '' => 'file',
        ];

        foreach (array_keys($names) as $name) {
            self::assertSame(201, $this->upload($png, $name, as: $this->buyer)->getStatusCode(), $name);
        }

        // Nothing but <yyyy>/<mm>/<random id> files, whatever the client called them.
        $stored = $this->storedFiles();
        self::assertCount(count($names), $stored);
        foreach ($stored as $path) {
            self::assertStringNotContainsString('..', $path);
            self::assertMatchesRegularExpression('~^' . preg_quote($this->uploadDir, '~') . '/2026/01/[0-9a-f]{32}$~', $path);
        }
        self::assertSame([], array_values(array_diff(scandir($this->workDir) ?: [], ['.', '..', 'uploads', 'incoming'])));

        $saved = array_column($this->fetchRows('SELECT name FROM files ORDER BY id'), 'name');
        self::assertSame(array_values($names), $saved);
        foreach ($saved as $name) {
            self::assertLessThanOrEqual(255, mb_strlen($name));
            self::assertDoesNotMatchRegularExpression('~[\p{Cc}/\\\\]~u', $name);
        }
    }

    public function test_a_long_cyrillic_name_is_cut_to_255_characters_and_keeps_its_extension(): void
    {
        $response = $this->upload(Samples::png(), str_repeat('ж', 296) . '.png', as: $this->buyer);

        self::assertSame(201, $response->getStatusCode());
        $body = self::decodeJson($response);
        self::assertIsArray($body);
        self::assertIsString($body['name']);
        self::assertSame(255, mb_strlen($body['name']));
        self::assertSame(str_repeat('ж', 251) . '.png', $body['name']);
        self::assertTrue(mb_check_encoding($body['name'], 'UTF-8'));
        $this->assertMatchesSpec($response);
        self::assertSame($body['name'], $this->fetchValue('SELECT name FROM files'));
    }

    public function test_the_same_bytes_uploaded_twice_are_two_files(): void
    {
        $png = Samples::png();

        $first = self::decodeJson($this->upload($png, 'a.png', as: $this->buyer));
        $second = self::decodeJson($this->upload($png, 'a.png', as: $this->buyer));

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertNotSame($first['id'], $second['id']);
        self::assertCount(2, array_unique($this->storedFiles()));
        self::assertSame(2, $this->countRows('files'));
    }

    public function test_buyers_and_designers_can_upload_and_a_manager_cannot(): void
    {
        $designer = $this->createUser('dave', Role::Designer);
        $manager = $this->createUser('mia', Role::Manager);

        self::assertSame(201, $this->upload(Samples::png(), 'a.png', as: $this->buyer)->getStatusCode());
        self::assertSame(201, $this->upload(Samples::png(), 'b.png', as: $designer)->getStatusCode());

        $denied = $this->upload(Samples::png(), 'c.png', as: $manager);

        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('{"error":{"code":"forbidden","fields":[]}}', (string) $denied->getBody());
        $this->assertMatchesSpec($denied);
        self::assertSame(2, $this->countRows('files'));
        self::assertCount(2, $this->storedFiles());
    }

    public function test_upload_without_a_session_is_401_and_stores_nothing(): void
    {
        $response = $this->upload(Samples::png(), 'a.png');

        self::assertSame(401, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        $this->assertNothingStored();
    }

    public function test_upload_without_the_csrf_token_is_403(): void
    {
        [$token] = $this->startSession($this->buyer);
        $request = self::withSession($this->buildRequest('POST', '/api/files'), $token)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withUploadedFiles(['file' => $this->uploadedFile(Samples::png(), 'a.png')]);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
        $this->assertNothingStored();
    }

    public function test_a_request_without_the_file_field_is_422_required(): void
    {
        foreach ([[], ['document' => $this->uploadedFile(Samples::png(), 'a.png')]] as $files) {
            $response = $this->uploadFiles($files, $this->buyer);

            self::assertSame(422, $response->getStatusCode());
            self::assertSame(
                '{"error":{"code":"validation_failed","fields":[{"field":"file","code":"required","params":{}}]}}',
                (string) $response->getBody(),
            );
            $this->assertMatchesSpec($response);
        }
    }

    public function test_a_json_body_is_422_required(): void
    {
        $response = $this->request('POST', '/api/files', ['file' => 'not a file'], as: $this->buyer);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            '{"error":{"code":"validation_failed","fields":[{"field":"file","code":"required","params":{}}]}}',
            (string) $response->getBody(),
        );
    }

    public function test_only_one_file_per_request(): void
    {
        $expected = '{"error":{"code":"validation_failed","fields":[{"field":"file","code":"invalid","params":{}}]}}';

        $listed = $this->uploadFiles(['file' => [
            $this->uploadedFile(Samples::png(), 'a.png'),
            $this->uploadedFile(Samples::png(), 'b.png'),
        ]], $this->buyer);
        $extra = $this->uploadFiles([
            'file' => $this->uploadedFile(Samples::png(), 'a.png'),
            'other' => $this->uploadedFile(Samples::png(), 'b.png'),
        ], $this->buyer);

        foreach ([$listed, $extra] as $response) {
            self::assertSame(422, $response->getStatusCode());
            self::assertSame($expected, (string) $response->getBody());
            $this->assertMatchesSpec($response);
        }
        $this->assertNothingStored();
    }

    #[DataProvider('phpUploadErrors')]
    public function test_php_upload_errors_are_mapped(int $error, int $status, string $code): void
    {
        $response = $this->uploadFiles(['file' => $this->uploadedFile('', 'a.png', size: 0, error: $error)], $this->buyer);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, $this->errorCode($response));
        if ($status !== 500) {
            // No operation documents the 500 every unexpected failure answers with.
            $this->assertMatchesSpec($response);
        }
        $this->assertNothingStored();
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function phpUploadErrors(): array
    {
        return [
            'larger than upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, 413, 'file_too_large'],
            'larger than MAX_FILE_SIZE' => [UPLOAD_ERR_FORM_SIZE, 413, 'file_too_large'],
            'no file chosen' => [UPLOAD_ERR_NO_FILE, 422, 'validation_failed'],
            'interrupted' => [UPLOAD_ERR_PARTIAL, 422, 'validation_failed'],
            'no temporary directory' => [UPLOAD_ERR_NO_TMP_DIR, 500, 'internal_error'],
            'cannot write to disk' => [UPLOAD_ERR_CANT_WRITE, 500, 'internal_error'],
            'blocked by an extension' => [UPLOAD_ERR_EXTENSION, 500, 'internal_error'],
        ];
    }

    public function test_a_body_php_dropped_for_exceeding_post_max_size_is_413_file_too_large(): void
    {
        // PHP leaves $_FILES and $_POST empty when CONTENT_LENGTH is over post_max_size: the request
        // looks like one without a file, but the true answer is "too large".
        $this->bootApp($this->config, [UploadAction::class => autowire()->constructorParameter('postMaxBytes', 2048)]);

        $dropped = $this->uploadFiles([], $this->buyer, serverParams: ['CONTENT_LENGTH' => '5000']);

        self::assertSame(413, $dropped->getStatusCode());
        self::assertSame('{"error":{"code":"file_too_large","fields":[],"params":{"maxBytes":99000000}}}', (string) $dropped->getBody());
        $this->assertMatchesSpec($dropped);

        // A request within the limit that simply has no file is still the validation error.
        $missing = $this->uploadFiles([], $this->buyer, serverParams: ['CONTENT_LENGTH' => '200']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($missing));
    }

    public function test_an_upload_that_cannot_be_written_leaves_no_row_and_no_file(): void
    {
        // The directory for the month cannot be created: a file sits where the year directory belongs.
        file_put_contents($this->uploadDir . '/2026', 'in the way');

        $response = $this->upload(Samples::png(), 'a.png', as: $this->buyer);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('internal_error', $this->errorCode($response));
        self::assertSame(0, $this->countRows('files'));
        self::assertSame(['2026'], array_values(array_diff(scandir($this->uploadDir) ?: [], ['.', '..'])));
    }

    public function test_the_upload_directory_is_created_on_first_use_and_a_month_directory_reused(): void
    {
        self::assertSame([], $this->storedFiles());

        $this->upload(Samples::png(), 'a.png', as: $this->buyer);
        $this->upload(Samples::png(), 'b.png', as: $this->buyer);
        $this->clock->advance('P1M');
        $this->upload(Samples::png(), 'c.png', as: $this->buyer);

        $months = array_map(static fn(string $path): string => basename(dirname($path, 2)) . '/' . basename(dirname($path)), $this->storedFiles());
        sort($months);
        self::assertSame(['2026/01', '2026/01', '2026/02'], $months);
    }

    public function test_modes_do_not_depend_on_the_umask_of_the_process(): void
    {
        foreach ([0077, 0000] as $index => $umask) {
            $previous = umask($umask);
            try {
                $response = $this->upload(Samples::png(), 'a.png', as: $this->buyer);
            } finally {
                umask($previous);
            }

            self::assertSame(201, $response->getStatusCode());
            $path = $this->storedFiles()[$index];
            self::assertSame('0640', substr(sprintf('%o', fileperms($path)), -4), sprintf('file, umask %04o', $umask));
            self::assertSame('0750', substr(sprintf('%o', fileperms(dirname($path))), -4), sprintf('directory, umask %04o', $umask));
            // The temporary file used while writing is gone.
            self::assertSame([], glob($this->uploadDir . '/.upload-*'));
        }
    }

    public function test_the_client_name_never_reaches_the_file_system(): void
    {
        $this->upload(Samples::png(), 'unique-marker-name.png', as: $this->buyer);

        $listing = implode("\n", $this->everyPathUnder($this->uploadDir));

        self::assertStringNotContainsString('unique-marker-name', $listing);
    }

    /**
     * @return list<string> regular files under the upload directory, as full paths
     */
    private function storedFiles(): array
    {
        return array_values(array_filter($this->everyPathUnder($this->uploadDir), is_file(...)));
    }

    /**
     * @return list<string>
     */
    private function everyPathUnder(string $directory): array
    {
        $paths = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $paths[] = $directory . '/' . $entry;
            if (is_dir($directory . '/' . $entry) && !is_link($directory . '/' . $entry)) {
                $paths = [...$paths, ...$this->everyPathUnder($directory . '/' . $entry)];
            }
        }

        return $paths;
    }

    private function assertNothingStored(): void
    {
        self::assertSame(0, $this->countRows('files'));
        self::assertSame([], $this->everyPathUnder($this->uploadDir), 'Something was left in the upload directory.');
    }

    private function errorCode(ResponseInterface $response): string
    {
        $body = self::decodeJson($response);
        self::assertIsArray($body);
        self::assertIsArray($body['error'] ?? null);
        self::assertIsString($body['error']['code'] ?? null);

        return $body['error']['code'];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\AuthService;
use App\Identity\Role;
use App\Identity\UserRepository;
use App\Shared\Http\ApiError;
use App\Tests\Support\ApiTestCase;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;

final class LoginTest extends ApiTestCase
{
    /** Test-only password; it exists in this file and in the test database, nowhere else. */
    private const PASSWORD = 'test-only Лото 🎰 password';

    private const INVALID_CREDENTIALS = '{"error":{"code":"invalid_credentials","fields":[]}}';

    public function test_account_without_password_hash_cannot_login(): void
    {
        $this->createUser('anna');

        // An empty password never reaches the lookup: login demands a 1..1000 character password.
        $empty = $this->login('anna', '');
        self::assertSame(422, $empty->getStatusCode());
        self::assertSame(
            [['field' => 'password', 'code' => 'too_short', 'params' => ['min' => 1, 'max' => 1000]]],
            self::errorFields($empty),
        );
        self::assertFalse($empty->hasHeader('Set-Cookie'));
        $this->assertMatchesSpec($empty);

        // Below the validation, the service refuses '' for a NULL hash like any other password.
        try {
            $this->service(AuthService::class)->login('anna', '', '172.18.0.1');
            self::fail('A NULL hash must not accept the empty password.');
        } catch (ApiError $error) {
            self::assertSame(401, $error->status);
            self::assertSame('invalid_credentials', $error->code);
        }

        $x = $this->login('anna', 'x');
        self::assertSame(401, $x->getStatusCode());
        self::assertSame(self::INVALID_CREDENTIALS, (string) $x->getBody());
        self::assertFalse($x->hasHeader('Set-Cookie'));
        $this->assertMatchesSpec($x);

        self::assertSame(0, $this->countRows('sessions'));
    }

    public function test_unknown_user_and_wrong_password_give_identical_body(): void
    {
        $this->createUser('bob', Role::Designer, self::PASSWORD);
        $this->createUser('anna');

        $unknown = $this->login('nobody', self::PASSWORD);
        $wrong = $this->login('bob', self::PASSWORD . 'x');
        $noHash = $this->login('anna', self::PASSWORD);

        foreach ([$unknown, $wrong, $noHash] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame(self::INVALID_CREDENTIALS, (string) $response->getBody());
            self::assertSame($unknown->getHeaders(), $response->getHeaders());
            self::assertFalse($response->hasHeader('Set-Cookie'));
            $this->assertMatchesSpec($response);
        }

        self::assertSame(0, $this->countRows('sessions'));
    }

    public function test_success_sets_cookie_with_httponly_secure_samesite_lax(): void
    {
        $user = $this->createUser('bob', Role::Designer, self::PASSWORD);

        $response = $this->login('bob', self::PASSWORD);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);

        $cookies = $response->getHeader('Set-Cookie');
        self::assertCount(1, $cookies);
        $parts = array_map('trim', explode(';', $cookies[0]));
        self::assertMatchesRegularExpression('/^creative_session=[0-9a-f]{64}$/', $parts[0]);
        self::assertEqualsCanonicalizing(['Path=/', 'HttpOnly', 'Secure', 'SameSite=Lax'], array_slice($parts, 1));

        $body = self::decodeJson($response);
        self::assertIsArray($body);
        self::assertIsArray($body['user']);
        self::assertSame($user->id, $body['user']['id']);
        self::assertSame('bob', $body['user']['username']);

        // Neither the password nor its hash leaves the server, in the response or in the log.
        $hash = (string) $this->service(UserRepository::class)->findByUsername('bob')?->passwordHash;
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString($hash, (string) $response->getBody());
        self::assertStringNotContainsString('argon2', (string) $response->getBody());
        foreach ($this->logs->getRecords() as $record) {
            $line = self::render($record);
            self::assertStringNotContainsString(self::PASSWORD, $line);
            self::assertStringNotContainsString($hash, $line);
        }

        self::assertSame(1, $this->countRows('sessions'));
    }

    public function test_cookie_is_not_secure_only_when_configuration_says_so(): void
    {
        $this->bootApp($this->config->with(['SESSION_COOKIE_SECURE' => '0']));
        $this->createUser('bob', Role::Designer, self::PASSWORD);

        $response = $this->login('bob', self::PASSWORD);

        self::assertSame(200, $response->getStatusCode());
        $attributes = array_slice(array_map('trim', explode(';', $response->getHeaderLine('Set-Cookie'))), 1);
        self::assertEqualsCanonicalizing(['Path=/', 'HttpOnly', 'SameSite=Lax'], $attributes);
    }

    public function test_me_returns_role_locale_and_csrf_token(): void
    {
        $user = $this->createUser('vera', Role::Manager, self::PASSWORD, 'ru');
        $login = $this->login('vera', self::PASSWORD);
        $token = self::sessionTokenFrom($login);

        $response = $this->handle(self::withSession($this->buildRequest('GET', '/api/auth/me'), $token));

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);

        $me = self::decodeJson($response);
        self::assertIsArray($me);
        self::assertSame(['id', 'username', 'displayName', 'role', 'locale', 'csrfToken'], array_keys($me));
        self::assertSame($user->id, $me['id']);
        self::assertSame('vera', $me['username']);
        self::assertSame('Vera Test', $me['displayName']);
        self::assertSame('manager', $me['role']);
        self::assertSame('ru', $me['locale']);
        self::assertIsString($me['csrfToken']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $me['csrfToken']);

        // The login response carries the same view of the user, token included.
        $loginBody = self::decodeJson($login);
        self::assertIsArray($loginBody);
        self::assertSame(['user' => $me], $loginBody);
    }

    public function test_me_without_a_session_is_401(): void
    {
        $response = $this->request('GET', '/api/auth/me');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":{"code":"unauthenticated","fields":[]}}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
    }

    public function test_login_body_is_validated(): void
    {
        $missing = $this->request('POST', '/api/auth/login', ['username' => 'bob']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame([['field' => 'password', 'code' => 'required', 'params' => []]], self::errorFields($missing));
        $this->assertMatchesSpec($missing);

        $tooLong = $this->login(str_repeat('a', 101), str_repeat('p', 1001));
        self::assertSame(
            [
                ['field' => 'username', 'code' => 'too_long', 'params' => ['min' => 1, 'max' => 100]],
                ['field' => 'password', 'code' => 'too_long', 'params' => ['min' => 1, 'max' => 1000]],
            ],
            self::errorFields($tooLong),
        );

        $notStrings = $this->request('POST', '/api/auth/login', ['username' => ['bob'], 'password' => 5]);
        self::assertSame(
            [
                ['field' => 'username', 'code' => 'invalid', 'params' => []],
                ['field' => 'password', 'code' => 'invalid', 'params' => []],
            ],
            self::errorFields($notStrings),
        );
    }

    public function test_login_from_a_form_post_is_refused(): void
    {
        // A cross-site HTML form can send urlencoded or text/plain bodies but not JSON; refusing them
        // means another site cannot log a visitor into an account of its choosing.
        $this->createUser('bob', Role::Designer, self::PASSWORD);

        foreach (['application/x-www-form-urlencoded', 'text/plain'] as $type) {
            $request = $this->buildRequest('POST', '/api/auth/login')
                ->withHeader('Content-Type', $type)
                ->withBody((new StreamFactory())->createStream(http_build_query(['username' => 'bob', 'password' => self::PASSWORD])));

            $response = $this->handle($request);

            self::assertSame(403, $response->getStatusCode(), $type);
            self::assertFalse($response->hasHeader('Set-Cookie'));
            $this->assertMatchesSpec($response);
        }

        self::assertSame(0, $this->countRows('sessions'));
    }

    public function test_successful_login_replaces_the_session_the_browser_presented(): void
    {
        $user = $this->createUser('bob', Role::Designer, self::PASSWORD);
        [$old] = $this->startSession($user);

        $request = self::withSession(
            $this->buildRequest('POST', '/api/auth/login', ['username' => 'bob', 'password' => self::PASSWORD]),
            $old,
        );
        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($old, self::sessionTokenFrom($response));
        self::assertSame(1, $this->countRows('sessions'));
    }

    private function login(string $username, string $password): ResponseInterface
    {
        return $this->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    }

    /**
     * @return mixed the "fields" of an error response
     */
    private static function errorFields(ResponseInterface $response): mixed
    {
        $body = self::decodeJson($response);
        self::assertIsArray($body);
        self::assertIsArray($body['error']);

        return $body['error']['fields'];
    }

    private static function render(LogRecord $record): string
    {
        return $record->message . ' ' . (string) json_encode($record->context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

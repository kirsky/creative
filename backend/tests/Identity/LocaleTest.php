<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\UserRepository;
use App\Tests\Support\ApiTestCase;

final class LocaleTest extends ApiTestCase
{
    public function test_patch_locale_persists_ru(): void
    {
        $user = $this->createUser('anna');

        $response = $this->request('PATCH', '/api/me/locale', ['locale' => 'ru'], as: $user);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        $me = self::decodeJson($response);
        self::assertIsArray($me);
        self::assertSame('ru', $me['locale']);
        self::assertSame($user->id, $me['id']);

        self::assertSame('ru', $this->service(UserRepository::class)->findById($user->id)?->locale);

        $after = self::decodeJson($this->request('GET', '/api/auth/me', as: $user));
        self::assertIsArray($after);
        self::assertSame('ru', $after['locale']);
    }

    public function test_invalid_locale_de_is_422(): void
    {
        $user = $this->createUser('anna');

        $response = $this->request('PATCH', '/api/me/locale', ['locale' => 'de'], as: $user);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            '{"error":{"code":"validation_failed","fields":[{"field":"locale","code":"invalid_choice","params":{}}]}}',
            (string) $response->getBody(),
        );
        $this->assertMatchesSpec($response);
        self::assertSame('en', $this->service(UserRepository::class)->findById($user->id)?->locale);
    }

    public function test_locale_must_be_present_and_a_string(): void
    {
        $user = $this->createUser('anna');

        $missing = $this->request('PATCH', '/api/me/locale', ['other' => 'ru'], as: $user);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame(
            '{"error":{"code":"validation_failed","fields":['
            . '{"field":"locale","code":"required","params":{}},'
            . '{"field":"other","code":"unknown_field","params":{}}]}}',
            (string) $missing->getBody(),
        );

        foreach ([null, ['ru'], 1, 'RU', 'ru '] as $locale) {
            $response = $this->request('PATCH', '/api/me/locale', ['locale' => $locale], as: $user);

            self::assertSame(422, $response->getStatusCode(), var_export($locale, true));
        }
    }

    public function test_changing_the_locale_does_not_touch_other_users(): void
    {
        $anna = $this->createUser('anna');
        $bob = $this->createUser('bob');

        $this->request('PATCH', '/api/me/locale', ['locale' => 'ru'], as: $anna);

        self::assertSame('en', $this->service(UserRepository::class)->findById($bob->id)?->locale);
    }
}

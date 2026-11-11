<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\AuthMiddleware;
use App\Identity\UserRepository;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH /api/me/locale {locale: "en"|"ru"}: the signed-in user's interface language. Answers with Me.
 */
final class SetLocaleAction
{
    public const LOCALES = ['en', 'ru'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly Validator $validator,
        private readonly JsonResponder $json,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $session = AuthMiddleware::session($request);

        $body = $request->getParsedBody();
        $data = $this->validator->validate(is_array($body) ? $body : [], new Assert\Collection([
            'locale' => new Assert\Sequentially([
                new Assert\NotNull(message: 'required'),
                new Assert\Choice(choices: self::LOCALES, message: 'invalid_choice'),
            ]),
        ]));

        $locale = $data['locale'];
        if (!is_string($locale)) {
            throw new RuntimeException('A validated locale is not a string.');
        }

        $user = $session->user;
        $this->users->setLocale($user->id, $locale);
        $updated = $this->users->findById($user->id) ?? throw new RuntimeException('The signed-in user is gone.');

        return $this->json->respond(MeAction::present($updated, $session->csrfToken));
    }
}

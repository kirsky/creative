<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\AuthService;
use App\Identity\SessionCookie;
use App\Identity\SessionService;
use App\Shared\Http\ApiError;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\TrustedProxyMiddleware;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/auth/login {username, password}: starts a session and sets its cookie. Public.
 *
 * Only JSON bodies are accepted. A cross-site HTML form can post urlencoded or text/plain bodies
 * without a CORS preflight, which would let another site sign a visitor into an account of its
 * choosing (login CSRF); it cannot post application/json.
 */
final class LoginAction
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SessionService $sessions,
        private readonly SessionCookie $cookie,
        private readonly Validator $validator,
        private readonly JsonResponder $json,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw ApiError::forbidden();
        }

        $body = $request->getParsedBody();
        $data = $this->validator->validate(is_array($body) ? $body : [], new Assert\Collection([
            'username' => self::text(100),
            'password' => self::text(1000),
        ]));

        $username = $data['username'];
        $password = $data['password'];
        if (!is_string($username) || !is_string($password)) {
            throw new RuntimeException('Validated login fields are not strings.');
        }

        $ip = $request->getAttribute(TrustedProxyMiddleware::ATTRIBUTE);
        $ip = is_string($ip) ? $ip : '0.0.0.0';

        $user = $this->auth->login($username, $password, $ip);

        // The browser is replacing its cookie; the session it presented (if any) ends with it.
        $previous = SessionCookie::read($request);
        if ($previous !== null) {
            $this->sessions->end($previous);
        }

        $token = $this->sessions->start($user, $ip);
        $session = $this->sessions->resolveSession($token) ?? throw new RuntimeException('A session just started does not resolve.');

        return $this->json->respond(
            ['user' => MeAction::present($session->user, $session->csrfToken)],
            200,
            ['Set-Cookie' => $this->cookie->issue($token)],
        );
    }

    /**
     * @param int<1, max> $max
     */
    private static function text(int $max): Assert\Sequentially
    {
        return new Assert\Sequentially([
            new Assert\NotNull(message: 'required'),
            new Assert\Type('string', message: 'invalid'),
            new Assert\Length(min: 1, max: $max, minMessage: 'too_short', maxMessage: 'too_long'),
        ]);
    }
}

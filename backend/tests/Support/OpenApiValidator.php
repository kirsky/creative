<?php

declare(strict_types=1);

namespace App\Tests\Support;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks PSR-7 responses against an OpenAPI 3.1 document.
 *
 * Why not a library: league/openapi-psr7-validator, the maintained PSR-7 option, supports
 * OpenAPI 3.0 only. OpenAPI 3.1 schemas are JSON Schema 2020-12, which opis/json-schema validates,
 * so this class only does the OpenAPI part: find the operation, pick the documented response
 * (exact status, "4XX" range or "default"), and check the media type, the required headers and the
 * JSON body against the schema, following local $refs. Request validation is out of scope.
 */
final class OpenApiValidator
{
    private const DOCUMENT_URI = 'https://openapi.invalid/spec';

    private readonly stdClass $document;

    private readonly Validator $schemas;

    public function __construct(string $specPath)
    {
        $document = Yaml::parse((string) file_get_contents($specPath), Yaml::PARSE_OBJECT_FOR_MAP);

        if (!$document instanceof stdClass || !is_string($document->openapi ?? null) || !str_starts_with($document->openapi, '3.1')) {
            throw new InvalidArgumentException(sprintf('%s is not an OpenAPI 3.1 document.', $specPath));
        }

        $this->document = $document;
        $this->schemas = new Validator();
        $this->schemas->setMaxErrors(10);
        $this->schemas->resolver()?->registerRaw($document, self::DOCUMENT_URI);
    }

    /**
     * @return list<string> what is wrong with the response; empty when it matches the spec
     */
    public function validate(string $method, string $path, ResponseInterface $response): array
    {
        $path = (string) parse_url($path, PHP_URL_PATH);
        $method = strtolower($method);

        $template = $this->findPath($path);
        if ($template === null) {
            return [sprintf('The spec has no path matching %s.', $path)];
        }

        $operation = self::asObject(self::asObject($this->document->paths)->{$template}->{$method} ?? null);
        if ($operation === null) {
            return [sprintf('The spec documents no %s operation for %s.', strtoupper($method), $template)];
        }

        $status = (string) $response->getStatusCode();
        $located = $this->findResponse(
            self::asObject($operation->responses ?? null),
            $status,
            '/paths/' . self::escape($template) . '/' . $method . '/responses',
        );
        if ($located === null) {
            return [sprintf('%s %s documents no response for status %s.', strtoupper($method), $template, $status)];
        }

        [$spec, $pointer] = $located;

        return array_merge(
            $this->checkHeaders($spec, $response),
            $this->checkBody($spec, $pointer, $response),
        );
    }

    /**
     * Validates decoded JSON (objects as stdClass) against components/schemas/{name}.
     *
     * @return list<string>
     */
    public function validateSchema(string $name, mixed $data): array
    {
        if (is_array($data)) {
            $data = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        }

        return $this->validateAgainst($data, '/components/schemas/' . self::escape($name));
    }

    /**
     * Every local $ref in the document must point at something. Remote references are not supported.
     *
     * @return list<string>
     */
    public function unresolvedReferences(): array
    {
        $problems = [];
        $walk = function (mixed $node) use (&$walk, &$problems): void {
            if ($node instanceof stdClass) {
                foreach (get_object_vars($node) as $key => $value) {
                    if ($key === '$ref' && is_string($value)) {
                        if (!str_starts_with($value, '#/') || $this->lookup(substr($value, 1)) === null) {
                            $problems[] = $value;
                        }
                    } else {
                        $walk($value);
                    }
                }
            } elseif (is_array($node)) {
                array_map($walk, $node);
            }
        };
        $walk($this->document);

        return $problems;
    }

    private function findPath(string $path): ?string
    {
        $paths = self::asObject($this->document->paths ?? null);
        if ($paths === null) {
            return null;
        }

        if (isset($paths->{$path})) {
            return $path;
        }

        $best = null;
        $bestParameters = PHP_INT_MAX;
        foreach (array_keys(get_object_vars($paths)) as $template) {
            $template = (string) $template;
            $parameters = preg_match_all('/\{[^}\/]+\}/', $template);
            $pattern = '#^' . preg_replace('/\\\\\{[^}\/]+\\\\\}/', '[^/]+', preg_quote($template, '#')) . '$#';

            if (preg_match($pattern, $path) === 1 && $parameters < $bestParameters) {
                $best = $template;
                $bestParameters = (int) $parameters;
            }
        }

        return $best;
    }

    /**
     * @return array{stdClass, string}|null the response object and its JSON pointer, after following $refs
     */
    private function findResponse(?stdClass $responses, string $status, string $pointer): ?array
    {
        if ($responses === null) {
            return null;
        }

        foreach ([$status, $status[0] . 'XX', 'default'] as $key) {
            if (isset($responses->{$key})) {
                return $this->follow(self::asObject($responses->{$key}), $pointer . '/' . $key);
            }
        }

        return null;
    }

    /**
     * @return array{stdClass, string}|null
     */
    private function follow(?stdClass $node, string $pointer): ?array
    {
        for ($hops = 0; $node !== null && $hops < 10; ++$hops) {
            $ref = $node->{'$ref'} ?? null;
            if (!is_string($ref)) {
                return [$node, $pointer];
            }

            $pointer = substr($ref, 1);
            $node = self::asObject($this->lookup($pointer));
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function checkHeaders(stdClass $spec, ResponseInterface $response): array
    {
        $errors = [];
        $headers = self::asObject($spec->headers ?? null);

        foreach ($headers === null ? [] : get_object_vars($headers) as $name => $definition) {
            $definition = self::asObject($definition);
            if (($definition->required ?? false) === true && !$response->hasHeader((string) $name)) {
                $errors[] = sprintf('The documented response header %s is missing.', $name);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function checkBody(stdClass $spec, string $pointer, ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $content = self::asObject($spec->content ?? null);

        if ($content === null || get_object_vars($content) === []) {
            return $body === '' ? [] : ['The response is documented without a body, but it has one.'];
        }

        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if ($contentType === '') {
            return ['The response has no Content-Type.'];
        }

        $mediaType = $this->findMediaType($content, $contentType);
        if ($mediaType === null) {
            return [sprintf('Content-Type %s is not documented (documented: %s).', $contentType, implode(', ', array_keys(get_object_vars($content))))];
        }

        $schema = self::asObject($content->{$mediaType})->schema ?? null;
        if ($schema === null || !($contentType === 'application/json' || str_ends_with($contentType, '+json'))) {
            return [];
        }

        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['The body is not valid JSON: ' . $e->getMessage()];
        }

        return $this->validateAgainst($data, $pointer . '/content/' . self::escape($mediaType) . '/schema');
    }

    private function findMediaType(stdClass $content, string $contentType): ?string
    {
        $documented = array_map('strval', array_keys(get_object_vars($content)));

        foreach ($documented as $candidate) {
            if (strtolower($candidate) === $contentType) {
                return $candidate;
            }
        }

        foreach ($documented as $candidate) {
            if (strtolower($candidate) === explode('/', $contentType)[0] . '/*' || $candidate === '*/*') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function validateAgainst(mixed $data, string $pointer): array
    {
        // A JSON pointer inside a URI fragment is percent-encoded segment by segment ("{id}" is not a legal fragment).
        $fragment = implode('/', array_map('rawurlencode', explode('/', $pointer)));

        $result = $this->schemas->validate($data, self::DOCUMENT_URI . '#' . $fragment);
        $error = $result->error();

        if ($error === null) {
            return [];
        }

        $messages = [];
        $formatted = (new ErrorFormatter())->format($error, true);
        foreach ($formatted as $path => $problems) {
            foreach (is_array($problems) ? $problems : [] as $problem) {
                $messages[] = sprintf('%s: %s', $path === '' ? '/' : $path, is_string($problem) ? $problem : 'invalid');
            }
        }

        return $messages === [] ? ['The body does not match the schema.'] : $messages;
    }

    /**
     * The node at a JSON pointer ("/components/schemas/Error") of the document, or null.
     */
    private function lookup(string $pointer): mixed
    {
        $node = $this->document;

        foreach (array_slice(explode('/', $pointer), 1) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if ($node instanceof stdClass && property_exists($node, $segment)) {
                $node = $node->{$segment};
            } elseif (is_array($node) && isset($node[(int) $segment])) {
                $node = $node[(int) $segment];
            } else {
                return null;
            }
        }

        return $node;
    }

    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    private static function asObject(mixed $value): ?stdClass
    {
        return $value instanceof stdClass ? $value : null;
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

final class OpenApiDocument
{
    public function build(): array
    {
        $schemas = json_decode(file_get_contents(resource_path('openapi/schemas.json')), true, 512, JSON_THROW_ON_ERROR);
        $operations = json_decode(file_get_contents(resource_path('openapi/operations.json')), true, 512, JSON_THROW_ON_ERROR);
        $paths = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $name = str_replace('api.v1.', '', $route->getName());
            if (! isset($operations[$name])) {
                throw new \LogicException('Missing OpenAPI contract for '.$name);
            }
            $metadata = $operations[$name];
            $parameters = [];
            preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
            foreach ($matches[1] as $parameter) {
                $parameters[] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160]];
            }
            if ($metadata['paginate'] ?? false) {
                $parameters[] = ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 1]];
            }
            foreach ($metadata['query'] ?? [] as $parameter) {
                $parameters[] = $parameter;
            }
            $operation = ['summary' => $metadata['summary'], 'tags' => [$metadata['tag']], 'parameters' => $parameters, 'security' => in_array('auth:sanctum', $route->gatherMiddleware(), true) ? [['bearerAuth' => []]] : [], 'responses' => []];
            if (isset($metadata['body'])) {
                $operation['requestBody'] = ['required' => ! ($metadata['optional_body'] ?? false), 'content' => [($metadata['request_media'] ?? 'application/json') => ['schema' => ['$ref' => '#/components/schemas/'.$metadata['body']]]]];
            }
            if ($name === 'checkout') {
                $operation['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]+$']];
            }
            if ($name === 'payments.webhook') {
                foreach (['X-Webhook-Timestamp', 'X-Webhook-Signature'] as $header) {
                    $operation['parameters'][] = ['name' => $header, 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
                }
                $operation['description'] = 'Snippe 2026-01-25 envelope. Requires hex HMAC-SHA256(secret, timestamp + dot + exact raw body); timestamp tolerance 300 seconds. Subscribe to payment.completed/failed/expired/voided and payout.completed/failed/reversed. Retries deduplicate by envelope id.';
            }
            foreach ($metadata['statuses'] ?? [200] as $status) {
                $schema = ($metadata['binary'] ?? false) ? ['type' => 'string', 'format' => 'binary'] : ['$ref' => '#/components/schemas/'.($metadata['response'] ?? 'Message')];
                $operation['responses'][(string) $status] = ['description' => $status === 201 ? 'Created' : ($status === 202 ? 'Requested; financial completion is asynchronous' : 'Successful response'), 'content' => [($metadata['content_type'] ?? 'application/json') => ['schema' => $schema]]];
            }
            foreach ([401 => 'Unauthenticated or invalid signature', 403 => 'Insufficient permission', 404 => 'Resource absent or outside your scope', 409 => 'State, stock, reference or idempotency conflict', 422 => 'Validation failed', 429 => 'Rate limit exceeded', 503 => 'Required service is not configured'] as $status => $description) {
                $operation['responses'][(string) $status] = ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths['/'.$route->uri()][strtolower($method)] = ['operationId' => str_replace('.', '_', $name).'_'.strtolower($method)] + $operation;
            }
        }
        ksort($paths);

        return ['openapi' => '3.1.0', 'info' => ['title' => 'MloExpress API', 'version' => '1.0.0', 'description' => 'Integer TZS. ULID public identifiers. Sanctum bearer tokens. Pending mobile-money checkout is a stock reservation; only verified payment confirms it. Cash is restricted to pickup, with explicit vendor receipt confirmation.'], 'servers' => [['url' => '/']], 'paths' => $paths, 'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Expiring Laravel Sanctum personal access token. Store securely.']], 'schemas' => $schemas]];
    }
}

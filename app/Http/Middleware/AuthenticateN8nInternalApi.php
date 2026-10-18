<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateN8nInternalApi
{
    public function handle(Request $request, Closure $next, string $scope = 'shared'): Response
    {
        $expected = trim((string) config("services.n8n.{$scope}_internal_api_token"));
        if ($expected === '') {
            $expected = trim((string) config('services.n8n.internal_api_token'));
        }
        $actual = trim((string) $request->bearerToken());

        if ($expected === '') {
            return new JsonResponse(['message' => 'n8n internal API is not configured.'], 503);
        }

        if ($actual === '' || ! hash_equals($expected, $actual)) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}

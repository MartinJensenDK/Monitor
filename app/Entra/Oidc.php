<?php

declare(strict_types=1);

namespace App\Entra;

use App\Support\Str;
use RuntimeException;

/**
 * The sign-in half of the integration: OpenID Connect authorization code flow
 * with PKCE.
 *
 * The state and nonce are minted here and checked on the way back, so a code
 * replayed from somewhere else does not become a session.
 */
final class Oidc
{
    private const SCOPES = 'openid profile email';

    /**
     * @return array{url:string,state:string,nonce:string,verifier:string}
     */
    public static function begin(): array
    {
        if (!Entra::isConfigured()) {
            throw new RuntimeException('Microsoft sign-in is not configured.');
        }

        $state = Str::token(24);
        $nonce = Str::token(24);
        $verifier = Str::token(48);

        $query = http_build_query([
            'client_id' => Entra::clientId(),
            'response_type' => 'code',
            'redirect_uri' => Entra::redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Jwt::base64UrlEncode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);

        return [
            'url' => Entra::authorizeEndpoint() . '?' . $query,
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
        ];
    }

    /**
     * Swap the authorization code for tokens and return the verified claims.
     *
     * @return array<string,mixed>
     */
    public static function complete(string $code, string $verifier, string $nonce): array
    {
        $tokens = Http::postForm(Entra::tokenEndpoint(), [
            'client_id' => Entra::clientId(),
            'client_secret' => Entra::clientSecret(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => Entra::redirectUri(),
            'code_verifier' => $verifier,
            'scope' => self::SCOPES,
        ]);

        $idToken = (string) ($tokens['id_token'] ?? '');
        if ($idToken === '') {
            throw new RuntimeException('Microsoft returned no ID token.');
        }

        return Jwt::verify($idToken, $nonce);
    }

    /**
     * The person behind the claims, in this application's words.
     *
     * @param array<string,mixed> $claims
     * @return array{external_id:string,email:string,name:string}
     */
    public static function profile(array $claims): array
    {
        $externalId = (string) ($claims['oid'] ?? $claims['sub'] ?? '');

        // Guest accounts often have no "email" claim; the UPN is what remains.
        $email = strtolower(trim((string) (
            $claims['email']
            ?? $claims['preferred_username']
            ?? $claims['upn']
            ?? ''
        )));

        $name = trim((string) ($claims['name'] ?? ''));
        if ($name === '') {
            $name = $email !== '' ? explode('@', $email)[0] : 'Microsoft user';
        }

        if ($externalId === '') {
            throw new RuntimeException('Microsoft returned an account without an object id.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('That Microsoft account has no usable email address.');
        }

        return ['external_id' => $externalId, 'email' => $email, 'name' => $name];
    }
}

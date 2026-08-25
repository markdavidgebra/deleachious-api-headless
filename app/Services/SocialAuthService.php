<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialAuthService
{
    public function authenticate(
        string $provider,
        ?string $idToken,
        ?string $accessToken,
        ?string $name = null,
        ?string $email = null,
    ): User {
        $profile = match ($provider) {
            'google' => $this->google($idToken),
            'facebook' => $this->facebook($accessToken),
            'apple' => $this->apple($idToken, $name, $email),
            default => throw ValidationException::withMessages([
                'provider' => ['Unsupported provider.'],
            ]),
        };

        return $this->findOrCreate($provider, $profile);
    }

    /**
     * @return array{id: string, email: string, name: string}
     */
    private function google(?string $idToken): array
    {
        if (! $idToken) {
            throw ValidationException::withMessages([
                'id_token' => ['A Google ID token is required.'],
            ]);
        }

        $response = Http::timeout(12)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (! $response->ok()) {
            throw ValidationException::withMessages([
                'id_token' => ['Google sign-in could not be verified.'],
            ]);
        }

        $aud = (string) $response->json('aud');
        if (! $this->audienceAllowed($aud, (string) config('services.google.client_ids'))) {
            throw ValidationException::withMessages([
                'id_token' => ['This Google app is not allowed.'],
            ]);
        }

        $verified = $response->json('email_verified');
        if ($verified === false || $verified === 'false') {
            throw ValidationException::withMessages([
                'email' => ['Google email is not verified.'],
            ]);
        }

        $email = $response->json('email');
        if (! $email) {
            throw ValidationException::withMessages([
                'email' => ['Google did not return an email address.'],
            ]);
        }

        return [
            'id' => (string) $response->json('sub'),
            'email' => (string) $email,
            'name' => (string) ($response->json('name') ?: Str::before($email, '@')),
        ];
    }

    /**
     * @return array{id: string, email: string, name: string}
     */
    private function facebook(?string $accessToken): array
    {
        if (! $accessToken) {
            throw ValidationException::withMessages([
                'access_token' => ['A Facebook access token is required.'],
            ]);
        }

        $appId = (string) config('services.facebook.client_id');
        $secret = (string) config('services.facebook.client_secret');
        if ($appId === '' || $secret === '') {
            throw ValidationException::withMessages([
                'provider' => ['Facebook sign-in is not configured.'],
            ]);
        }

        $debug = Http::timeout(12)->get('https://graph.facebook.com/debug_token', [
            'input_token' => $accessToken,
            'access_token' => $appId.'|'.$secret,
        ]);

        if (! $debug->json('data.is_valid') || (string) $debug->json('data.app_id') !== $appId) {
            throw ValidationException::withMessages([
                'access_token' => ['Facebook sign-in could not be verified.'],
            ]);
        }

        $me = Http::timeout(12)->get('https://graph.facebook.com/me', [
            'fields' => 'id,name,email',
            'access_token' => $accessToken,
        ]);

        if (! $me->ok() || ! $me->json('id')) {
            throw ValidationException::withMessages([
                'access_token' => ['Facebook profile could not be loaded.'],
            ]);
        }

        $email = $me->json('email');
        if (! $email) {
            throw ValidationException::withMessages([
                'email' => ['Facebook did not return an email. Allow email access and try again.'],
            ]);
        }

        return [
            'id' => (string) $me->json('id'),
            'email' => (string) $email,
            'name' => (string) ($me->json('name') ?: Str::before($email, '@')),
        ];
    }

    /**
     * @return array{id: string, email: string, name: string}
     */
    private function apple(?string $idToken, ?string $fallbackName, ?string $fallbackEmail): array
    {
        if (! $idToken) {
            throw ValidationException::withMessages([
                'id_token' => ['An Apple identity token is required.'],
            ]);
        }

        $payload = $this->verifyAppleJwt($idToken);
        $email = $payload['email'] ?? $fallbackEmail;
        $sub = (string) ($payload['sub'] ?? '');

        if ($sub === '') {
            throw ValidationException::withMessages([
                'id_token' => ['Apple sign-in could not be verified.'],
            ]);
        }

        if (! $email) {
            $email = 'apple_'.$sub.'@privaterelay.appleid.com';
        }

        $name = trim((string) $fallbackName);

        return [
            'id' => $sub,
            'email' => (string) $email,
            'name' => $name !== '' ? $name : Str::before((string) $email, '@'),
        ];
    }

    /**
     * @param  array{id: string, email: string, name: string}  $profile
     */
    private function findOrCreate(string $provider, array $profile): User
    {
        $user = User::query()
            ->where('provider', $provider)
            ->where('provider_id', $profile['id'])
            ->first();

        if ($user) {
            $this->guardDeleted($user);

            return $user;
        }

        $user = User::query()->where('email', $profile['email'])->first();
        if ($user) {
            $this->guardDeleted($user);
            $user->update([
                'provider' => $provider,
                'provider_id' => $profile['id'],
            ]);

            return $user;
        }

        return User::create([
            'name' => $profile['name'] ?: 'Guest',
            'email' => $profile['email'],
            'password' => Str::password(32),
            'provider' => $provider,
            'provider_id' => $profile['id'],
            'email_verified_at' => now(),
            'points' => 0,
        ]);
    }

    private function guardDeleted(User $user): void
    {
        if (str_ends_with((string) $user->email, '@deleted.invalid')) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deleted.'],
            ]);
        }
    }

    private function audienceAllowed(string $audience, string $allowedCsv): bool
    {
        $allowed = array_values(array_filter(array_map('trim', explode(',', $allowedCsv))));

        return $audience !== '' && in_array($audience, $allowed, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyAppleJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw ValidationException::withMessages([
                'id_token' => ['Apple identity token is invalid.'],
            ]);
        }

        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $header = json_decode($this->base64UrlDecode($headerPart), true);
        $payload = json_decode($this->base64UrlDecode($payloadPart), true);
        if (! is_array($header) || ! is_array($payload)) {
            throw ValidationException::withMessages([
                'id_token' => ['Apple identity token is invalid.'],
            ]);
        }

        $keys = Http::timeout(12)->get('https://appleid.apple.com/auth/keys')->json('keys') ?? [];
        $jwk = collect($keys)->firstWhere('kid', $header['kid'] ?? null);
        if (! is_array($jwk)) {
            throw ValidationException::withMessages([
                'id_token' => ['Apple signing key was not found.'],
            ]);
        }

        $pem = $this->jwkToPem($jwk);
        $signature = $this->base64UrlDecode($signaturePart, binary: true);
        $ok = openssl_verify(
            $headerPart.'.'.$payloadPart,
            $signature,
            $pem,
            OPENSSL_ALGO_SHA256,
        );

        if ($ok !== 1) {
            throw ValidationException::withMessages([
                'id_token' => ['Apple sign-in could not be verified.'],
            ]);
        }

        if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
            throw ValidationException::withMessages([
                'id_token' => ['Apple issuer is invalid.'],
            ]);
        }

        $aud = (string) ($payload['aud'] ?? '');
        if (! $this->audienceAllowed($aud, (string) config('services.apple.client_ids'))) {
            throw ValidationException::withMessages([
                'id_token' => ['This Apple app is not allowed.'],
            ]);
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            throw ValidationException::withMessages([
                'id_token' => ['Apple sign-in has expired. Try again.'],
            ]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function jwkToPem(array $jwk): string
    {
        $modulus = $this->base64UrlDecode((string) ($jwk['n'] ?? ''), binary: true);
        $exponent = $this->base64UrlDecode((string) ($jwk['e'] ?? ''), binary: true);

        $modulusEnc = $this->asn1LengthPrefixed("\x02", $modulus);
        $exponentEnc = $this->asn1LengthPrefixed("\x02", $exponent);
        $rsaKey = $this->asn1LengthPrefixed("\x30", $modulusEnc.$exponentEnc);
        $bitString = $this->asn1LengthPrefixed("\x03", "\x00".$rsaKey);
        $rsaOid = pack('H*', '300d06092a864886f70d0101010500');
        $publicKey = $this->asn1LengthPrefixed("\x30", $rsaOid.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".
            chunk_split(base64_encode($publicKey), 64, "\n").
            "-----END PUBLIC KEY-----\n";
    }

    private function asn1LengthPrefixed(string $type, string $value): string
    {
        $length = strlen($value);
        if ($length < 128) {
            return $type.chr($length).$value;
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return $type.chr(0x80 | strlen($bytes)).$bytes.$value;
    }

    private function base64UrlDecode(string $value, bool $binary = false): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw ValidationException::withMessages([
                'id_token' => ['Apple identity token is invalid.'],
            ]);
        }

        return $binary ? $decoded : $decoded;
    }
}

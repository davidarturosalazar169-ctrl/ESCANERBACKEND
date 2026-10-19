<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        Log::info('Auth login request', [
            'origin' => $request->header('Origin'),
            'session_driver' => config('session.driver'),
            'session_id_before' => $request->session()->getId(),
            'session_started' => $request->session()->isStarted(),
            'has_session_cookie' => $request->hasCookie(config('session.cookie')),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son válidas.'],
            ]);
        }

        $request->session()->regenerate();
        $token = $this->createApiToken($request->user()->id);
        Log::info('Auth login success', [
            'user_id' => $request->user()->id,
            'session_id_after' => $request->session()->getId(),
            'session_cookie' => config('session.cookie'),
            'session_secure' => config('session.secure'),
            'session_same_site' => config('session.same_site'),
        ]);

        return response()->json([
            'user' => $request->user(),
            'message' => 'Inicio de sesión correcto.',
            'token' => $token,
        ])->withCookie($this->sessionCookie($request));
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = \App\Models\User::create($data);
        Auth::login($user);
        $request->session()->regenerate();
        $token = $this->createApiToken($user->id);

        return response()->json(['user' => $user, 'message' => 'Registro correcto.', 'token' => $token], 201)
            ->withCookie($this->sessionCookie($request));
    }

    public function me(Request $request): JsonResponse
    {
        Log::info('Auth session check', [
            'origin' => $request->header('Origin'),
            'session_id' => $request->session()->getId(),
            'authenticated' => Auth::check(),
            'user_id' => $request->user()?->id,
            'has_session_cookie' => $request->hasCookie(config('session.cookie')),
        ]);

        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Log::info('Auth logout request', [
            'session_id' => $request->session()->getId(),
            'user_id' => $request->user()?->id,
            'authenticated' => Auth::check(),
        ]);

        Auth::logout();
        $plainToken = $request->bearerToken();
        if ($plainToken) {
            \App\Models\ApiToken::where('token_hash', hash('sha256', $plainToken))->delete();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function sessionCookie(Request $request)
    {
        return cookie(
            config('session.cookie'),
            $request->session()->getId(),
            config('session.lifetime'),
            config('session.path'),
            config('session.domain'),
            config('session.secure'),
            config('session.http_only'),
            false,
            config('session.same_site'),
        );
    }

    private function createApiToken(int $userId): string
    {
        $plainToken = Str::random(80);
        \App\Models\ApiToken::create([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $plainToken),
        ]);

        return $plainToken;
    }
}

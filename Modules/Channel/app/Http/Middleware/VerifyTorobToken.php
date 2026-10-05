<?php

namespace Modules\Channel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Channel\Drivers\Torob\TorobJwtVerifier;
use Modules\Channel\Exceptions\InvalidTorobTokenException;
use Modules\Channel\Models\Channel;

class VerifyTorobToken
{
    public function __construct(
        protected TorobJwtVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        // ۱. چک اتصال کانال ترب
        $torob = Channel::where('slug', 'torob')->first();

        if (!$torob || !$torob->is_connected) {
            return response()->json([
                'error' => 'Torob channel is not connected.',
            ], 403);
        }

        // ۲. خواندن توکن
        $token = $request->header('X-Torob-Token');
        $version = $request->header('X-Torob-Token-Version');

        if (!$token) {
            return response()->json([
                'error' => 'X-Torob-Token header is missing.',
            ], 401);
        }

        if ((string) $version !== '1') {
            return response()->json([
                'error' => 'Unsupported X-Torob-Token-Version.',
            ], 401);
        }

        // ۳. verify
        try {
            $request->attributes->set(
                'torob_token_payload',
                $this->verifier->verify($token)
            );
        } catch (InvalidTorobTokenException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 401);
        }

        return $next($request);
    }
}
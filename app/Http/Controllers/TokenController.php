<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TokenController extends Controller
{
    public function generateToken(Request $request)
    {
        // Check if the user is authenticated (e.g., via Shopify)
        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            // If not authenticated, return an error response
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user->tokens()->delete();

        // Generate a new token for the authenticated user
        $token = $user->createToken('API Token')->plainTextToken;

        return response()->json(['token' => $token], 200);
    }
}

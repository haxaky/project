<?php

namespace App\Http\Controllers;

use App\Support\OpenApi;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    public function index(): View
    {
        return view('docs.index');
    }

    public function specification(OpenApi $openApi): JsonResponse
    {
        return response()->json($openApi->document(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function csrf(): JsonResponse
    {
        return response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store');
    }
}

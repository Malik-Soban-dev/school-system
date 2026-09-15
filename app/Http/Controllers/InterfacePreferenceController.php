<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InterfacePreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->preferences($request));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(['en', 'ur'])],
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
        ]);

        $request->user()->forceFill([
            'preferred_language' => $data['language'],
            'preferred_theme' => $data['theme'],
        ])->save();

        return response()->json($this->preferences($request));
    }

    /** @return array{language: string, theme: string} */
    private function preferences(Request $request): array
    {
        return [
            'language' => $request->user()->preferred_language ?: 'en',
            'theme' => $request->user()->preferred_theme ?: 'system',
        ];
    }
}

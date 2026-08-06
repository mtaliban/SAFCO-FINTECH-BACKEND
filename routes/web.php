<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'version' => '1.0.0',
    'api_docs' => url('/api/v1/health'),
]));

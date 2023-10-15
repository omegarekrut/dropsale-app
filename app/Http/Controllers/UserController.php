<?php

namespace App\Http\Controllers;

use App\Jobs\ImportUsersJob;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function importUsers(): JsonResponse
    {
        ImportUsersJob::dispatch();

        return response()->json(['message' => 'Import process started']);
    }
}

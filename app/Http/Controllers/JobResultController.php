<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobResultController extends Controller
{
    public function getUserImportResult()
    {
        $result = DB::table('job_results')
            ->where('type', 'user_import')
            ->latest('created_at')
            ->first();

        return response()->json($result ? json_decode($result->data, true) : []);
    }
}

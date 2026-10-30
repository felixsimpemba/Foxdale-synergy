<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The page size requested with ?per_page=, capped at 100 (specification section 15).
     */
    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, $request->integer('per_page', $default)));
    }
}

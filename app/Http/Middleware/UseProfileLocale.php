<?php

namespace App\Http\Middleware;

use App\Models\CandidateProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseProfileLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(CandidateProfile::current()->locale);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AutomaticSurveyProgressReadOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (config('survey_progress.automatic') && $request->routeIs('survey.create', 'survey.store', 'survey.edit', 'survey.update', 'survey.resubmit')) {
            if ($request->isMethod('GET')) {
                return to_route('survey-progress.index')->with('success', 'Survey quantities now come from Google Drive. Historical entries remain available.');
            }
            abort(409, 'Manual survey progress entry is disabled. Correct the Drive sources and synchronize instead.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\TrackerPoll;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackerController
{
    public function __invoke(Request $request, TrackerPoll $poll): Response
    {
        $lat = $request->input('lat');
        $lon = $request->input('lon');
        $validLocation = is_numeric($lat) && is_numeric($lon)
            && is_finite((float) $lat) && is_finite((float) $lon)
            && abs((float) $lat) <= 90 && abs((float) $lon) <= 180;
        if (!$validLocation) {
            $request->merge(['lat' => null, 'lon' => null]);
        }

        $data = $request->validate([
            'i' => ['required', 'string', 'regex:/^[0-9]{15}$/D'],
            'r' => ['required', 'integer', 'between:0,65535'],
            'c' => ['nullable', 'required_with:a', 'integer', 'between:1,65535'],
            'a' => ['nullable', 'required_with:c', 'in:0,1,2'],
            'p' => ['required', 'in:0,1'],
            'lat' => ['nullable', 'required_with:lon', 'numeric', 'between:-90,90'],
            'lon' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);

        return response($poll->handle($data), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}

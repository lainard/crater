<?php

namespace Crater\Http\Controllers;

use Crater\Models\Setting;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $version = Setting::getSetting('version');

        return response()->json([
            'version' => $version,
        ]);
    }

    public function payment(Request $request){
         $version = Setting::getSetting('version');

        return response()->json([
            'version' => $version,
        ]);
    }
}

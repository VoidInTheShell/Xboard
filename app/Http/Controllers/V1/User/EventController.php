<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\UserRealtimeService;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function ticket(Request $request)
    {
        return $this->success(UserRealtimeService::issueTicket((int) $request->user()->id));
    }
}

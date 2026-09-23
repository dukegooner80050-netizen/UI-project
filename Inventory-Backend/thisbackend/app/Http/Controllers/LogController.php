<?php

namespace App\Http\Controllers;

use App\Models\Log;
use Carbon\Carbon;

class LogController extends Controller
{
    public function index()
{
    return response()->json(

        Log::with("user")
            ->orderBy("timestamp", "desc")
            ->get()
            ->map(function ($log) {

                $timestamp = Carbon::parse($log->timestamp);

                return [

                    "id" => $log->idlogs,

                    "action" => $log->activity_description,

                    "performedBy" =>
                        optional($log->user)->full_name
                        ?? optional($log->user)->username
                        ?? "Unknown User",

                    "role" =>
                        optional($log->user)->role
                        ?? "Unknown",

                    "timestamp" => $log->timestamp,

                    "date" => $timestamp->toDateString(),

                    "time" => $timestamp->format("H:i:s"),

                ];

            })

    );
}
}
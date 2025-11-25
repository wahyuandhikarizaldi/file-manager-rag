<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class SupabaseHelper
{
    public static function uploadToSupabase($file, $filename)
    {
        $url = env('SUPABASE_URL') . "/storage/v1/object/" . env('SUPABASE_BUCKET') . "/{$filename}";
        $token = env('SUPABASE_SERVICE_ROLE');

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => $file->getMimeType(),
        ])->put($url, file_get_contents($file->getRealPath()));

        return $response->successful();
    }

    public static function getPublicUrl($filename)
    {
        return env('SUPABASE_URL') . "/storage/v1/object/public/" . env('SUPABASE_BUCKET') . "/{$filename}";
    }
}

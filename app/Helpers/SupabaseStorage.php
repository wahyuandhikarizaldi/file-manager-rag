<?php
namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class SupabaseStorage {
    public static function upload($file, $filename) {
        $url = env('SUPABASE_URL') . '/storage/v1/object/files/' . $filename;
        $token = env('SUPABASE_SERVICE_ROLE');

        $response = Http::withHeaders([
            'Authorization' => "Bearer $token",
            'Content-Type' => $file->getMimeType(),
        ])->put($url, file_get_contents($file->getRealPath()));

        return $response->successful();
    }

    public static function getFileUrl($filename) {
        return env('SUPABASE_URL') . '/storage/v1/object/public/files/' . $filename;
    }
}

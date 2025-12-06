<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class FileController extends Controller
{
    public function index()
    {
        $files = File::latest()->get();
        return view('files.index', compact('files'));
    }

    public function create()
    {
        return view('files.create');
    }

    public function store(Request $request)
{
    $request->validate([
        'file' => 'required|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,md,html,htm,json,csv,sql|max:512000',
        'description' => 'nullable|string',
    ]);
    

    $uploadedFile = $request->file('file');
    $filename = $uploadedFile->getClientOriginalName();

    // Simpan ke storage lokal (seperti biasa)
    $path = $uploadedFile->storeAs('files', $filename, 'public');

    // === Upload ke Supabase ===
    $supabaseUrl = env('SUPABASE_URL'); // contoh: https://jlfykwabronlkbnzywhk.supabase.co
    $supabaseKey = env('SUPABASE_SERVICE_ROLE'); // gunakan SERVICE ROLE KEY, bukan anon key
    $bucket = 'files';

    $fileContent = file_get_contents(storage_path('app/public/' . $path));

    $uploadUrl = "$supabaseUrl/storage/v1/object/$bucket/$filename";

    $fileMime = $uploadedFile->getMimeType();

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $supabaseKey,
        'apikey' => $supabaseKey,
        'Content-Type' => $fileMime, // otomatis sesuai file
    ])->send('POST', $uploadUrl, [
        'body' => $fileContent,
    ]);

    if ($response->failed()) {
        \Log::error('❌ Error Supabase upload', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
    } else {
        \Log::info('✅ File uploaded to Supabase', [
            'response' => $response->body(),
        ]);
    }

    // Simpan metadata ke SQLite
    $file = File::create([
        'name' => $filename,
        'path' => $path,
        'description' => $request->description,
        'upload_date' => now(),
    ]);

    // === Kirim file ke FastAPI untuk diindex ke ChromaDB ===
    $fastapiUrl = 'http://127.0.0.1:5000/upload';
    $response = Http::attach(
        'file', file_get_contents(storage_path('app/public/' . $path)), $filename
    )->post($fastapiUrl);

    if ($response->failed()) {
        return redirect()->route('files.index')
            ->with('error', 'File diupload ke server, tapi gagal diindex ke AI.');
    }

    return redirect()->route('files.index')->with('success', 'File berhasil diupload & diindex.');
}


    public function show(File $file)
    {
        return view('files.show', compact('file'));
    }

    public function edit(File $file)
    {
        return view('files.edit', compact('file'));
    }

    public function update(Request $request, File $file)
    {
        $request->validate([
            'description' => 'nullable|string',
        ]);

        $file->update([
            'description' => $request->description,
        ]);

        return redirect()->route('files.index')->with('success', 'Deskripsi diperbarui.');
    }

    public function destroy(File $file)
    {
        $filename = basename($file->path);

        // === 1️⃣ Hapus dari storage lokal ===
        Storage::disk('public')->delete($file->path);

        // === 2️⃣ Hapus dari Supabase Storage ===
        try {
            $supabaseUrl = env('SUPABASE_URL');
            $supabaseKey = env('SUPABASE_SERVICE_ROLE');
            $bucket = 'files';
            $deleteUrl = "$supabaseUrl/storage/v1/object/$bucket/$filename";

            $response = Http::withHeaders([
                'Authorization' => "Bearer $supabaseKey",
            ])->delete($deleteUrl);

            if ($response->failed()) {
                Log::error('❌ Gagal hapus file dari Supabase:', ['response' => $response->body()]);
            }
        } catch (\Exception $e) {
            Log::error('❌ Error hapus dari Supabase:', ['message' => $e->getMessage()]);
        }

        // === 3️⃣ Hapus dari FastAPI (ChromaDB) ===
        try {
            $fastapiUrl = env('FASTAPI_URL', 'http://127.0.0.1:5000') . '/delete';
            $response = Http::asForm()->delete($fastapiUrl, [
                'filename' => $filename,
            ]);

            if ($response->failed()) {
                Log::error('❌ Gagal hapus dari FastAPI:', ['response' => $response->body()]);
            }
        } catch (\Exception $e) {
            Log::error('❌ Error hapus dari FastAPI:', ['message' => $e->getMessage()]);
        }

        // === 4️⃣ Hapus dari database ===
        $file->delete();

        return redirect()->route('files.index')->with('success', '🗑️ File berhasil dihapus dari sistem, Supabase, dan AI DB.');
    }

    public function viewPdf(File $file)
    {
        // Jika kamu menyimpan path Supabase di kolom `path`
        // misalnya: 'files/ATS WAHYU.pdf'
        // maka kita tinggal buat URL publik Supabase

        $supabaseUrl = env('SUPABASE_URL'); // pastikan di .env sudah ada
        $publicPath = $file->path; // contoh: 'files/ATS WAHYU.pdf'

        // Bangun URL publiknya
        $publicUrl = "{$supabaseUrl}/storage/v1/object/public/{$publicPath}";

        // Redirect ke file publik agar iframe bisa render PDF langsung
        return redirect()->away($publicUrl);
    }

}

<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

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
            'file' => 'required|mimes:pdf|max:2048',
            'description' => 'nullable|string',
        ]);

        $uploadedFile = $request->file('file');
        $filename = $uploadedFile->getClientOriginalName();
        $path = $uploadedFile->storeAs('files', $filename, 'public');

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
        // Hapus dari storage lokal
        Storage::disk('public')->delete($file->path);
        $filename = basename($file->path);
        $file->delete();

        // === Hapus juga di ChromaDB (FastAPI) ===
        $fastapiUrl = 'http://127.0.0.1:5000/delete';
        $response = Http::asForm()->delete($fastapiUrl, [
            'filename' => $filename,
        ]);

        if ($response->failed()) {
            return redirect()->route('files.index')
                ->with('error', 'File dihapus di sistem, tapi gagal dihapus dari AI DB.');
        }

        return redirect()->route('files.index')->with('success', 'File dihapus dari sistem & AI DB.');
    }

    public function viewPdf(File $file)
    {
        $path = storage_path('app/public/' . $file->path);

        if (!file_exists($path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file->name . '"',
            'X-Frame-Options' => 'ALLOWALL',   // <--- ini penting
        ]);
    }
    

}

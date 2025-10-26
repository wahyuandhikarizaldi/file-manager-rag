<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\File;

Route::get('/', [FileController::class, 'index'])->name('files.index');
Route::get('/files/create', [FileController::class, 'create'])->name('files.create');
Route::post('/files', [FileController::class, 'store'])->name('files.store');
Route::get('/files/{file}', [FileController::class, 'show'])->name('files.show');
Route::get('/files/{file}/edit', [FileController::class, 'edit'])->name('files.edit');
Route::put('/files/{file}', [FileController::class, 'update'])->name('files.update');
Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
Route::get('/view-pdf/{file}', [FileController::class, 'viewPdf'])->name('files.view');
Route::post('/ask', function (Request $request) {
    $question = $request->input('question');

    // Kirim pertanyaan ke server AI (FastAPI)
    $response = Http::post('http://127.0.0.1:5000/ask', [
        'question' => $question,
    ]);

    $data = $response->json();

    $sources = [];
    if (isset($data['sources'])) {
        foreach ($data['sources'] as $filename) {
            // cari berdasarkan kolom `path` (karena struktur db kamu pakai ini)
            $file = File::where('path', 'like', "%{$filename}%")->first();

            if ($file) {
                $sources[] = [
                    'name' => $file->name,
                    'url'  => route('files.show', $file->id),
                ];
            } else {
                $sources[] = [
                    'name' => $filename,
                    'url'  => null,
                ];
            }
        }
    }

    return response()->json([
        'answer' => $data['answer'] ?? 'Tidak ada jawaban.',
        'sources' => $sources,
    ]);
});

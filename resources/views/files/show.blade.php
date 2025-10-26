<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lihat File</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen flex flex-col items-center py-10 px-4">

    <div class="w-full max-w-4xl bg-white rounded-2xl shadow-lg p-8">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">{{ $file->name }}</h1>
        <p class="text-gray-500 text-sm mb-1">📅 Tanggal Upload: <span class="font-medium">{{ $file->upload_date }}</span></p>
        <p class="text-gray-600 mb-6">📝 Deskripsi: <span class="font-medium">{{ $file->description ?? '-' }}</span></p>

        <div class="border rounded-lg overflow-hidden shadow-sm mb-6">
            <iframe 
                src="{{ route('files.view', $file->id) }}" 
                class="w-full h-[600px] border-0"
                allowfullscreen>
            </iframe>
        </div>

        <div class="flex flex-wrap justify-between items-center gap-3">
            <a href="{{ asset('storage/' . $file->path) }}" 
               download 
               class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition">
               ⬇️ Download PDF
            </a>

            <a href="{{ route('files.index') }}" 
               class="text-blue-600 hover:underline font-medium">
               ← Kembali ke Daftar File
            </a>
        </div>
    </div>

</body>
</html>

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

        @php
            $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
            $fileUrl = env('SUPABASE_URL') . '/storage/v1/object/public/' . $file->path;
        @endphp

        <div class="border rounded-lg overflow-hidden shadow-sm mb-6">
            @if(in_array($ext, ['pdf','doc','docx','ppt','pptx','xls','xlsx']))
                {{-- Google Docs Viewer untuk PDF & Office --}}
                <iframe src="https://docs.google.com/gview?url={{ urlencode($fileUrl) }}&embedded=true" 
                        class="w-full h-[600px]" frameborder="0"></iframe>
            @elseif(in_array($ext, ['txt','csv','json']))
                {{-- Preview konten teks --}}
                @php
                    try {
                        $content = file_get_contents(storage_path('app/public/' . $file->path));
                    } catch (\Exception $e) {
                        $content = null;
                    }
                @endphp

                @if($content)
                    <pre class="whitespace-pre-wrap p-4 bg-gray-100 border rounded h-[600px] overflow-auto text-sm">{{ $content }}</pre>
                @else
                    <p class="text-gray-700 p-4">📄 Preview not supported.</p>
                @endif
            @else
                {{-- File tidak bisa preview --}}
                <p class="text-gray-700 p-4">📄 Preview not supported.</p>
            @endif
        </div>

        <div class="flex flex-wrap justify-between items-center gap-3">
            <a href="{{ $fileUrl }}" download class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700">⬇️ Download File</a>
            <a href="{{ route('files.index') }}" class="text-blue-600 hover:underline font-medium">
               ← Kembali ke Daftar File
            </a>
        </div>
    </div>

</body>
</html>

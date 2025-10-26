<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Deskripsi File</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col items-center justify-center p-6">

    <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8">
        <h1 class="text-2xl font-bold text-center text-gray-800 mb-6">✏️ Edit Deskripsi File</h1>

        @if (session('success'))
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        <form action="{{ route('files.update', $file->id) }}" method="POST" class="space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label class="block font-semibold mb-1 text-gray-700">Nama File:</label>
                <p class="border border-gray-300 bg-gray-50 rounded-lg p-2 text-gray-600">
                    {{ $file->name }}
                </p>
            </div>

            <div>
                <label class="block font-semibold mb-1 text-gray-700">Deskripsi:</label>
                <input type="text" name="description" value="{{ $file->description }}"
                    placeholder="Masukkan deskripsi baru..."
                    class="w-full border border-gray-300 rounded-lg p-2 bg-gray-50 focus:ring-2 focus:ring-blue-400">
            </div>

            <div class="flex justify-between items-center">
                <a href="{{ route('files.index') }}" 
                    class="text-blue-600 hover:underline text-sm font-medium">← Kembali ke daftar file</a>
                <button type="submit"
                    class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                    Simpan
                </button>
            </div>
        </form>
    </div>

</body>
</html>

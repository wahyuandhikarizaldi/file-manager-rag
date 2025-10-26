<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manajemen File</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <style>
    /* Animasi muncul & hilang */
    .fade-slide-enter {
      opacity: 0;
      transform: translateY(20px);
      transition: all 0.3s ease;
    }
    .fade-slide-enter-active {
      opacity: 1;
      transform: translateY(0);
    }
    .fade-slide-exit {
      opacity: 1;
      transform: translateY(0);
      transition: all 0.3s ease;
    }
    .fade-slide-exit-active {
      opacity: 0;
      transform: translateY(20px);
    }

    /* Animasi muncul jawaban */
    .fade-in {
      opacity: 0;
      animation: fadeIn 0.4s ease forwards;
    }
    @keyframes fadeIn {
      to {
        opacity: 1;
      }
    }
  </style>
</head>

<body class="bg-gray-50 text-gray-800 relative">
  <div class="max-w-6xl mx-auto py-10 px-4">
    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
      <h1 class="text-3xl font-bold text-gray-900">📂 Manajemen File</h1>
      <a href="{{ route('files.create') }}"
         class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
        + Upload File Baru
      </a>
    </div>

    <!-- Pesan sukses -->
    @if (session('success'))
      <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg border border-green-300">
        ✅ {{ session('success') }}
      </div>
    @endif

    <!-- Tabel daftar file -->
    <div class="overflow-x-auto bg-white shadow-lg rounded-lg">
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-100 text-gray-700">
            <th class="py-3 px-4">Nama File</th>
            <th class="py-3 px-4">Tanggal Upload</th>
            <th class="py-3 px-4">Deskripsi</th>
            <th class="py-3 px-4 text-center">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($files as $file)
            <tr class="border-b hover:bg-gray-50">
              <td class="py-3 px-4 font-medium text-blue-600">
                <a href="{{ route('files.show', $file->id) }}" class="hover:underline">
                  {{ $file->name }}
                </a>
              </td>
              <td class="py-3 px-4">{{ $file->upload_date }}</td>
              <td class="py-3 px-4">{{ $file->description }}</td>
              <td class="py-3 px-4 text-center">
                <a href="{{ route('files.edit', $file->id) }}" class="text-yellow-600 hover:text-yellow-800">Edit</a>
                <span class="mx-2 text-gray-400">|</span>
                <form action="{{ route('files.destroy', $file->id) }}" method="POST" class="inline">
                  @csrf @method('DELETE')
                  <button type="submit" onclick="return confirm('Yakin hapus file ini?')" 
                          class="text-red-600 hover:text-red-800">Hapus</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <!-- Floating Button -->
  <button id="openAsk"
          class="fixed bottom-6 right-6 bg-blue-600 text-white px-5 py-3 rounded-full shadow-lg hover:bg-blue-700 transition flex items-center gap-2">
    💬 Tanya Dokumen
  </button>

  <!-- Overlay (klik luar untuk tutup) -->
  <!-- <div id="overlay" class="hidden fixed inset-0 bg-black bg-opacity-30 backdrop-blur-sm z-40"></div> -->
  <div id="overlay" class="hidden fixed inset-0 z-40"></div>

  <!-- Floating Window -->
  <div id="askWindow"
       class="hidden fixed bottom-20 right-6 w-96 bg-white shadow-2xl rounded-2xl border border-gray-200 overflow-hidden z-50 fade-slide-enter">
    <div class="flex justify-between items-center bg-blue-600 text-white px-4 py-3">
      <h3 class="font-semibold">🧠 Tanya Tentang Dokumen</h3>
      <button id="closeAsk" class="text-white text-xl leading-none hover:text-gray-200">×</button>
    </div>

    <div class="p-4">
      <form id="askForm" class="flex flex-col gap-3">
        @csrf
        <input type="text" id="question" name="question" placeholder="Tulis pertanyaan kamu..." required
          class="border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-400 focus:outline-none">
        <button type="submit" id="askBtn"
                class="bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition">
          Tanya
        </button>
      </form>

      <!-- Loading Spinner -->
      <div id="loading" class="hidden mt-4 flex items-center gap-3 text-gray-600">
        <svg class="animate-spin h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <span>Sedang mencari jawaban...</span>
      </div>

      <div id="result" class="mt-4 max-h-72 overflow-y-auto"></div>
    </div>
  </div>

  <!-- Script -->
  <script>
    const openBtn = document.getElementById('openAsk');
    const closeBtn = document.getElementById('closeAsk');
    const windowBox = document.getElementById('askWindow');
    const overlay = document.getElementById('overlay');
    const resultDiv = document.getElementById('result');

    // Fungsi buka window
    function openWindow() {
      overlay.classList.remove('hidden');
      windowBox.classList.remove('hidden', 'fade-slide-exit', 'fade-slide-exit-active');
      windowBox.classList.add('fade-slide-enter');
      setTimeout(() => windowBox.classList.add('fade-slide-enter-active'), 10);
      openBtn.classList.add('hidden');
    }

    // Fungsi tutup window
    function closeWindow() {
      windowBox.classList.remove('fade-slide-enter-active');
      windowBox.classList.add('fade-slide-exit', 'fade-slide-exit-active');
      setTimeout(() => {
        windowBox.classList.add('hidden');
        overlay.classList.add('hidden');
        openBtn.classList.remove('hidden');
      }, 300);
    }

    openBtn.addEventListener('click', openWindow);
    closeBtn.addEventListener('click', closeWindow);
    overlay.addEventListener('click', closeWindow);

    // Tanya dokumen form
    document.getElementById('askForm').addEventListener('submit', async (e) => {
      e.preventDefault();

      const question = document.getElementById('question').value;
      const token = document.querySelector('input[name="_token"]').value;
      const btn = document.getElementById('askBtn');
      const loading = document.getElementById('loading');

      resultDiv.innerHTML = "";
      loading.classList.remove('hidden');
      btn.disabled = true;
      btn.classList.add('opacity-50', 'cursor-not-allowed');
      btn.textContent = "Menunggu...";

      try {
        const res = await fetch('/ask', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token
          },
          body: JSON.stringify({ question })
        });

        const data = await res.json();

        const htmlAnswer = data.answer ? marked.parse(data.answer) : '<i>Tidak ada jawaban.</i>';
        let sourceLinks = '';

        if (data.sources && data.sources.length > 0) {
          sourceLinks = data.sources.map(s => {
            const url = s.url || `/storage/files/${encodeURIComponent(s.name)}`;
            return `<li><a href="${url}" target="_blank" class="text-blue-600 hover:underline">${s.name}</a></li>`;
          }).join('');
        } else {
          sourceLinks = '<li class="text-gray-500">Tidak ada dokumen terkait.</li>';
        }

        resultDiv.innerHTML = `
          <div class="fade-in p-4 border border-gray-200 rounded-lg bg-gray-50 shadow-sm mt-4">
            <h3 class="text-lg font-semibold mb-2 text-gray-800">💬 Jawaban:</h3>
            <div class="prose max-w-none text-gray-700">${htmlAnswer}</div>
            <hr class="my-3">
            <p class="font-medium text-gray-800">📎 Dokumen Terkait:</p>
            <ul class="list-disc ml-5">${sourceLinks}</ul>
          </div>
        `;
      } catch (err) {
        resultDiv.innerHTML = `
          <div class="fade-in mt-4 p-4 bg-red-100 border border-red-300 rounded-lg text-red-700">
            ⚠️ Terjadi kesalahan: ${err.message}
          </div>`;
      } finally {
        loading.classList.add('hidden');
        btn.disabled = false;
        btn.classList.remove('opacity-50', 'cursor-not-allowed');
        btn.textContent = "Tanya";
      }
    });
  </script>
</body>
</html>

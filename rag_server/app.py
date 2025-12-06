from fastapi import FastAPI, UploadFile, File as FastAPIFile, Form, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel
from markdown import markdown

import os, shutil, threading

from langchain.text_splitter import RecursiveCharacterTextSplitter
from langchain_community.vectorstores import Chroma
from langchain_community.document_loaders import (
    PyPDFLoader,
    Docx2txtLoader,
    UnstructuredPowerPointLoader,
    UnstructuredExcelLoader,
    TextLoader,
    UnstructuredMarkdownLoader,
    UnstructuredHTMLLoader,
    JSONLoader,
    CSVLoader
)
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_community.embeddings import HuggingFaceEmbeddings
from langchain.chains import RetrievalQAWithSourcesChain
import numpy as np
import re
import time
import random

# === Konfigurasi ===
DOCS_FOLDER = r"C:\Users\wahyu\Desktop\mulq nitip\filemanager\storage\app\public\files"
CHROMA_DIR = "./chroma_db"
GOOGLE_API_KEY = "AIzaSyBHHBeutAneymc2wg32oHqSIFJc9LQ3r6k"

# === Setup FastAPI ===
app = FastAPI(title="RAG FileManager API", version="1.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # ubah ke domain Laravel kamu kalau sudah deploy
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# ===========================
# Background rebuild control
# ===========================
current_rebuild_thread = None
rebuild_lock = threading.Lock()

def start_rebuild_in_background():
    """Start rebuild_vectorstore in a separate thread"""
    global current_rebuild_thread, rebuild_lock

    def rebuild_task():
        try:
            rebuild_vectorstore()
        except Exception as e:
            print("❌ Rebuild failed:", e)

    with rebuild_lock:
        # jika thread lama masih jalan, biarkan jalan, rebuild baru akan dijalankan di thread baru
        if current_rebuild_thread and current_rebuild_thread.is_alive():
            print("⚠️ Rebuild lama masih berjalan, starting new rebuild...")
        current_rebuild_thread = threading.Thread(target=rebuild_task)
        current_rebuild_thread.start()


# ===========================
# File loader
# ===========================
def load_file(path):
    ext = os.path.splitext(path)[1].lower()

    if ext == ".pdf":
        return PyPDFLoader(path).load()

    elif ext in [".doc", ".docx"]:
        return Docx2txtLoader(path).load()

    elif ext in [".ppt", ".pptx"]:
        return UnstructuredPowerPointLoader(path).load()

    elif ext in [".xls", ".xlsx"]:
        return UnstructuredExcelLoader(path).load()

    elif ext == ".txt":
        return TextLoader(path, encoding="utf-8").load()

    elif ext == ".md":
        return UnstructuredMarkdownLoader(path).load()

    elif ext in [".html", ".htm"]:
        return UnstructuredHTMLLoader(path).load()

    elif ext == ".json":
        return JSONLoader(path, jq_schema=".", text_content=False).load()

    elif ext == ".csv":
        return CSVLoader(path).load()

    elif ext == ".sql":
        with open(path, "r", encoding="utf-8") as f:
            content = f.read()
        # return dalam format Document
        from langchain.schema import Document
        return [Document(page_content=content, metadata={"source": os.path.basename(path)})]

    else:
        raise ValueError(f"Unsupported file type: {ext}")


# ===========================
# Vectorstore & QA
# ===========================
def load_vectorstore():
    """Muat Chroma vectorstore"""
    embeddings = HuggingFaceEmbeddings(model_name="BAAI/bge-m3")
    return Chroma(persist_directory=CHROMA_DIR, embedding_function=embeddings)

def rebuild_vectorstore():
    """Sinkronisasi ChromaDB agar sesuai dengan isi folder DOCS"""
    print("🔄 Sinkronisasi ChromaDB (delta mode)...")

    # === Inisialisasi ulang vectorstore ===
    embeddings = HuggingFaceEmbeddings(model_name="BAAI/bge-m3")
    vectorstore = Chroma(persist_directory=CHROMA_DIR, embedding_function=embeddings)

    # === Ambil daftar sumber yang sudah ada di Chroma ===
    existing_sources = set()
    if vectorstore._collection.count() > 0:
        items = vectorstore._collection.get(include=["metadatas"])
        existing_sources = {m["source"] for m in items["metadatas"] if "source" in m}

    # === Ambil daftar file DOCS saat ini di folder ===
    SUPPORTED_EXT = (".pdf", ".doc", ".docx", ".ppt", ".pptx",
                 ".xls", ".xlsx", ".txt", ".md", ".html", ".htm",
                 ".json", ".csv", ".sql")

    current_sources = {
        f for f in os.listdir(DOCS_FOLDER)
        if f.lower().endswith(SUPPORTED_EXT)
    }

    # === Hapus file yang sudah dihapus dari folder ===
    deleted_sources = existing_sources - current_sources
    for src in deleted_sources:
        vectorstore._collection.delete(where={"source": src})
        print(f"🗑️  Hapus chunk untuk file yang sudah dihapus: {src}")

    # === Tambahkan atau update file baru ===
    new_or_updated = []
    
    for src in current_sources:
        file_path = os.path.join(DOCS_FOLDER, src)

        # Ambil waktu terakhir diupdate file ini
        mtime = os.path.getmtime(file_path)
        metadata_key = f"{src}_last_modified"

        # Simpan metadata update time di metadata lokal
        last_known_time = vectorstore._collection.get(
            where={"source": src},
            include=["metadatas"]
        )

        # Jika file belum ada di DB, atau sudah berubah, kita update
        needs_update = False
        if not last_known_time["ids"]:
            needs_update = True
        else:
            stored_time = last_known_time["metadatas"][0].get("last_modified", 0)
            if mtime != stored_time:
                needs_update = True

        if needs_update:
            print(f"📥 Tambah/update file: {src}")
            # Hapus versi lama dulu
            vectorstore._collection.delete(where={"source": src})

            # Load ulang file
            try:
                docs = load_file(file_path)
                for d in docs:
                    d.metadata["source"] = src
                    d.metadata["last_modified"] = mtime

                new_or_updated.extend(docs)

            except Exception as e:
                print(f"❌ Skip {src}: {e}")


    # === Split dan simpan ke DB jika ada file baru/diupdate ===
    if new_or_updated:
        
        # === 2.1 Hitung Dynamic Chunk Size ===
        total_chars = sum([len(d.page_content) for d in new_or_updated])
        total_pages = len(new_or_updated)

        avg_chars_per_page = total_chars / max(total_pages, 1)
        print(f"Avg chars per page = {avg_chars_per_page:.0f}")

        # rule dinamis
        if avg_chars_per_page < 1500:
            chunk_size = 500
        elif avg_chars_per_page < 3000:
            chunk_size = 800
        elif avg_chars_per_page < 6000:
            chunk_size = 1200
        else:
            chunk_size = 1800

        chunk_overlap = int(chunk_size * 0.2)  # overlap = 20% dari chunk size

        print(f"Dynamic chunk_size = {chunk_size}, overlap = {chunk_overlap}")
        
        splitter = RecursiveCharacterTextSplitter(
            chunk_size=chunk_size,
            chunk_overlap=chunk_overlap
        )
        texts = splitter.split_documents(new_or_updated)
        vectorstore.add_documents(texts)
        vectorstore.persist()
        print(f"✅ Sinkronisasi selesai — {len(texts)} chunks ditambahkan/diupdate.")
    else:
        print("✅ Tidak ada perubahan, semua dokumen sudah sinkron.")

    return vectorstore

def estimate_optimal_k(vectorstore, percentile=0.10):
    """
    Estimasi k optimal tanpa brute-force loop,
    pakai distribusi jarak embedding internal.

    percentile = 0.10 berarti ambil 10% tetangga terdekat sebagai top-K.
    """
    items = vectorstore._collection.get(include=["embeddings"])
    X = np.array(items["embeddings"])

    if len(X) < 5:
        return len(X)

    # Hitung jarak rata-rata ke 10 tetangga terdekat
    from sklearn.neighbors import NearestNeighbors
    nn = NearestNeighbors(n_neighbors=min(10, len(X)))
    nn.fit(X)

    dists, _ = nn.kneighbors(X)

    # Ambil distribusi jarak “ke tetangga terdekat”
    mean_dist = np.mean(dists, axis=0)

    # top-K = persentil terdekat
    k = max(2, int(len(X) * percentile))
    k = min(k, len(X))

    return k

def get_qa_chain():
    """Bangun chain QA"""
    vectorstore = load_vectorstore()
    
    # === 5. Cari k optimal secara otomatis ===
    optimal_k = estimate_optimal_k(vectorstore)
    print(f"[Dynamic Retriever] Optimal k = {optimal_k}")
    
    retriever = vectorstore.as_retriever(
        search_kwargs={"k": optimal_k}
    )
    qa_chain = RetrievalQAWithSourcesChain.from_chain_type(
        llm=ChatGoogleGenerativeAI(model="gemini-2.5-flash", google_api_key=GOOGLE_API_KEY),
        retriever=retriever,
        chain_type="stuff",
        return_source_documents=True
    )
    return qa_chain, vectorstore


# ===========================
# Startup: rebuild vectorstore awal
# ===========================
@app.on_event("startup")
async def startup_event():
    print("🚀 Server FastAPI berjalan, melakukan sinkronisasi awal...")
    rebuild_vectorstore()

# ===========================
# Model request
# ===========================
class AskRequest(BaseModel):
    question: str


# ===========================
# Endpoint
# ===========================
@app.post("/ask")
async def ask_question(request: AskRequest):
    """Jawab pertanyaan berdasarkan dokumen"""
    try:
        qa_chain, _ = get_qa_chain()
        result = qa_chain({"question": request.question})
        answer = result.get("answer", "Tidak ada jawaban.")
        sources = []

        if result.get("sources"):
            raw_sources = result["sources"].split(",")
            for src in raw_sources:
                clean_name = os.path.basename(src.strip())
                sources.append(clean_name)

        return {"answer": answer, "sources": list(set(sources))}
    except Exception as e:
        return JSONResponse(status_code=500, content={"error": str(e)})

@app.post("/upload")
async def upload_file(background_tasks: BackgroundTasks, file: UploadFile = FastAPIFile(...)):
    """Upload file dan rebuild vectorstore di background"""
    try:
        file_path = os.path.join(DOCS_FOLDER, file.filename)
        with open(file_path, "wb") as f:
            shutil.copyfileobj(file.file, f)

        # Jalankan rebuild di background
        background_tasks.add_task(start_rebuild_in_background)

        return {"message": f"File {file.filename} berhasil di-upload, indexing dijalankan di background."}
    except Exception as e:
        return JSONResponse(status_code=500, content={"error": str(e)})

@app.delete("/delete")
async def delete_file(background_tasks: BackgroundTasks, filename: str = Form(...)):
    """Hapus file dan rebuild vectorstore di background"""
    try:
        file_path = os.path.join(DOCS_FOLDER, filename)
        if os.path.exists(file_path):
            os.remove(file_path)

        # Jalankan rebuild di background
        background_tasks.add_task(start_rebuild_in_background)

        return {"message": f"File {filename} berhasil dihapus, indexing dijalankan di background."}
    except Exception as e:
        return JSONResponse(status_code=500, content={"error": str(e)})

@app.post("/sync")
async def sync_chroma(background_tasks: BackgroundTasks):
    """Sinkronisasi manual vectorstore di background"""
    try:
        background_tasks.add_task(start_rebuild_in_background)
        return {"message": "🔄 Sinkronisasi vectorstore dijalankan di background."}
    except Exception as e:
        return JSONResponse(status_code=500, content={"error": str(e)})

@app.get("/")
async def root():
    return {"message": "RAG FileManager FastAPI is running 🚀"}

# ===========================
# Run server
# ===========================
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("rag_server:app", host="127.0.0.1", port=5000, reload=True)
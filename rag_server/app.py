from fastapi import FastAPI, UploadFile, File as FastAPIFile, Form
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel
from markdown import markdown

import os, shutil

from langchain.text_splitter import RecursiveCharacterTextSplitter
from langchain_community.vectorstores import Chroma
from langchain_community.document_loaders import PyPDFLoader
from langchain_google_genai import GoogleGenerativeAIEmbeddings, ChatGoogleGenerativeAI
from langchain_community.embeddings import HuggingFaceEmbeddings
from langchain.chains import RetrievalQAWithSourcesChain

# === Konfigurasi ===
PDF_FOLDER = r"C:\Users\wahyu\Desktop\mulq nitip\filemanager\storage\app\public\files"
CHROMA_DIR = "./chroma_db"
GOOGLE_API_KEY = "AIzaSyDdeuyde0lYy0rQvUJkmMe-3VYtTRQVNTw"

# === Setup FastAPI ===
app = FastAPI(title="RAG FileManager API", version="1.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # ubah ke domain Laravel kamu kalau sudah deploy
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# === Fungsi bantu ===
def load_vectorstore():
    """Muat Chroma vectorstore"""
    embeddings = HuggingFaceEmbeddings(model_name="sentence-transformers/all-MiniLM-L6-v2")
    return Chroma(persist_directory=CHROMA_DIR, embedding_function=embeddings)

import shutil

def rebuild_vectorstore():
    """Sinkronisasi ChromaDB agar sesuai dengan isi folder PDF"""
    print("🔄 Sinkronisasi ChromaDB (delta mode)...")

    # === Inisialisasi ulang vectorstore ===
    embeddings = HuggingFaceEmbeddings(model_name="sentence-transformers/all-MiniLM-L6-v2")
    vectorstore = Chroma(persist_directory=CHROMA_DIR, embedding_function=embeddings)

    # === Ambil daftar sumber yang sudah ada di Chroma ===
    existing_sources = set()
    if vectorstore._collection.count() > 0:
        items = vectorstore._collection.get(include=["metadatas"])
        existing_sources = {m["source"] for m in items["metadatas"] if "source" in m}

    # === Ambil daftar file PDF saat ini di folder ===
    current_sources = {
        f for f in os.listdir(PDF_FOLDER)
        if f.lower().endswith(".pdf")
    }

    # === Hapus file yang sudah dihapus dari folder ===
    deleted_sources = existing_sources - current_sources
    for src in deleted_sources:
        vectorstore._collection.delete(where={"source": src})
        print(f"🗑️  Hapus chunk untuk file yang sudah dihapus: {src}")

    # === Tambahkan atau update file baru ===
    new_or_updated = []
    
    for src in current_sources:
        file_path = os.path.join(PDF_FOLDER, src)

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
            loader = PyPDFLoader(file_path)
            docs = loader.load()
            for d in docs:
                d.metadata["source"] = src
                d.metadata["last_modified"] = mtime
            new_or_updated.extend(docs)

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

        print(f"Dynamic ch unk_size = {chunk_size}, overlap = {chunk_overlap}")
        
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

def get_qa_chain():
    """Bangun chain QA"""
    vectorstore = load_vectorstore()
    retriever = vectorstore.as_retriever(search_kwargs={"k": vectorstore._collection.count()})  # ambil semua chunk
    qa_chain = RetrievalQAWithSourcesChain.from_chain_type(
        llm=ChatGoogleGenerativeAI(model="gemini-2.5-flash", google_api_key=GOOGLE_API_KEY),
        retriever=retriever,
        chain_type="stuff",
        return_source_documents=True
    )
    return qa_chain, vectorstore

# === Jalankan sinkronisasi otomatis saat startup ===
@app.on_event("startup")
async def startup_event():
    print("🚀 Server FastAPI berjalan, melakukan sinkronisasi awal...")
    rebuild_vectorstore()


# === Model untuk input JSON ===
class AskRequest(BaseModel):
    question: str

# === Endpoint ===
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
async def upload_file(file: UploadFile = FastAPIFile(...)):
    """Sinkronisasi ulang Chroma secara manual"""
    try:
        rebuild_vectorstore()
        return {"message": "🔄 ChromaDB berhasil disinkronkan ulang dengan folder PDF."}
    except Exception as e:
        return JSONResponse(status_code=500, content={"error": str(e)})



@app.delete("/delete")
async def delete_file(filename: str = Form(...)):
    """Sinkronisasi ulang Chroma secara manual"""
    try:
        rebuild_vectorstore()
        return {"message": "🔄 ChromaDB berhasil disinkronkan ulang dengan folder PDF."}
    except Exception as e:
        return JSONResponse(status_code=500, content={"error": str(e)})



@app.post("/sync")
async def sync_chroma():
    """Sinkronisasi ulang Chroma secara manual"""
    try:
        rebuild_vectorstore()
        return {"message": "🔄 ChromaDB berhasil disinkronkan ulang dengan folder PDF."}
    except Exception as e:
        return JSONResponse(status_code=500, content={"error": str(e)})


@app.get("/")
async def root():
    return {"message": "RAG FileManager FastAPI is running 🚀"}

# === Run ===
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("rag_server:app", host="127.0.0.1", port=5000, reload=True)

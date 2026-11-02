# /workspace/embed/server.py — يُشغَّل من /workspace/venv-embed
import argparse
import threading

import uvicorn
from fastapi import FastAPI
from pydantic import BaseModel
from FlagEmbedding import BGEM3FlagModel

app = FastAPI()
model = BGEM3FlagModel("/workspace/models/bge-m3", use_fp16=True)
# إحماء بأقصى دفعة قبل أن يرد /health — فتبلغ ذاكرة التضمين ذروتها قبل أن يحسب SGLang مخزنه
model.encode(["عقار " * 1100] * 16, batch_size=16, max_length=1024,
             return_dense=True, return_sparse=True, return_colbert_vecs=True)
lock = threading.Lock()

class EmbedRequest(BaseModel):
    texts: list[str]

@app.get("/health")
def health():
    return {"status": "ok"}

@app.post("/embed")
def embed(req: EmbedRequest):
    with lock:
        out = model.encode(req.texts, batch_size=16, max_length=1024,
                           return_dense=True, return_sparse=True, return_colbert_vecs=True)
    result = []
    for i in range(len(req.texts)):
        weights = out["lexical_weights"][i]
        result.append({
            "dense": out["dense_vecs"][i].tolist(),
            "sparse": {"indices": [int(k) for k in weights],
                       "values": [float(v) for v in weights.values()]},
            "colbert": out["colbert_vecs"][i].tolist(),
        })
    return result

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8080)
    args = parser.parse_args()
    uvicorn.run(app, host=args.host, port=args.port)

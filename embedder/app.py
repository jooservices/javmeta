from typing import Any

from fastapi import FastAPI
from pydantic import BaseModel, Field
from sentence_transformers import SentenceTransformer

app = FastAPI(title="javmeta embedder")

MODEL_NAME = "intfloat/multilingual-e5-small"
_model = None


def get_model() -> SentenceTransformer:
    global _model
    if _model is None:
        _model = SentenceTransformer(MODEL_NAME)
    return _model


class EmbedRequest(BaseModel):
    model: str = "javmeta-passage"
    input: str | list[str]


class EmbeddingObject(BaseModel):
    object: str = "embedding"
    index: int
    embedding: list[float]


class EmbeddingResponse(BaseModel):
    object: str = "list"
    data: list[EmbeddingObject]
    model: str
    usage: dict[str, int]


def _prefix(model: str) -> str:
    return "query: " if "query" in model.lower() else "passage: "


@app.get("/healthz")
def healthz() -> dict[str, Any]:
    return {"status": "ok", "model": MODEL_NAME}


@app.post("/v1/embeddings", response_model=EmbeddingResponse)
def embeddings(req: EmbedRequest) -> EmbeddingResponse:
    model = get_model()
    prefix = _prefix(req.model)
    raw = req.input if isinstance(req.input, list) else [req.input]
    texts = [prefix + t for t in raw if isinstance(t, str) and t.strip()]
    if not texts:
        return EmbeddingResponse(data=[], model=req.model, usage={"prompt_tokens": 0, "total_tokens": 0})

    vecs = model.encode(texts, normalize_embeddings=True, convert_to_numpy=True)
    return EmbeddingResponse(
        data=[
            EmbeddingObject(index=i, embedding=vec.tolist())
            for i, vec in enumerate(vecs)
        ],
        model=req.model,
        usage={"prompt_tokens": len(texts), "total_tokens": len(texts)},
    )


@app.post("/embed")
def embed_legacy(req: EmbedRequest) -> dict[str, Any]:
    resp = embeddings(req)
    return {"vectors": [d.embedding for d in resp.data], "dim": int(get_model().get_sentence_embedding_dimension())}
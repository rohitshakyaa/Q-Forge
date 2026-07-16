"""`/embed` against the deterministic stub embedder (no Ollama needed)."""

import math
import os

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.services.embedding import StubEmbedder, get_embedder


@pytest.fixture
def stub_client() -> TestClient:
    """Force stub mode (same switch as the LLM provider) for the duration."""
    os.environ["LLM_PROVIDER"] = "stub"
    get_settings.cache_clear()
    yield TestClient(app)
    del os.environ["LLM_PROVIDER"]
    get_settings.cache_clear()


def post_embed(client: TestClient, texts: list[str]) -> dict:
    response = client.post("/embed", json={"texts": texts})
    assert response.status_code == 200
    return response.json()


class TestEmbed:
    def test_returns_one_vector_per_text_in_order(self, stub_client):
        body = post_embed(stub_client, ["What is TCP?", "Define a B-tree."])

        assert body["status"] == "success"
        assert body["errors"] == []
        data = body["data"]
        assert data["model"] == "stub"
        assert data["dimensions"] == 768
        assert len(data["embeddings"]) == 2
        assert all(len(vec) == 768 for vec in data["embeddings"])

    def test_same_text_always_embeds_identically(self, stub_client):
        first = post_embed(stub_client, ["Explain TCP congestion control."])
        second = post_embed(stub_client, ["Explain TCP congestion control."])

        assert first["data"]["embeddings"] == second["data"]["embeddings"]

    def test_different_texts_embed_differently(self, stub_client):
        body = post_embed(stub_client, ["What is TCP?", "What is UDP?"])
        a, b = body["data"]["embeddings"]

        assert a != b

    def test_empty_texts_rejected(self, stub_client):
        response = stub_client.post("/embed", json={"texts": []})
        assert response.status_code == 422

    def test_stub_selection_follows_provider_switch(self, stub_client):
        assert isinstance(get_embedder(), StubEmbedder)


class TestStubEmbedderVectors:
    def test_vectors_are_unit_length(self):
        (vec,) = StubEmbedder().embed(["any text at all"])

        norm = math.sqrt(sum(v * v for v in vec))
        assert norm == pytest.approx(1.0, abs=1e-9)

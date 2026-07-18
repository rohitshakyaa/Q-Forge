"""`/embed` against the deterministic stub embedder (no Ollama needed)."""

import math
import os

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.services.embedding import OllamaEmbedder, StubEmbedder, get_embedder


@pytest.fixture
def stub_client() -> TestClient:
    """Force stub mode (same switch as the LLM provider) for the duration."""
    os.environ["LLM_PROVIDER"] = "stub"
    get_settings.cache_clear()
    yield TestClient(app)
    del os.environ["LLM_PROVIDER"]
    get_settings.cache_clear()


def post_embed(client: TestClient, texts: list[str], task: str | None = None) -> dict:
    payload: dict = {"texts": texts}
    if task is not None:
        payload["task"] = task
    response = client.post("/embed", json=payload)
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

    def test_task_defaults_and_query_both_accepted(self, stub_client):
        # No task field → defaults to "document"; explicit "query" is accepted.
        assert post_embed(stub_client, ["x"])["status"] == "success"
        assert post_embed(stub_client, ["x"], task="query")["status"] == "success"

    def test_unknown_task_rejected(self, stub_client):
        response = stub_client.post("/embed", json={"texts": ["x"], "task": "cluster"})
        assert response.status_code == 422

    def test_stub_ignores_task(self, stub_client):
        # The fake has no asymmetric behaviour: same text embeds identically
        # whether requested as a query or a document (dedup plumbing relies on it).
        as_doc = post_embed(stub_client, ["Explain TCP."], task="document")
        as_query = post_embed(stub_client, ["Explain TCP."], task="query")
        assert as_doc["data"]["embeddings"] == as_query["data"]["embeddings"]


class TestOllamaEmbedderPrefixes:
    def test_applies_task_prefix_and_reports_prefixed_tag(self, monkeypatch):
        captured: dict = {}

        class FakeResponse:
            def raise_for_status(self) -> None:
                pass

            def json(self) -> dict:
                return {"embeddings": [[0.0] * 768, [0.0] * 768]}

        def fake_post(url, json, timeout):  # noqa: A002 - mirror httpx.post signature
            captured["url"] = url
            captured["json"] = json
            return FakeResponse()

        monkeypatch.setattr("app.services.embedding.ollama.httpx.post", fake_post)

        embedder = OllamaEmbedder()
        embedder.embed(["page rank", "SET protocol"], task="query")

        # Every input carries nomic's query prefix…
        assert captured["json"]["input"] == [
            "search_query: page rank",
            "search_query: SET protocol",
        ]
        # …the raw model name goes to Ollama…
        assert captured["json"]["model"] == "nomic-embed-text"
        # …and the comparability tag Laravel stamps is the prefixed variant.
        assert embedder.model == "nomic-embed-text/prefixed"

    def test_document_task_uses_document_prefix(self, monkeypatch):
        captured: dict = {}

        class FakeResponse:
            def raise_for_status(self) -> None:
                pass

            def json(self) -> dict:
                return {"embeddings": [[0.0] * 768]}

        monkeypatch.setattr(
            "app.services.embedding.ollama.httpx.post",
            lambda url, json, timeout: captured.update(json=json) or FakeResponse(),
        )

        OllamaEmbedder().embed(["stored question"], task="document")
        assert captured["json"]["input"] == ["search_document: stored question"]


class TestStubEmbedderVectors:
    def test_vectors_are_unit_length(self):
        (vec,) = StubEmbedder().embed(["any text at all"])

        norm = math.sqrt(sum(v * v for v in vec))
        assert norm == pytest.approx(1.0, abs=1e-9)

"""`/generate-questions` against the deterministic stub provider (no Ollama needed)."""

import os

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.services.llm.stub import MALFORMED_SENTINEL


@pytest.fixture
def stub_client() -> TestClient:
    """Force the stub provider for the duration of the test."""
    os.environ["LLM_PROVIDER"] = "stub"
    get_settings.cache_clear()
    yield TestClient(app)
    del os.environ["LLM_PROVIDER"]
    get_settings.cache_clear()


def post_generate(client: TestClient, **body) -> dict:
    payload = {"grounding": "Unit 3: Trees. Syllabus body.", "type": "short", "marks": 5, "count": 3}
    payload.update(body)
    response = client.post("/generate-questions", json=payload)
    assert response.status_code == 200
    return response.json()


class TestGenerateQuestions:
    def test_returns_valid_structured_questions(self, stub_client):
        body = post_generate(stub_client, count=3)

        assert body["status"] == "success"
        assert body["errors"] == []
        assert len(body["data"]) == 3
        assert all(q["text"] for q in body["data"])
        assert {q["type"] for q in body["data"]} == {"short"}
        assert {q["marks"] for q in body["data"]} == {5}

    def test_mcq_carries_options_and_answer(self, stub_client):
        body = post_generate(stub_client, type="mcq", count=2)

        assert len(body["data"]) == 2
        for q in body["data"]:
            assert q["type"] == "mcq"
            assert len(q["options"]) >= 2
            assert q["answer"]

    def test_malformed_item_lands_in_errors_not_data(self, stub_client):
        # The sentinel makes the stub append one item missing its `text`.
        body = post_generate(
            stub_client,
            grounding=f"Unit 3 context. {MALFORMED_SENTINEL}",
            count=3,
        )

        assert body["status"] == "success"
        # The three good items survive; the malformed one is isolated in errors.
        assert len(body["data"]) == 3
        assert all(q["text"] for q in body["data"])
        assert len(body["errors"]) == 1
        assert "item 3" in body["errors"][0]

    def test_rejects_out_of_range_count(self, stub_client):
        response = stub_client.post(
            "/generate-questions",
            json={"grounding": "x", "type": "short", "marks": 5, "count": 0},
        )
        assert response.status_code == 422

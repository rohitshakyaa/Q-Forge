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

    def test_units_are_optional_and_default_to_none(self, stub_client):
        # Requests without `units` (all pre-multi-unit callers) keep working, and
        # the stub emits no unit scope marker.
        body = post_generate(stub_client, count=1)

        assert body["status"] == "success"
        assert "[" not in body["data"][0]["text"].replace("[stub]", "")

    def test_two_units_reach_the_provider_prompt(self, stub_client):
        # The stub echoes the unit scope into the text, proving the request's
        # units survive the whole path (endpoint -> provider). Units are never
        # echoed as a response FIELD — Laravel stamps them on save.
        body = post_generate(stub_client, count=2, units=["Trees", "Graphs"])

        assert body["status"] == "success"
        for q in body["data"]:
            assert "Trees + Graphs" in q["text"]
            assert "units" not in q

    def test_rejects_more_than_two_units(self, stub_client):
        response = stub_client.post(
            "/generate-questions",
            json={
                "grounding": "x", "type": "short", "marks": 5, "count": 1,
                "units": ["A", "B", "C"],
            },
        )
        assert response.status_code == 422

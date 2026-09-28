"""
test_api.py - Tests for the Flask application.

Covers: pages, health check, error handling, POST /api/predict,
the /analyze form, input validation, CSRF and a missing model.

Run with:  pytest -v
"""

import os

import pytest

from app import create_app
from config import Config, TestConfig

MODEL_MISSING = not (os.path.exists(Config.MODEL_PATH) and os.path.exists(Config.VECTORIZER_PATH))
needs_model = pytest.mark.skipif(MODEL_MISSING, reason="Model not trained - run python train_model.py")

# Example test messages (test inputs only, never used for training).
SCAM_EXAMPLE = ("Congratulations! You have won 5,000,000 UGX. "
                "Click this link immediately to claim your prize.")
LEGIT_EXAMPLE = "Hi mum, I will be home late tonight. Can you keep some food for me?"


@pytest.fixture
def client():
    """A test client that sends requests to the app without a real server."""
    app = create_app(TestConfig)
    return app.test_client()


def test_health_endpoint(client):
    response = client.get("/api/health")
    assert response.status_code == 200
    assert response.get_json() == {"status": "ok"}


def test_home_page_loads(client):
    response = client.get("/")
    assert response.status_code == 200
    assert b"Analyze a Message" in response.data


def test_analyze_page_loads(client):
    response = client.get("/analyze")
    assert response.status_code == 200
    assert b'id="message"' in response.data


def test_unknown_page_shows_friendly_404(client):
    response = client.get("/does-not-exist")
    assert response.status_code == 404
    assert b"Page not found" in response.data
    assert b"Traceback" not in response.data


def test_unknown_api_route_returns_json_error(client):
    response = client.get("/api/does-not-exist")
    assert response.status_code == 404
    assert "error" in response.get_json()


def test_security_headers_present(client):
    response = client.get("/")
    assert response.headers["X-Content-Type-Options"] == "nosniff"
    assert "Content-Security-Policy" in response.headers


# ----------------------------------------------------------------------
# POST /api/predict
# ----------------------------------------------------------------------
@needs_model
def test_predict_scam_message(client):
    response = client.post("/api/predict", json={"message": SCAM_EXAMPLE})
    assert response.status_code == 200
    data = response.get_json()
    assert data["prediction"] == "scam"
    assert 0.5 <= data["confidence"] <= 1.0
    assert "Prize or reward claim" in data["indicators"]
    assert "disclaimer" in data


@needs_model
def test_predict_legitimate_message(client):
    response = client.post("/api/predict", json={"message": LEGIT_EXAMPLE})
    assert response.status_code == 200
    data = response.get_json()
    assert data["prediction"] == "legitimate"
    assert 0.5 <= data["confidence"] <= 1.0


@pytest.mark.parametrize("payload", [
    {"message": ""},             # empty
    {"message": "   \n  "},      # only whitespace
    {"message": 12345},          # not text
    {"message": None},           # null
    {"text": "wrong key"},       # missing "message"
    ["not", "an", "object"],     # wrong JSON type
])
def test_predict_rejects_invalid_input(client, payload):
    response = client.post("/api/predict", json=payload)
    assert response.status_code == 400
    assert "error" in response.get_json()


def test_predict_rejects_too_long_message(client):
    response = client.post("/api/predict", json={"message": "a" * (TestConfig.MAX_MESSAGE_LENGTH + 1)})
    assert response.status_code == 400
    assert "too long" in response.get_json()["error"]


def test_predict_rejects_non_json(client):
    response = client.post("/api/predict", data="message=hello", content_type="text/plain")
    assert response.status_code == 415


def test_predict_rejects_malformed_json(client):
    response = client.post("/api/predict", data="{not json", content_type="application/json")
    assert response.status_code == 400


def test_predict_get_not_allowed(client):
    assert client.get("/api/predict").status_code == 405


def test_predict_without_model_returns_503(tmp_path):
    class NoModelConfig(TestConfig):
        MODEL_PATH = str(tmp_path / "missing_model.pkl")
    client = create_app(NoModelConfig).test_client()
    response = client.post("/api/predict", json={"message": "hello"})
    assert response.status_code == 503
    assert "train" in response.get_json()["error"].lower()


# ----------------------------------------------------------------------
# /analyze form
# ----------------------------------------------------------------------
@needs_model
def test_analyze_form_shows_result(client):
    response = client.post("/analyze", data={"message": SCAM_EXAMPLE})
    assert response.status_code == 200
    assert b"Potential Scam" in response.data
    assert b"Machine learning prediction" in response.data
    assert b"Detected warning indicators" in response.data
    assert b"100%" not in response.data


def test_analyze_form_empty_message(client):
    response = client.post("/analyze", data={"message": "  "})
    assert response.status_code == 400
    assert b"Please enter a message" in response.data


@needs_model
def test_user_text_is_escaped(client):
    response = client.post("/analyze", data={"message": "<script>alert(1)</script> win a prize"})
    assert b"<script>alert(1)</script>" not in response.data
    assert b"&lt;script&gt;" in response.data


def test_analyze_form_requires_csrf_token():
    class CsrfConfig(TestConfig):
        WTF_CSRF_ENABLED = True
    client = create_app(CsrfConfig).test_client()
    response = client.post("/analyze", data={"message": "hello"})
    assert response.status_code == 400
    assert b"expired" in response.data


def test_api_works_with_csrf_enabled():
    """The JSON API is deliberately CSRF-exempt (see app.py)."""
    class CsrfConfig(TestConfig):
        WTF_CSRF_ENABLED = True
    client = create_app(CsrfConfig).test_client()
    response = client.post("/api/predict", json={"message": ""})
    assert response.status_code == 400   # reached validation, not blocked by CSRF

"""
test_api.py - Tests for the Flask application.

Basic tests (pages load, health check, error handling).
More tests (prediction API, invalid input, database) are added later.

Run with:  pytest -v
"""

import pytest

from app import create_app
from config import TestConfig


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

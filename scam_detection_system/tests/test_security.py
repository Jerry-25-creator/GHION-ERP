"""
test_security.py - Tests for rate limiting, security headers, request size
limits and other security behaviour.

Run with:  pytest -v tests/test_security.py
"""

import pytest

import db
from app import create_app
from config import TestConfig
from security import RateLimiter


# ----------------------------------------------------------------------
# RateLimiter unit tests
# ----------------------------------------------------------------------
def test_rate_limiter_allows_up_to_the_limit():
    limiter = RateLimiter(max_events=3, window_seconds=60)
    assert [limiter.hit("ip") for _ in range(4)] == [True, True, True, False]
    assert limiter.is_limited("ip")
    assert limiter.retry_after("ip") > 0


def test_rate_limiter_keys_are_independent():
    limiter = RateLimiter(max_events=1, window_seconds=60)
    assert limiter.hit("a")
    assert limiter.hit("b")
    assert not limiter.hit("a")


def test_rate_limiter_window_expires(monkeypatch):
    clock = [1000.0]
    monkeypatch.setattr("security.time.monotonic", lambda: clock[0])
    limiter = RateLimiter(max_events=2, window_seconds=60)
    limiter.hit("ip"); limiter.hit("ip")
    assert limiter.is_limited("ip")
    clock[0] += 61                      # one minute later
    assert not limiter.is_limited("ip")


def test_rate_limiter_reset():
    limiter = RateLimiter(max_events=1, window_seconds=60)
    limiter.record("ip")
    limiter.reset("ip")
    assert not limiter.is_limited("ip")


# ----------------------------------------------------------------------
# Login lockout
# ----------------------------------------------------------------------
class LoginLimitConfig(TestConfig):
    LOGIN_MAX_ATTEMPTS = 3


def make_admin(app):
    with app.app_context():
        db.create_or_update_admin("admin", "CorrectHorse42")


def test_login_is_locked_after_repeated_failures():
    app = create_app(LoginLimitConfig)
    make_admin(app)
    client = app.test_client()
    for _ in range(3):
        assert client.post("/login", data={"username": "admin", "password": "wrong-pass1"}).status_code == 401
    # Even the correct password is refused while locked out.
    response = client.post("/login", data={"username": "admin", "password": "CorrectHorse42"})
    assert response.status_code == 429
    assert b"Too many failed login attempts" in response.data


def test_successful_login_resets_failure_count():
    app = create_app(LoginLimitConfig)
    make_admin(app)
    client = app.test_client()
    for _ in range(2):
        client.post("/login", data={"username": "admin", "password": "wrong-pass1"})
    assert client.post("/login", data={"username": "admin", "password": "CorrectHorse42"}).status_code == 302
    client.post("/logout")
    for _ in range(2):
        assert client.post("/login", data={"username": "admin", "password": "wrong-pass1"}).status_code == 401


# ----------------------------------------------------------------------
# Analysis rate limit
# ----------------------------------------------------------------------
def test_api_rate_limit():
    class LowLimitConfig(TestConfig):
        ANALYSIS_RATE_LIMIT_PER_MINUTE = 2
    client = create_app(LowLimitConfig).test_client()
    codes = [client.post("/api/predict", json={"message": ""}).status_code for _ in range(3)]
    assert codes == [400, 400, 429]
    assert "error" in client.post("/api/predict", json={"message": "x"}).get_json()


def test_form_rate_limit_shows_friendly_page():
    class LowLimitConfig(TestConfig):
        ANALYSIS_RATE_LIMIT_PER_MINUTE = 1
    client = create_app(LowLimitConfig).test_client()
    client.post("/analyze", data={"message": ""})
    response = client.post("/analyze", data={"message": ""})
    assert response.status_code == 429
    assert b"Too many requests" in response.data


# ----------------------------------------------------------------------
# Headers, size limits, static files
# ----------------------------------------------------------------------
@pytest.fixture
def client():
    return create_app(TestConfig).test_client()


def test_security_headers(client):
    headers = client.get("/").headers
    csp = headers["Content-Security-Policy"]
    assert "default-src 'self'" in csp and "frame-ancestors 'none'" in csp
    assert "http" not in csp                     # nothing loaded from other sites
    assert headers["X-Frame-Options"] == "DENY"
    assert headers["X-Content-Type-Options"] == "nosniff"
    assert "camera=()" in headers["Permissions-Policy"]
    assert "Strict-Transport-Security" not in headers   # only sent over HTTPS


def test_hsts_sent_over_https(client):
    headers = client.get("/", base_url="https://localhost").headers
    assert "max-age" in headers["Strict-Transport-Security"]


def test_request_too_large(client):
    response = client.post("/api/predict", data="x" * (TestConfig.MAX_CONTENT_LENGTH + 1),
                           content_type="application/json")
    assert response.status_code == 413


def test_no_external_resources_in_pages(client):
    page = client.get("/analyze").data
    assert b"cdn.jsdelivr.net" not in page
    assert client.get("/static/vendor/bootstrap-5.3.3/bootstrap.min.css").status_code == 200


def test_session_cookie_flags():
    app = create_app(TestConfig)
    assert app.config["SESSION_COOKIE_HTTPONLY"] is True
    assert app.config["SESSION_COOKIE_SAMESITE"] == "Lax"


def test_about_page(client):
    response = client.get("/about")
    assert response.status_code == 200
    assert b"Limitations" in response.data


# ----------------------------------------------------------------------
# Error handling
# ----------------------------------------------------------------------
def test_server_error_shows_no_stack_trace():
    class NoPropagateConfig(TestConfig):
        PROPAGATE_EXCEPTIONS = False
    app = create_app(NoPropagateConfig)

    @app.route("/boom")
    def boom():
        raise RuntimeError("secret internal detail")

    response = app.test_client().get("/boom")
    assert response.status_code == 500
    assert b"Something went wrong" in response.data
    assert b"secret internal detail" not in response.data
    assert b"Traceback" not in response.data


def test_unusable_database_does_not_break_the_site(tmp_path):
    class BrokenDbConfig(TestConfig):
        DATABASE_PATH = str(tmp_path)          # a folder, not a file -> cannot be opened
    app = create_app(BrokenDbConfig)
    assert app.extensions["db_error"]
    client = app.test_client()
    assert client.get("/").status_code == 200
    assert client.post("/api/predict", json={"message": ""}).status_code == 400
    assert client.get("/login").status_code == 200    # shows "database not available"

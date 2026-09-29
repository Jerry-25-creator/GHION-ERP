"""
test_database.py - Tests for database storage, the admin login and the
dashboard/history pages.

Run with:  pytest -v
"""

import os
import sqlite3

import pytest

import db
from app import create_app
from config import Config, TestConfig
from create_admin import password_problem

MODEL_MISSING = not (os.path.exists(Config.MODEL_PATH) and os.path.exists(Config.VECTORIZER_PATH))
needs_model = pytest.mark.skipif(MODEL_MISSING, reason="Model not trained - run python train_model.py")

ADMIN_USER = "testadmin"
ADMIN_PASSWORD = "CorrectHorse42"

FAKE_RESULT = {"prediction": "scam", "confidence": 0.93, "scam_probability": 0.93,
               "indicators": [{"name": "Urgent language", "explanation": "..."}]}


@pytest.fixture
def app():
    return create_app(TestConfig)


@pytest.fixture
def client(app):
    return app.test_client()


@pytest.fixture
def admin_client(app, client):
    """A test client that is already logged in as an admin."""
    with app.app_context():
        db.create_or_update_admin(ADMIN_USER, ADMIN_PASSWORD)
    response = client.post("/login", data={"username": ADMIN_USER, "password": ADMIN_PASSWORD})
    assert response.status_code == 302
    return client


# ----------------------------------------------------------------------
# Storage
# ----------------------------------------------------------------------
def test_tables_are_created(app):
    conn = sqlite3.connect(app.config["DATABASE_PATH"])
    tables = {row[0] for row in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    conn.close()
    assert {"analysis_history", "admin_users"} <= tables


def test_save_and_read_analysis(app):
    with app.app_context():
        new_id = db.save_analysis("Win a prize now", FAKE_RESULT, "web", "Test model")
        recent = db.get_recent_analyses()
    assert recent[0]["id"] == new_id
    assert recent[0]["prediction"] == "scam"
    assert recent[0]["confidence"] == pytest.approx(0.93)
    assert recent[0]["indicators"] == ["Urgent language"]
    assert recent[0]["analyzed_at"]


def test_statistics(app):
    legit = dict(FAKE_RESULT, prediction="legitimate")
    with app.app_context():
        assert db.get_statistics()["total"] == 0
        db.save_analysis("a", FAKE_RESULT, "web", None)
        db.save_analysis("b", FAKE_RESULT, "api", None)
        db.save_analysis("c", legit, "web", None)
        stats = db.get_statistics()
    assert (stats["total"], stats["scam"], stats["legitimate"], stats["api"]) == (3, 2, 1, 1)


def test_personal_data_is_redacted_before_storage(app):
    with app.app_context():
        db.save_analysis("Call 0772 123 456 or mail me@example.com", FAKE_RESULT, "web", None)
        stored = db.get_recent_analyses()[0]["message"]
    assert "0772" not in stored and "me@example.com" not in stored
    assert "[number]" in stored and "[email]" in stored


def test_sql_injection_is_stored_as_plain_text(app):
    evil = "x'); DROP TABLE analysis_history; --"
    with app.app_context():
        db.save_analysis(evil, FAKE_RESULT, "web", None)
        assert db.get_recent_analyses()[0]["message"] == evil
        assert db.get_statistics()["total"] == 1   # table still exists


def test_history_paging_and_filter(app):
    legit = dict(FAKE_RESULT, prediction="legitimate")
    with app.app_context():
        for i in range(25):
            db.save_analysis(f"msg {i}", FAKE_RESULT if i % 5 == 0 else legit, "web", None)
        page1, total = db.get_history(page=1, per_page=20)
        page2, _ = db.get_history(page=2, per_page=20)
        scams, scam_total = db.get_history(prediction="scam")
    assert total == 25 and len(page1) == 20 and len(page2) == 5
    assert scam_total == 5 and all(item["prediction"] == "scam" for item in scams)


@needs_model
def test_prediction_is_stored(client, app):
    client.post("/api/predict", json={"message": "You have won a prize, claim now"})
    client.post("/analyze", data={"message": "See you at lunch"})
    with app.app_context():
        stats = db.get_statistics()
    assert stats["total"] == 2 and stats["api"] == 1


@needs_model
def test_prediction_still_works_if_database_fails(client, app, monkeypatch):
    def broken(*args, **kwargs):
        raise db.DatabaseError("simulated failure")
    monkeypatch.setattr(db, "save_analysis", broken)
    response = client.post("/api/predict", json={"message": "Hello, how are you?"})
    assert response.status_code == 200


# ----------------------------------------------------------------------
# Admin accounts and login
# ----------------------------------------------------------------------
def test_password_is_hashed(app):
    with app.app_context():
        db.create_or_update_admin(ADMIN_USER, ADMIN_PASSWORD)
        row = db._run("SELECT password_hash FROM admin_users", fetch="one")
    assert ADMIN_PASSWORD not in row["password_hash"]
    assert row["password_hash"].startswith(("scrypt:", "pbkdf2:"))


def test_verify_admin(app):
    with app.app_context():
        db.create_or_update_admin(ADMIN_USER, ADMIN_PASSWORD)
        assert db.verify_admin(ADMIN_USER, ADMIN_PASSWORD) is not None
        assert db.verify_admin(ADMIN_USER, "wrong-password1") is None
        assert db.verify_admin("nobody", ADMIN_PASSWORD) is None


def test_password_rules():
    assert password_problem("short1", "admin") is not None
    assert password_problem("onlyletterslong", "admin") is not None
    assert password_problem("admin12345", "admin12345") is not None
    assert password_problem("CorrectHorse42", "admin") is None


def test_wrong_password_is_rejected(app, client):
    with app.app_context():
        db.create_or_update_admin(ADMIN_USER, ADMIN_PASSWORD)
    response = client.post("/login", data={"username": ADMIN_USER, "password": "nope-nope-1"})
    assert response.status_code == 401
    assert b"Incorrect username or password" in response.data


def test_login_page_explains_missing_admin(client):
    assert b"create_admin.py" in client.get("/login").data


@pytest.mark.parametrize("url", ["/dashboard", "/history"])
def test_admin_pages_require_login(client, url):
    response = client.get(url)
    assert response.status_code == 302
    assert "/login" in response.headers["Location"]


def test_delete_requires_login(client):
    assert client.post("/history/1/delete").status_code == 302


def test_dashboard_after_login(admin_client, app):
    with app.app_context():
        db.save_analysis("<b>bold</b> prize", FAKE_RESULT, "web", None)
    response = admin_client.get("/dashboard")
    assert response.status_code == 200
    assert b"Messages analysed" in response.data
    assert b"Recent analyses" in response.data
    assert b"Model performance" in response.data
    assert b"<b>bold</b>" not in response.data          # stored text is escaped
    assert response.headers["Cache-Control"] == "no-store"


def test_history_and_delete(admin_client, app):
    with app.app_context():
        record_id = db.save_analysis("Delete me please", FAKE_RESULT, "web", None)
    assert b"Delete me please" in admin_client.get("/history").data
    response = admin_client.post(f"/history/{record_id}/delete", follow_redirects=True)
    assert b"deleted" in response.data
    assert b"Delete me please" not in response.data


def test_logout(admin_client):
    admin_client.post("/logout")
    assert admin_client.get("/dashboard").status_code == 302


def test_login_form_requires_csrf_token(app):
    class CsrfConfig(TestConfig):
        WTF_CSRF_ENABLED = True
    client = create_app(CsrfConfig).test_client()
    response = client.post("/login", data={"username": "a", "password": "b"})
    assert response.status_code == 400


def test_dashboard_never_shows_100_percent(admin_client, app):
    certain = dict(FAKE_RESULT, confidence=0.9991)
    with app.app_context():
        db.save_analysis("Free prize", certain, "web", None)
    page = admin_client.get("/dashboard").data
    assert b"over 99%" in page
    assert b">100%<" not in page

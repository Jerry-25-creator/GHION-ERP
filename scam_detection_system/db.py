"""
db.py - All database access for the Scam Detection System (SQLite).

Design rules:
    * Every query uses "?" placeholders. User text is NEVER inserted into
      SQL strings directly, which prevents SQL injection.
    * All SQL is kept in this one file, so moving to MySQL later means
      changing this file (connection + "%s" placeholders) and running
      database/schema_mysql.sql - the rest of the app stays the same.
    * One connection per web request, stored in Flask's "g" object and
      closed automatically at the end of the request.
"""

import json
import os
import re
import sqlite3
from datetime import datetime, timezone

from flask import current_app, g
from werkzeug.security import check_password_hash, generate_password_hash

from scam_detector.preprocessing import EMAIL_RE, PHONE_RE

SCHEMA_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "database", "schema.sql")

# Used when a username does not exist, so a failed login takes the same time
# whether or not the username is valid (hides which usernames exist).
_DUMMY_HASH = generate_password_hash("dummy-password-for-timing")


class DatabaseError(Exception):
    """A friendly wrapper around low-level sqlite3 errors."""


def utc_now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


# ----------------------------------------------------------------------
# Connection handling
# ----------------------------------------------------------------------
def connect(path):
    conn = sqlite3.connect(path, timeout=5)
    conn.row_factory = sqlite3.Row          # rows behave like dictionaries
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def get_db():
    """Return this request's database connection (opened on first use)."""
    if "db" not in g:
        try:
            g.db = connect(current_app.config["DATABASE_PATH"])
        except sqlite3.Error as error:
            raise DatabaseError("Could not open the database.") from error
    return g.db


def close_db(exception=None):
    db = g.pop("db", None)
    if db is not None:
        db.close()


def init_app(app):
    """Create the database file and tables (if needed) and register clean-up."""
    app.teardown_appcontext(close_db)
    path = app.config["DATABASE_PATH"]
    try:
        os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
        with open(SCHEMA_PATH, encoding="utf-8") as f:
            schema = f.read()
        conn = connect(path)
        with conn:
            conn.executescript(schema)
        conn.close()
        app.extensions["db_error"] = None
    except (OSError, sqlite3.Error) as error:
        # The app still runs (predictions work); history is just not saved.
        app.logger.error("Database initialisation failed: %s", error)
        app.extensions["db_error"] = str(error)


def _run(query, params=(), fetch=None, commit=False):
    """Execute one query safely. fetch = None | "one" | "all"."""
    try:
        db = get_db()
        cursor = db.execute(query, params)
        if commit:
            db.commit()
        if fetch == "one":
            return cursor.fetchone()
        if fetch == "all":
            return cursor.fetchall()
        return cursor
    except sqlite3.Error as error:
        current_app.logger.error("Database error: %s", error)
        raise DatabaseError("A database error occurred.") from error


# ----------------------------------------------------------------------
# Privacy
# ----------------------------------------------------------------------
def redact_personal_data(message):
    """
    Replace e-mail addresses and long numbers (phone, account or card
    numbers) with placeholders before storing a message. The prediction
    is made on the ORIGINAL text; only the stored copy is redacted.
    """
    message = EMAIL_RE.sub("[email]", message)
    return PHONE_RE.sub("[number]", message)


# ----------------------------------------------------------------------
# analysis_history
# ----------------------------------------------------------------------
def save_analysis(message, result, source, model_name):
    """Store one analysis. Returns the new row id."""
    if current_app.config.get("REDACT_STORED_MESSAGES", True):
        message = redact_personal_data(message)
    cursor = _run(
        """INSERT INTO analysis_history
               (message, prediction, confidence, scam_probability,
                indicators, source, model_name, analyzed_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
        (message, result["prediction"], round(result["confidence"], 4),
         round(result["scam_probability"], 4),
         json.dumps([ind["name"] for ind in result["indicators"]]),
         source, model_name, utc_now()),
        commit=True,
    )
    return cursor.lastrowid


def get_statistics():
    """Totals used by the dashboard."""
    row = _run(
        """SELECT COUNT(*) AS total,
                  COALESCE(SUM(CASE WHEN prediction = 'scam' THEN 1 ELSE 0 END), 0) AS scam,
                  COALESCE(SUM(CASE WHEN prediction = 'legitimate' THEN 1 ELSE 0 END), 0) AS legitimate,
                  COALESCE(SUM(CASE WHEN source = 'api' THEN 1 ELSE 0 END), 0) AS api,
                  AVG(confidence) AS avg_confidence
           FROM analysis_history""",
        fetch="one",
    )
    stats = dict(row)
    total = stats["total"]
    stats["scam_percent"] = (stats["scam"] / total * 100) if total else 0.0
    stats["legitimate_percent"] = (stats["legitimate"] / total * 100) if total else 0.0
    return stats


def _to_dict(row):
    item = dict(row)
    item["indicators"] = json.loads(item["indicators"] or "[]")
    return item


def get_recent_analyses(limit=10):
    rows = _run("SELECT * FROM analysis_history ORDER BY id DESC LIMIT ?", (limit,), fetch="all")
    return [_to_dict(r) for r in rows]


def get_history(page=1, per_page=20, prediction=None):
    """One page of history, newest first, optionally filtered by prediction.
    Returns (items, total_count)."""
    # "where" is one of two FIXED strings chosen here - user input only ever
    # travels through the "?" parameters.
    where, params = "", []
    if prediction in ("scam", "legitimate"):
        where, params = "WHERE prediction = ?", [prediction]
    total = _run(f"SELECT COUNT(*) FROM analysis_history {where}", params, fetch="one")[0]
    rows = _run(
        f"SELECT * FROM analysis_history {where} ORDER BY id DESC LIMIT ? OFFSET ?",
        params + [per_page, (page - 1) * per_page], fetch="all",
    )
    return [_to_dict(r) for r in rows], total


def delete_analysis(analysis_id):
    """Delete one record. Returns True if a row was deleted."""
    cursor = _run("DELETE FROM analysis_history WHERE id = ?", (analysis_id,), commit=True)
    return cursor.rowcount > 0


# ----------------------------------------------------------------------
# admin_users
# ----------------------------------------------------------------------
def create_or_update_admin(username, password):
    """Create an admin, or change the password if the username exists.
    Returns "created" or "updated". The password is stored only as a hash."""
    password_hash = generate_password_hash(password)   # salted scrypt hash
    existing = _run("SELECT id FROM admin_users WHERE username = ?", (username,), fetch="one")
    if existing:
        _run("UPDATE admin_users SET password_hash = ? WHERE id = ?",
             (password_hash, existing["id"]), commit=True)
        return "updated"
    _run("INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)",
         (username, password_hash, utc_now()), commit=True)
    return "created"


def count_admins():
    return _run("SELECT COUNT(*) FROM admin_users", fetch="one")[0]


def verify_admin(username, password):
    """Return the admin row if the username and password are correct, else None."""
    user = _run("SELECT * FROM admin_users WHERE username = ?", (username,), fetch="one")
    if user is None:
        check_password_hash(_DUMMY_HASH, password)   # same work as a real check
        return None
    if not check_password_hash(user["password_hash"], password):
        return None
    _run("UPDATE admin_users SET last_login_at = ? WHERE id = ?", (utc_now(), user["id"]), commit=True)
    return user


def get_admin(admin_id):
    return _run("SELECT id, username, last_login_at FROM admin_users WHERE id = ?",
                (admin_id,), fetch="one")

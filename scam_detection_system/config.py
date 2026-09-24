"""
config.py - Central configuration for the Scam Detection System.

All settings are read from environment variables (loaded from the .env
file by python-dotenv), so no secrets are hard-coded in the source code.
"""

import os
import secrets
import warnings

from dotenv import load_dotenv

# Absolute path of the project folder, so paths work no matter which
# directory the app is started from.
BASE_DIR = os.path.abspath(os.path.dirname(__file__))

# Load variables from .env (if it exists) into os.environ.
load_dotenv(os.path.join(BASE_DIR, ".env"))


def _get_int(name, default):
    """Read an integer environment variable, falling back to a default."""
    try:
        return int(os.environ.get(name, default))
    except ValueError:
        return default


def _get_secret_key():
    """
    Return SECRET_KEY from the environment.

    If it is missing, generate a random temporary key so the app still
    starts during development. Sessions will not survive a restart in that
    case, so a warning is shown.
    """
    key = os.environ.get("SECRET_KEY", "")
    if not key or key == "replace-me-with-a-long-random-value":
        warnings.warn(
            "SECRET_KEY is not set in .env - using a temporary random key. "
            "See .env.example for how to generate one."
        )
        key = secrets.token_hex(32)
    return key


class Config:
    """Default configuration used by app.py."""

    SECRET_KEY = _get_secret_key()
    DEBUG = os.environ.get("FLASK_DEBUG", "0") == "1"
    HOST = os.environ.get("FLASK_HOST", "127.0.0.1")
    PORT = _get_int("FLASK_PORT", 5000)

    # --- Input limits -------------------------------------------------
    # Longest message (in characters) the system will analyse.
    MAX_MESSAGE_LENGTH = _get_int("MAX_MESSAGE_LENGTH", 5000)
    # Hard limit on the size of any HTTP request body (64 KB).
    MAX_CONTENT_LENGTH = 64 * 1024

    # --- File locations -----------------------------------------------
    DATA_PATH = os.path.join(BASE_DIR, "data", "scam_dataset.csv")
    MODEL_DIR = os.path.join(BASE_DIR, "models")
    MODEL_PATH = os.path.join(MODEL_DIR, "model.pkl")
    VECTORIZER_PATH = os.path.join(MODEL_DIR, "vectorizer.pkl")
    DATABASE_PATH = os.path.join(BASE_DIR, "database", "database.db")

    # --- Security -----------------------------------------------------
    WTF_CSRF_ENABLED = True
    SESSION_COOKIE_HTTPONLY = True
    SESSION_COOKIE_SAMESITE = "Lax"


class TestConfig(Config):
    """Configuration used by the automated tests."""

    TESTING = True
    WTF_CSRF_ENABLED = False

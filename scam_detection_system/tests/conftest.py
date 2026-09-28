"""
conftest.py - Shared pytest fixtures.

Every test gets its own empty temporary database, so tests never touch
the real database/database.db and cannot affect each other.
"""

import pytest

from config import TestConfig


@pytest.fixture(autouse=True)
def temporary_database(tmp_path, monkeypatch):
    monkeypatch.setattr(TestConfig, "DATABASE_PATH", str(tmp_path / "test.db"))

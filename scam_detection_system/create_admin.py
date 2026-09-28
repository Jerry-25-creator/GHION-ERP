"""
create_admin.py - Create an administrator account (or change its password).

Usage (Windows):
    python create_admin.py

You will be asked for a username and a password. The password is typed
invisibly and stored only as a salted hash - it is never written to any
file in plain text, and no default password exists in the code.
"""

import getpass
import re
import sys

import db
from app import create_app

MIN_PASSWORD_LENGTH = 10
USERNAME_RE = re.compile(r"^[A-Za-z0-9_.-]{3,50}$")


def password_problem(password, username):
    """Return a description of what is wrong with the password, or None."""
    if len(password) < MIN_PASSWORD_LENGTH:
        return f"Password must be at least {MIN_PASSWORD_LENGTH} characters."
    if password.lower() == username.lower():
        return "Password must not be the same as the username."
    if not (re.search(r"[A-Za-z]", password) and re.search(r"\d", password)):
        return "Password must contain both letters and numbers."
    return None


def main():
    print("Create or update an administrator account\n")
    username = input("Username: ").strip()
    if not USERNAME_RE.match(username):
        print("Username must be 3-50 characters: letters, numbers, _ . or -")
        sys.exit(1)

    password = getpass.getpass(f"Password (min {MIN_PASSWORD_LENGTH} characters, letters + numbers): ")
    problem = password_problem(password, username)
    if problem:
        print(problem)
        sys.exit(1)
    if getpass.getpass("Repeat password: ") != password:
        print("Passwords do not match.")
        sys.exit(1)

    app = create_app()
    if app.extensions.get("db_error"):
        print(f"Database error: {app.extensions['db_error']}")
        sys.exit(1)
    with app.app_context():
        action = db.create_or_update_admin(username, password)
    print(f"\nAdmin account '{username}' {action}. Log in at http://127.0.0.1:5000/login")


if __name__ == "__main__":
    main()

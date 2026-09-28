"""
admin.py - Administrator pages (Flask Blueprint).

    /login                        GET form, POST check username + password
    /logout                       POST - end the session
    /dashboard                    statistics, recent analyses, model performance
    /history                      all stored analyses (paged, filterable)
    /history/<id>/delete          POST - delete one record

Authentication (kept simple for the first version):
    * Admin accounts are created with "python create_admin.py".
    * Passwords are checked against salted hashes (db.verify_admin).
    * After login, only the admin's id is stored in Flask's signed session
      cookie. The session expires after ADMIN_SESSION_MINUTES.
    * All POST forms carry a CSRF token (Flask-WTF CSRFProtect).
"""

from functools import wraps

from flask import (Blueprint, abort, current_app, flash, g, redirect, render_template,
                   request, session, url_for)

import db

bp = Blueprint("admin", __name__)

HISTORY_PAGE_SIZE = 20


def login_required(view):
    """Decorator: only logged-in admins may open the page."""
    @wraps(view)
    def wrapped(*args, **kwargs):
        if g.get("admin") is None:
            flash("Please log in to view that page.", "info")
            return redirect(url_for("admin.login"))
        return view(*args, **kwargs)
    return wrapped


@bp.before_app_request
def load_logged_in_admin():
    """Before every request, look up the admin stored in the session (if any)."""
    g.admin = None
    admin_id = session.get("admin_id")
    if admin_id is not None:
        try:
            g.admin = db.get_admin(admin_id)
        except db.DatabaseError:
            g.admin = None
        if g.admin is None:          # account deleted or database unavailable
            session.clear()


@bp.app_context_processor
def inject_admin():
    """Make "current_admin" available in every template (used by the navbar)."""
    return {"current_admin": g.get("admin")}


@bp.route("/login", methods=["GET", "POST"])
def login():
    if g.get("admin"):
        return redirect(url_for("admin.dashboard"))

    error = None
    no_admin_yet = False
    try:
        no_admin_yet = db.count_admins() == 0
    except db.DatabaseError:
        error = "The database is not available. Please try again later."

    if request.method == "POST" and error is None:
        username = request.form.get("username", "").strip()[:50]
        password = request.form.get("password", "")[:200]
        user = db.verify_admin(username, password) if username and password else None
        if user is None:
            # Same message for a wrong username or wrong password, so the page
            # does not reveal which usernames exist.
            error = "Incorrect username or password."
            current_app.logger.warning("Failed admin login attempt")
        else:
            session.clear()                   # new session -> prevents session fixation
            session["admin_id"] = user["id"]
            session.permanent = True          # expires after PERMANENT_SESSION_LIFETIME
            return redirect(url_for("admin.dashboard"))

    status = 401 if request.method == "POST" and error else 200
    return render_template("login.html", error=error, no_admin_yet=no_admin_yet), status


@bp.route("/logout", methods=["POST"])
def logout():
    session.clear()
    flash("You have been logged out.", "info")
    return redirect(url_for("index"))


@bp.route("/dashboard")
@login_required
def dashboard():
    predictor = current_app.extensions.get("predictor")
    evaluation = predictor.evaluation if predictor else {}
    return render_template(
        "dashboard.html",
        stats=db.get_statistics(),
        recent=db.get_recent_analyses(10),
        evaluation=evaluation,
        model_error=current_app.extensions.get("predictor_error"),
    )


@bp.route("/history")
@login_required
def history():
    page = max(request.args.get("page", 1, type=int), 1)
    prediction = request.args.get("prediction")
    if prediction not in ("scam", "legitimate"):
        prediction = None
    items, total = db.get_history(page, HISTORY_PAGE_SIZE, prediction)
    pages = max((total + HISTORY_PAGE_SIZE - 1) // HISTORY_PAGE_SIZE, 1)
    if page > pages:
        abort(404)
    return render_template("history.html", items=items, total=total, page=page,
                           pages=pages, prediction=prediction)


@bp.route("/history/<int:analysis_id>/delete", methods=["POST"])
@login_required
def delete_record(analysis_id):
    if db.delete_analysis(analysis_id):
        flash(f"Record #{analysis_id} deleted.", "success")
    else:
        flash(f"Record #{analysis_id} was not found.", "warning")
    # Return to the same page/filter only if it is a local history URL.
    back = request.form.get("next", "")
    if not back.startswith("/history"):
        back = url_for("admin.history")
    return redirect(back)

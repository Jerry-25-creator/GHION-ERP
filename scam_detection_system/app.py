"""
app.py - Flask web application for the Scam Detection System.

Pages:   /            home page
         /analyze     form to analyse a message (GET shows the form, POST shows the result)
API:     /api/health  GET  - is the server running?
         /api/predict POST - analyse a message sent as JSON
Admin:   /login, /dashboard, /history   (see admin.py)

Every analysis is stored in the SQLite database (see db.py).

The trained model (models/model.pkl + models/vectorizer.pkl) is loaded once
when the app starts. If it is missing, the pages still work and the user is
told to train the model first.

Run with:  python app.py
"""

import os
import secrets
import warnings
from datetime import datetime

from flask import Flask, current_app, jsonify, render_template, request
from flask_wtf.csrf import CSRFError, CSRFProtect

import admin
import db
from config import Config
from scam_detector.predictor import SCAM, ModelNotAvailableError, ScamPredictor

DISCLAIMER = ("This is an automated assessment by a machine learning model, not a "
              "guarantee. Always verify suspicious messages independently.")

# CSRF protection object; attached to the app inside create_app().
csrf = CSRFProtect()


def create_app(config_class=Config):
    """
    Application factory: builds and configures the Flask app.

    Using a factory (instead of a global app) lets the tests create an app
    with a different configuration (see config.TestConfig).
    """
    app = Flask(__name__)
    app.config.from_object(config_class)
    ensure_secret_key(app)
    csrf.init_app(app)
    load_predictor(app)
    db.init_app(app)

    register_routes(app)
    app.register_blueprint(admin.bp)
    register_error_handlers(app)
    register_security_headers(app)
    register_template_filters(app)
    return app


def ensure_secret_key(app):
    """
    Use SECRET_KEY from .env. If it is missing, generate a random temporary
    key so the app still starts during development; sessions and CSRF tokens
    then become invalid whenever the server restarts.
    """
    key = app.config.get("SECRET_KEY", "")
    if not key or key == "replace-me-with-a-long-random-value":
        warnings.warn("SECRET_KEY is not set in .env - using a temporary random key. "
                      "See .env.example for how to generate one.")
        app.config["SECRET_KEY"] = secrets.token_hex(32)


def load_predictor(app):
    """Load the trained model once at start-up and keep it in app.extensions."""
    try:
        app.extensions["predictor"] = ScamPredictor(
            app.config["MODEL_PATH"], app.config["VECTORIZER_PATH"],
            os.path.join(app.config["MODEL_DIR"], "evaluation_results.json"),
        )
        app.extensions["predictor_error"] = None
    except ModelNotAvailableError as error:
        app.logger.warning("Model not loaded: %s", error)
        app.extensions["predictor"] = None
        app.extensions["predictor_error"] = str(error)


def get_predictor():
    """Return the loaded predictor, or raise ModelNotAvailableError."""
    predictor = current_app.extensions.get("predictor")
    if predictor is None:
        raise ModelNotAvailableError(current_app.extensions.get("predictor_error")
                                     or "The model is not available.")
    return predictor


# ----------------------------------------------------------------------
# Input validation
# ----------------------------------------------------------------------
def validate_message(value, max_length):
    """
    Check a submitted message. Returns (cleaned_message, error_text);
    exactly one of them is None.
    """
    if not isinstance(value, str):
        return None, "The message must be text."
    # Remove invisible control characters (except new lines and tabs).
    message = "".join(ch for ch in value if ch in "\n\t" or ch.isprintable()).strip()
    if not message:
        return None, "Please enter a message to analyze."
    if len(message) > max_length:
        return None, (f"The message is too long ({len(message)} characters). "
                      f"The maximum is {max_length} characters.")
    return message, None


def format_confidence(confidence):
    """0.937 -> '94%'. Never shows 100%: the model can never be completely certain."""
    return "over 99%" if confidence >= 0.995 else f"{confidence:.0%}"


def describe_result(result):
    """Turn a raw prediction into the wording and styling shown on the page."""
    confidence = result["confidence"]
    percent = format_confidence(confidence)
    if confidence >= 0.9:
        certainty = "High"
    elif confidence >= 0.7:
        certainty = "Moderate"
    else:
        certainty = "Low"

    if result["prediction"] == SCAM:
        title = "Potential Scam"
        summary = ("Our model detected patterns commonly associated with scam messages. "
                   "Verify the sender and information before taking action.")
        css = "result-scam"
    else:
        title = "No obvious scam patterns detected"
        summary = ("Our model did not find patterns commonly associated with scam messages. "
                   "This does not guarantee the message is safe - stay careful with links, "
                   "payment requests and requests for personal details.")
        css = "result-legitimate"

    return {"title": title, "summary": summary, "css": css, "percent": percent,
            "certainty": certainty, "confidence_width": round(confidence * 100)}


def store_analysis(message, result, source):
    """
    Save an analysis to the history table. A database problem must not stop
    the user from getting their result, so errors are logged, not raised.
    """
    try:
        predictor = current_app.extensions.get("predictor")
        db.save_analysis(message, result, source, predictor.model_name if predictor else None)
    except db.DatabaseError:
        current_app.logger.error("Analysis could not be saved to the history")


# ----------------------------------------------------------------------
# Routes
# ----------------------------------------------------------------------
def register_routes(app):

    @app.route("/")
    def index():
        """Landing page."""
        return render_template("index.html")

    @app.route("/analyze", methods=["GET", "POST"])
    def analyze():
        """GET: show the form. POST: analyse the submitted message (CSRF-protected)."""
        max_length = app.config["MAX_MESSAGE_LENGTH"]
        if request.method == "GET":
            return render_template("analyze.html", max_length=max_length)

        submitted = request.form.get("message", "")
        message, error = validate_message(submitted, max_length)
        if error:
            return render_template("analyze.html", max_length=max_length,
                                   error=error, message=submitted), 400
        try:
            predictor = get_predictor()
            result = predictor.predict(message)
        except ModelNotAvailableError:
            return render_template(
                "analyze.html", max_length=max_length, message=submitted,
                error="The analysis model is not available yet. "
                      "Please ask the administrator to train it (python train_model.py).",
            ), 503

        store_analysis(message, result, "web")
        return render_template("result.html", message=message, result=result,
                               view=describe_result(result),
                               model_name=predictor.model_name,
                               test_metrics=predictor.test_metrics)

    @app.route("/api/predict", methods=["POST"])
    @csrf.exempt
    def api_predict():
        """
        Analyse a message sent as JSON: {"message": "..."}

        CSRF note: this endpoint is exempt from CSRF tokens because it is a
        stateless JSON API for other programs (e.g. a future mobile app). It
        uses no login or cookies, so a forged request gains nothing an
        attacker could not do directly. Requiring a JSON content type also
        stops ordinary HTML forms on other websites from posting to it.
        """
        if not request.is_json:
            return jsonify({"error": 'Send JSON with the header "Content-Type: application/json".'}), 415
        data = request.get_json(silent=True)
        if not isinstance(data, dict) or "message" not in data:
            return jsonify({"error": 'Request body must be a JSON object like {"message": "text"}.'}), 400

        message, error = validate_message(data["message"], app.config["MAX_MESSAGE_LENGTH"])
        if error:
            return jsonify({"error": error}), 400
        try:
            result = get_predictor().predict(message)
        except ModelNotAvailableError:
            return jsonify({"error": "The model is not available. Train it with python train_model.py."}), 503

        store_analysis(message, result, "api")
        return jsonify({
            "prediction": result["prediction"],
            "confidence": round(result["confidence"], 4),
            "scam_probability": round(result["scam_probability"], 4),
            "indicators": [ind["name"] for ind in result["indicators"]],
            "disclaimer": DISCLAIMER,
        })

    @app.route("/api/health")
    def health():
        """Simple health check used to confirm the server is running."""
        return jsonify({"status": "ok"})


# ----------------------------------------------------------------------
# Error handling - show friendly messages instead of stack traces
# ----------------------------------------------------------------------
def _wants_json():
    """True if the request came to the API or expects a JSON reply."""
    return request.path.startswith("/api/") or request.is_json


def register_error_handlers(app):

    def error_response(status, title, message):
        if _wants_json():
            return jsonify({"error": message}), status
        return (
            render_template("error.html", status=status, title=title, message=message),
            status,
        )

    @app.errorhandler(db.DatabaseError)
    def database_error(error):
        return error_response(503, "Database unavailable",
                              "The database could not be reached. Please try again later.")

    @app.errorhandler(401)
    def unauthorized(error):
        return error_response(401, "Login required", "Please log in to continue.")

    @app.errorhandler(CSRFError)
    def csrf_error(error):
        return error_response(400, "Page expired",
                              "Your session has expired. Please go back, reload the page and try again.")

    @app.errorhandler(400)
    def bad_request(error):
        return error_response(400, "Invalid request",
                              "The request could not be understood. Please try again.")

    @app.errorhandler(404)
    def not_found(error):
        return error_response(404, "Page not found",
                              "The page you are looking for does not exist.")

    @app.errorhandler(405)
    def method_not_allowed(error):
        return error_response(405, "Method not allowed",
                              "This action is not supported for this address.")

    @app.errorhandler(415)
    def unsupported_type(error):
        return error_response(415, "Unsupported format",
                              "The data was sent in an unsupported format.")

    @app.errorhandler(413)
    def too_large(error):
        return error_response(413, "Request too large",
                              "The submitted data is too large.")

    @app.errorhandler(500)
    def server_error(error):
        # The real error is written to the server log, never shown to users.
        app.logger.exception("Unhandled server error")
        return error_response(500, "Something went wrong",
                              "An unexpected error occurred. Please try again later.")


# ----------------------------------------------------------------------
# Security headers added to every response
# ----------------------------------------------------------------------
def register_security_headers(app):

    @app.after_request
    def add_headers(response):
        # Stop browsers guessing content types.
        response.headers["X-Content-Type-Options"] = "nosniff"
        # Do not allow the site to be embedded in other sites (clickjacking).
        response.headers["X-Frame-Options"] = "DENY"
        response.headers["Referrer-Policy"] = "no-referrer"
        # Only load scripts/styles from this site and the Bootstrap CDN.
        response.headers["Content-Security-Policy"] = (
            "default-src 'self'; "
            "script-src 'self' https://cdn.jsdelivr.net; "
            "style-src 'self' https://cdn.jsdelivr.net; "
            "img-src 'self' data:; "
            "frame-ancestors 'none'; "
            "form-action 'self'"
        )
        # Admin pages contain stored messages: do not let browsers cache them.
        if request.endpoint and request.endpoint.startswith("admin."):
            response.headers["Cache-Control"] = "no-store"
        return response


# ----------------------------------------------------------------------
# Template helpers
# ----------------------------------------------------------------------
def register_template_filters(app):

    @app.template_filter("datetime_utc")
    def datetime_utc(value):
        """'2026-09-28T14:03:00+00:00' -> '2026-09-28 14:03 UTC'."""
        try:
            return datetime.fromisoformat(value).strftime("%Y-%m-%d %H:%M UTC")
        except (TypeError, ValueError):
            return value or "-"

    app.add_template_filter(format_confidence, "confidence")

    @app.template_filter("percent")
    def percent(value, decimals=1):
        """0.9723 -> '97.2%'."""
        try:
            return f"{float(value) * 100:.{decimals}f}%"
        except (TypeError, ValueError):
            return "-"


if __name__ == "__main__":
    app = create_app()
    app.run(host=app.config["HOST"], port=app.config["PORT"], debug=app.config["DEBUG"])

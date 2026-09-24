"""
app.py - Flask web application for the Scam Detection System.

Phase 1: pages (home, analyze), health-check API, error handling and
basic security settings. The trained ML model is connected in Phase 3.

Run with:  python app.py
"""

from flask import Flask, jsonify, render_template, request
from flask_wtf.csrf import CSRFProtect

from config import Config

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
    csrf.init_app(app)

    register_routes(app)
    register_error_handlers(app)
    register_security_headers(app)
    return app


# ----------------------------------------------------------------------
# Routes
# ----------------------------------------------------------------------
def register_routes(app):

    @app.route("/")
    def index():
        """Landing page."""
        return render_template("index.html")

    @app.route("/analyze")
    def analyze():
        """Page where the user pastes a message to analyse."""
        return render_template(
            "analyze.html",
            max_length=app.config["MAX_MESSAGE_LENGTH"],
        )

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
        return response


# Create the app instance used by "python app.py".
app = create_app()

if __name__ == "__main__":
    app.run(host=app.config["HOST"], port=app.config["PORT"], debug=app.config["DEBUG"])

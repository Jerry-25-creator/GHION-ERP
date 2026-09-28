"""
predictor.py - Load the trained model and predict new messages.

Prediction steps (the same steps used during training):
    1. The saved TF-IDF vectorizer cleans the text (clean_text is stored
       inside it) and converts it into a vector of TF-IDF weights.
    2. The saved classifier returns the probability that the message is a
       scam. Above the decision threshold (0.5) -> "scam", else "legitimate".
    3. Warning indicators are found separately (indicators.py). They are
       reported alongside the prediction but never change it.

Security note: joblib/pickle files can run code when loaded, so only load
model files that YOU created with train_model.py. Never load .pkl files
downloaded from untrusted sources.
"""

import json
import os

import joblib

from .indicators import find_indicators

SCAM = "scam"
LEGITIMATE = "legitimate"


class ModelNotAvailableError(Exception):
    """Raised when the model files are missing or cannot be loaded."""


class ScamPredictor:
    def __init__(self, model_path, vectorizer_path, evaluation_path=None):
        """Load the model and vectorizer. Raises ModelNotAvailableError."""
        for path in (model_path, vectorizer_path):
            if not os.path.isfile(path):
                raise ModelNotAvailableError(
                    f"Model file not found: {os.path.basename(path)}. "
                    "Train the model first with: python train_model.py"
                )
        try:
            self.vectorizer = joblib.load(vectorizer_path)
            self.model = joblib.load(model_path)
        except Exception as error:  # corrupt file, incompatible library version...
            raise ModelNotAvailableError(
                f"The model files could not be loaded ({type(error).__name__}). "
                "Re-train the model with: python train_model.py"
            ) from error

        if not hasattr(self.model, "predict_proba"):
            raise ModelNotAvailableError("The saved model does not provide probabilities.")

        # Optional information about the model, written by train_model.py.
        self.evaluation = {}
        if evaluation_path and os.path.isfile(evaluation_path):
            with open(evaluation_path, encoding="utf-8") as f:
                self.evaluation = json.load(f)
        self.threshold = float(self.evaluation.get("decision_threshold", 0.5))

    @property
    def model_name(self):
        return self.evaluation.get("selected_model", type(self.model).__name__)

    @property
    def test_metrics(self):
        """Test-set metrics of the selected model (empty dict if unknown)."""
        return self.evaluation.get("selected_model_test_metrics", {})

    def scam_probability(self, message):
        """Probability (0-1) that the message is a scam, according to the model."""
        features = self.vectorizer.transform([message])
        # Column 1 of predict_proba is the probability of class 1 (scam).
        scam_index = list(self.model.classes_).index(1)
        return float(self.model.predict_proba(features)[0][scam_index])

    def predict(self, message):
        """
        Analyse one message and return a dictionary:

            prediction       "scam" or "legitimate"      (ML model)
            confidence       probability of the predicted class, 0.5-1.0 (ML model)
            scam_probability probability of the scam class, 0-1        (ML model)
            indicators       list of {"name", "explanation"}   (pattern checks)
        """
        p_scam = self.scam_probability(message)
        is_scam = p_scam >= self.threshold
        return {
            "prediction": SCAM if is_scam else LEGITIMATE,
            "confidence": p_scam if is_scam else 1.0 - p_scam,
            "scam_probability": p_scam,
            "indicators": find_indicators(message),
        }

"""
test_examples.py - Check the trained model against labelled example messages
(see example_messages.py). Skipped until the model has been trained.

Run with:  pytest -v tests/test_examples.py
"""

import os

import pytest

from config import Config
from example_messages import ALL_EXAMPLES, KNOWN_DIFFICULT_EXAMPLES
from scam_detector.predictor import ScamPredictor

pytestmark = pytest.mark.skipif(
    not (os.path.exists(Config.MODEL_PATH) and os.path.exists(Config.VECTORIZER_PATH)),
    reason="Model not trained - run python train_model.py",
)

LABELS = {0: "legitimate", 1: "scam"}


@pytest.fixture(scope="module")
def predictor():
    return ScamPredictor(Config.MODEL_PATH, Config.VECTORIZER_PATH)


@pytest.mark.parametrize("message, expected", ALL_EXAMPLES)
def test_example_message(predictor, message, expected):
    assert predictor.predict(message)["prediction"] == LABELS[expected]


@pytest.mark.parametrize("message, expected", KNOWN_DIFFICULT_EXAMPLES)
@pytest.mark.xfail(reason="Known limitation: message style is rare in the training data", strict=False)
def test_known_difficult_message(predictor, message, expected):
    assert predictor.predict(message)["prediction"] == LABELS[expected]


def test_confidence_is_a_probability(predictor):
    for message, _ in ALL_EXAMPLES:
        result = predictor.predict(message)
        assert 0.5 <= result["confidence"] <= 1.0
        assert 0.0 <= result["scam_probability"] <= 1.0

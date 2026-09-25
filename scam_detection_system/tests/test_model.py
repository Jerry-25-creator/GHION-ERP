"""
test_model.py - Tests for dataset loading, text preprocessing and the
trained model.

The small messages written in this file are TEST INPUTS only. They are
never used to train the model.

Run with:  pytest -v
"""

import os

import joblib
import pytest

from config import Config
from scam_detector.dataset import DatasetError, load_dataset, standardise_labels
from scam_detector.preprocessing import clean_text

import pandas as pd

# Enough rows of each class to pass validate_dataset (min 20 rows, 5 per class).
LEGIT = ["See you at lunch", "Meeting moved to 3pm", "Can you call me later",
         "Thanks for dinner", "Happy birthday", "I am on my way home",
         "Did you finish the report", "Let us watch the match", "Good night",
         "The bus is late again", "Send me the notes please", "Mum says hi"]
SCAM = ["You have won a prize claim now", "Urgent: verify your PIN",
        "Free entry to win cash", "Your account is suspended click link",
        "Congratulations you are selected for a reward", "Send your password to unlock",
        "Claim 5000 UGX bonus today", "Final notice pay the fee now"]


def write_csv(tmp_path, df, name="data.csv", **kwargs):
    path = tmp_path / name
    df.to_csv(path, index=False, **kwargs)
    return str(path)


# ----------------------------------------------------------------------
# Dataset loading
# ----------------------------------------------------------------------
def test_load_standard_csv(tmp_path):
    df = pd.DataFrame({"message": LEGIT + SCAM, "label": [0] * len(LEGIT) + [1] * len(SCAM)})
    data, report = load_dataset(write_csv(tmp_path, df), verbose=False)
    assert list(data.columns) == ["message", "label"]
    assert set(data["label"]) == {0, 1}
    assert report["rows_after_cleaning"] == len(LEGIT) + len(SCAM)


def test_load_kaggle_style_columns_and_labels(tmp_path):
    """Kaggle spam.csv uses v1 (ham/spam), v2 (text), extra empty columns, Latin-1."""
    df = pd.DataFrame({"v1": ["ham"] * len(LEGIT) + ["spam"] * len(SCAM),
                       "v2": LEGIT + SCAM, "Unnamed: 2": None})
    data, _ = load_dataset(write_csv(tmp_path, df, encoding="latin-1"), verbose=False)
    assert list(data.columns) == ["message", "label"]
    assert data["label"].sum() == len(SCAM)


def test_load_uci_tab_separated_file(tmp_path):
    path = tmp_path / "SMSSpamCollection"
    lines = [f"ham\t{m}" for m in LEGIT] + [f"spam\t{m}" for m in SCAM]
    path.write_text("\n".join(lines), encoding="utf-8")
    data, _ = load_dataset(str(path), verbose=False)
    assert len(data) == len(LEGIT) + len(SCAM)


def test_cleaning_removes_missing_unknown_and_duplicates(tmp_path):
    df = pd.DataFrame({
        "text": LEGIT + SCAM + ["", "See you at lunch", "Some message"],
        "class": [0] * len(LEGIT) + [1] * len(SCAM) + [0, 0, "maybe"],
    })
    data, report = load_dataset(write_csv(tmp_path, df), verbose=False)
    assert report["dropped_missing_message"] == 1
    assert report["dropped_unknown_label"] == 1
    assert report["dropped_duplicates"] == 1
    assert len(data) == len(LEGIT) + len(SCAM)


def test_missing_dataset_gives_friendly_error(tmp_path):
    with pytest.raises(DatasetError, match="Dataset not found"):
        load_dataset(str(tmp_path / "missing.csv"), verbose=False)


def test_missing_columns_gives_friendly_error(tmp_path):
    df = pd.DataFrame({"foo": ["a"] * 30, "bar": [0] * 30})
    with pytest.raises(DatasetError, match="required columns"):
        load_dataset(write_csv(tmp_path, df), verbose=False)


def test_single_class_dataset_is_rejected(tmp_path):
    df = pd.DataFrame({"message": [f"message {i}" for i in range(30)], "label": [0] * 30})
    with pytest.raises(DatasetError, match="scam"):
        load_dataset(write_csv(tmp_path, df), verbose=False)


def test_label_standardisation():
    labels = pd.Series(["ham", "SPAM", "1", "0", "1.0", "phishing", "legitimate", "??"])
    result = standardise_labels(labels).tolist()
    assert result[:7] == [0, 1, 1, 0, 1, 1, 0]
    assert pd.isna(result[7])


# ----------------------------------------------------------------------
# Text preprocessing
# ----------------------------------------------------------------------
def test_clean_text_lowercases_and_trims():
    assert clean_text("  Hello   WORLD  ") == "hello world"


def test_clean_text_handles_missing_values():
    assert clean_text(None) == ""
    assert clean_text(float("nan")) == ""


def test_clean_text_keeps_urls_as_token():
    assert "urltoken" in clean_text("Claim at http://win-now.biz/abc today")
    assert "urltoken" in clean_text("visit www.example.com")
    assert "urltoken" in clean_text("go to bit.ly/xyz")


def test_clean_text_keeps_money_phone_email_as_tokens():
    assert "moneytoken" in clean_text("You have won 5,000,000 UGX")
    assert "moneytoken" in clean_text("Win £1000 cash")
    assert "phonetoken" in clean_text("Call 0772 123 456 now")
    assert "emailtoken" in clean_text("Reply to winner@prize.com")


def test_clean_text_keeps_exclamation_and_removes_other_punctuation():
    result = clean_text("Congratulations!!! You're a winner.")
    assert result.count("exclamationmark") == 3
    assert "'" not in result and "." not in result


# ----------------------------------------------------------------------
# Trained model (skipped until "python train_model.py" has been run)
# ----------------------------------------------------------------------
model_missing = not (os.path.exists(Config.MODEL_PATH) and os.path.exists(Config.VECTORIZER_PATH))


@pytest.fixture(scope="module")
def trained():
    return joblib.load(Config.VECTORIZER_PATH), joblib.load(Config.MODEL_PATH)


@pytest.mark.skipif(model_missing, reason="Model not trained yet - run python train_model.py")
def test_model_gives_valid_probabilities(trained):
    vectorizer, model = trained
    proba = model.predict_proba(vectorizer.transform(["Hello, how are you?"]))[0]
    assert len(proba) == 2
    assert abs(proba.sum() - 1.0) < 1e-6


@pytest.mark.skipif(model_missing, reason="Model not trained yet - run python train_model.py")
def test_model_flags_obvious_scam(trained):
    vectorizer, model = trained
    msg = ("Congratulations! You have won a FREE prize of $5000. "
           "Call 09061234567 or text WIN to 87121 now to claim.")
    assert model.predict(vectorizer.transform([msg]))[0] == 1


@pytest.mark.skipif(model_missing, reason="Model not trained yet - run python train_model.py")
def test_model_accepts_ordinary_message(trained):
    vectorizer, model = trained
    msg = "Hi, are we still meeting for lunch tomorrow at 1?"
    assert model.predict(vectorizer.transform([msg]))[0] == 0

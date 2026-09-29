"""
test_training.py - Tests for the training pipeline (train_model.py).

The end-to-end test trains on a small SYNTHETIC dataset created inside the
test (clearly artificial, only to check that the code works) and writes to a
temporary folder, so the real model in models/ is never touched.

Run with:  pytest -v tests/test_training.py
"""

import json
import os

import pandas as pd
import pytest

import train_model
from scam_detector.predictor import ScamPredictor


def synthetic_dataset(n_per_class=30):
    """Artificial messages built from templates - for testing the code only."""
    scam = [f"Congratulations you won prize {i}! Claim cash now at www.win{i}.com or call 0906{i:06d}"
            for i in range(n_per_class)]
    legit = [f"Hi friend, see you at lunch on day {i}, bring the notes for lecture {i}"
             for i in range(n_per_class)]
    return pd.DataFrame({"message": scam + legit, "label": [1] * n_per_class + [0] * n_per_class})


def test_split_has_no_overlap_and_is_stratified():
    df = synthetic_dataset(50)
    X_train, X_test, y_train, y_test = train_model.split_dataset(df, test_size=0.2, seed=42)
    assert set(X_train).isdisjoint(set(X_test))        # never evaluate on training data
    assert len(X_test) == 20
    assert y_test.mean() == pytest.approx(0.5)         # same class ratio as the full data


def test_select_model_highest_f1():
    results = {"A": {"cv": {"cv_f1": 0.90, "cv_recall": 0.99}},
               "B": {"cv": {"cv_f1": 0.95, "cv_recall": 0.90}}}
    chosen, _ = train_model.select_model(results)
    assert chosen == "B"                               # A is more than 0.01 worse


def test_select_model_tie_broken_by_recall():
    results = {"A": {"cv": {"cv_f1": 0.950, "cv_recall": 0.97}},
               "B": {"cv": {"cv_f1": 0.955, "cv_recall": 0.93}}}
    chosen, reason = train_model.select_model(results)
    assert chosen == "A"
    assert "recall" in reason


def test_full_training_pipeline(tmp_path):
    data_path = tmp_path / "synthetic.csv"
    synthetic_dataset().to_csv(data_path, index=False)
    out = tmp_path / "models"

    evaluation = train_model.main(["--data", str(data_path), "--output-dir", str(out)])

    paths = train_model.output_paths(str(out))
    for path in paths.values():
        assert os.path.exists(path)
    assert set(evaluation["models"]) == {"Multinomial Naive Bayes", "Logistic Regression",
                                         "Linear SVM (calibrated)"}
    for result in evaluation["models"].values():
        for metric in ("accuracy", "precision", "recall", "f1"):
            assert 0.0 <= result["test"][metric] <= 1.0
    with open(paths["evaluation"], encoding="utf-8") as f:
        assert json.load(f)["selected_model"] == evaluation["selected_model"]

    # The saved files can be loaded by the web app's predictor.
    predictor = ScamPredictor(paths["model"], paths["vectorizer"], paths["evaluation"])
    assert predictor.predict("You won a prize! Claim cash now at www.win5.com")["prediction"] == "scam"


def test_missing_dataset_exits_with_error(tmp_path, capsys):
    with pytest.raises(SystemExit):
        train_model.main(["--data", str(tmp_path / "missing.csv"), "--output-dir", str(tmp_path)])
    assert "Dataset not found" in capsys.readouterr().out


def test_invalid_test_size_is_rejected(tmp_path):
    with pytest.raises(SystemExit):
        train_model.main(["--test-size", "0.9", "--output-dir", str(tmp_path)])

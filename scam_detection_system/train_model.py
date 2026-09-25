"""
train_model.py - Train, evaluate and save the scam detection model.

Pipeline:
    1. Load and validate the dataset          (scam_detector/dataset.py)
    2. Clean the data                          (missing values, labels, duplicates)
    3. Exploratory data analysis               (class balance, lengths, URLs...)
    4. Stratified train/test split             (test set is never used for training)
    5. Text preprocessing + TF-IDF             (scam_detector/preprocessing.py)
    6. Train 3 models, tuning each one with 5-fold cross-validation
       on the TRAINING set only:
           - Multinomial Naive Bayes
           - Logistic Regression
           - Linear SVM (calibrated to give probabilities)
    7. Evaluate every model on the held-out TEST set
    8. Select the model using documented criteria (see select_model)
    9. Save models/model.pkl, models/vectorizer.pkl and evaluation results

Usage:
    python train_model.py
    python train_model.py --data path\\to\\file.csv --test-size 0.2 --seed 42
"""

import argparse
import csv
import json
import os
import sys
import time
from datetime import datetime, timezone

import joblib
import numpy as np
import sklearn
from sklearn.calibration import CalibratedClassifierCV
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (accuracy_score, classification_report, confusion_matrix,
                             f1_score, precision_score, recall_score)
from sklearn.model_selection import GridSearchCV, StratifiedKFold, train_test_split
from sklearn.naive_bayes import MultinomialNB
from sklearn.pipeline import Pipeline
from sklearn.svm import LinearSVC

from config import Config
from scam_detector.dataset import DatasetError, load_dataset
from scam_detector.preprocessing import (EMAIL_RE, MONEY_RE, PHONE_RE, URL_RE,
                                         clean_text)

# If two models' cross-validation F1-scores are closer than this, they are
# treated as equally good and the one with the higher scam recall wins.
F1_TIE_MARGIN = 0.01

# Probability above which a message is classified as a scam.
DECISION_THRESHOLD = 0.5

EVALUATION_JSON = os.path.join(Config.MODEL_DIR, "evaluation_results.json")
COMPARISON_CSV = os.path.join(Config.MODEL_DIR, "model_comparison.csv")


def heading(title):
    print("\n" + "=" * 70)
    print(title)
    print("=" * 70)


# ----------------------------------------------------------------------
# Step 3: Exploratory data analysis
# ----------------------------------------------------------------------
def explore_data(df):
    """Print simple statistics that describe the dataset."""
    counts = df["label"].value_counts().sort_index()
    total = len(df)
    print("Class distribution:")
    for label, name in ((0, "Legitimate (0)"), (1, "Scam (1)")):
        n = int(counts.get(label, 0))
        print(f"  {name:<15} {n:>6}  ({n / total:6.1%})")

    ratio = counts.max() / counts.min()
    if ratio > 1.5:
        print(f"\n  Note: the classes are imbalanced (about {ratio:.1f} : 1).")
        print("  Accuracy alone would be misleading, so precision, recall and")
        print("  F1-score for the scam class are used to compare models.")

    lower = df["message"].str.lower()
    stats = df.assign(
        chars=df["message"].str.len(),
        words=df["message"].str.split().str.len(),
        has_url=lower.str.contains(URL_RE),
        has_email=lower.str.contains(EMAIL_RE),
        has_phone=lower.str.contains(PHONE_RE),
        has_money=lower.str.contains(MONEY_RE),
    )
    summary = stats.groupby("label").agg(
        avg_chars=("chars", "mean"),
        avg_words=("words", "mean"),
        pct_url=("has_url", "mean"),
        pct_phone=("has_phone", "mean"),
        pct_money=("has_money", "mean"),
    )

    print("\nMessage characteristics by class:")
    print(f"  {'':<15}{'Avg chars':>10}{'Avg words':>11}{'Has URL':>10}{'Has phone':>11}{'Has money':>11}")
    for label, name in ((0, "Legitimate"), (1, "Scam")):
        row = summary.loc[label]
        print(f"  {name:<15}{row.avg_chars:>10.1f}{row.avg_words:>11.1f}"
              f"{row.pct_url:>10.1%}{row.pct_phone:>11.1%}{row.pct_money:>11.1%}")

    return {
        "total_messages": int(total),
        "legitimate": int(counts.get(0, 0)),
        "scam": int(counts.get(1, 0)),
        "by_class": {("legitimate" if k == 0 else "scam"): {m: round(float(v), 4) for m, v in row.items()}
                     for k, row in summary.iterrows()},
    }


# ----------------------------------------------------------------------
# Step 5 + 6: Models
# ----------------------------------------------------------------------
def make_vectorizer():
    """
    TF-IDF settings:
        preprocessor=clean_text  our cleaning function (also saved inside the
                                 vectorizer, so prediction uses the same cleaning)
        ngram_range=(1, 2)       single words AND pairs of words, e.g. "claim prize"
        min_df=2                 ignore terms that appear in only one message (noise)
        max_df=0.95              ignore terms that appear in almost every message
        sublinear_tf=True        use 1 + log(count), so repeating a word 10 times
                                 does not make it 10 times more important
    """
    return TfidfVectorizer(preprocessor=clean_text, ngram_range=(1, 2),
                           min_df=2, max_df=0.95, sublinear_tf=True)


def candidate_models(seed):
    """
    The three algorithms compared, each with a small grid of values for its
    main setting. The best value is chosen by cross-validation.

    class_weight="balanced" makes LR and SVM pay more attention to the
    smaller (scam) class, which usually improves scam recall.
    """
    return {
        "Multinomial Naive Bayes": (
            MultinomialNB(),
            # alpha = smoothing strength
            {"clf__alpha": [0.01, 0.05, 0.1, 0.5, 1.0]},
        ),
        "Logistic Regression": (
            LogisticRegression(class_weight="balanced", max_iter=2000, random_state=seed),
            # C = inverse regularisation strength (higher = fits training data more closely)
            {"clf__C": [1, 10, 100]},
        ),
        "Linear SVM (calibrated)": (
            # LinearSVC gives scores, not probabilities. CalibratedClassifierCV learns
            # a mapping from SVM scores to probabilities using internal cross-validation
            # (sigmoid / Platt scaling), so the confidence shown to users is genuine.
            CalibratedClassifierCV(
                LinearSVC(class_weight="balanced", random_state=seed),
                method="sigmoid", cv=5,
            ),
            {"clf__estimator__C": [0.1, 1, 10]},
        ),
    }


def tune_with_cross_validation(name, classifier, grid, X_train, y_train, seed):
    """Run 5-fold stratified cross-validation on the TRAINING data only."""
    pipeline = Pipeline([("tfidf", make_vectorizer()), ("clf", classifier)])
    search = GridSearchCV(
        pipeline, grid,
        scoring={"f1": "f1", "recall": "recall", "precision": "precision"},
        refit="f1",                           # best = highest F1 for the scam class
        cv=StratifiedKFold(n_splits=5, shuffle=True, random_state=seed),
        n_jobs=1,
    )
    start = time.time()
    search.fit(X_train, y_train)
    i = search.best_index_
    cv = {
        "best_params": {k.replace("clf__", ""): v for k, v in search.best_params_.items()},
        "cv_f1": float(search.cv_results_["mean_test_f1"][i]),
        "cv_f1_std": float(search.cv_results_["std_test_f1"][i]),
        "cv_recall": float(search.cv_results_["mean_test_recall"][i]),
        "cv_precision": float(search.cv_results_["mean_test_precision"][i]),
    }
    print(f"  {name:<26} best {cv['best_params']}  "
          f"CV F1 = {cv['cv_f1']:.4f} (+/- {cv['cv_f1_std']:.4f})  "
          f"CV recall = {cv['cv_recall']:.4f}  [{time.time() - start:.1f}s]")
    # search.best_estimator_ has been re-trained on the whole training set.
    return search.best_estimator_, cv


# ----------------------------------------------------------------------
# Step 7: Evaluation on the test set
# ----------------------------------------------------------------------
def evaluate(pipeline, X_test, y_test):
    """Compute test-set metrics. Precision/recall/F1 refer to the SCAM class."""
    proba = pipeline.predict_proba(X_test)[:, 1]
    y_pred = (proba >= DECISION_THRESHOLD).astype(int)
    tn, fp, fn, tp = confusion_matrix(y_test, y_pred, labels=[0, 1]).ravel()
    return {
        "accuracy": float(accuracy_score(y_test, y_pred)),
        "precision": float(precision_score(y_test, y_pred, zero_division=0)),
        "recall": float(recall_score(y_test, y_pred, zero_division=0)),
        "f1": float(f1_score(y_test, y_pred, zero_division=0)),
        "confusion_matrix": {"tn": int(tn), "fp": int(fp), "fn": int(fn), "tp": int(tp)},
    }, y_pred


def print_confusion_matrix(cm):
    print(f"                        Predicted legit   Predicted scam")
    print(f"    Actual legitimate   {cm['tn']:>15}   {cm['fp']:>14}")
    print(f"    Actual scam         {cm['fn']:>15}   {cm['tp']:>14}")
    print(f"    -> {cm['fp']} false positive(s) (legitimate flagged as scam)")
    print(f"    -> {cm['fn']} false negative(s) (scams missed)")


def print_comparison_table(results):
    print(f"  {'Model':<26}{'Accuracy':>10}{'Precision':>11}{'Recall':>9}{'F1 Score':>10}{'CV F1':>9}")
    print("  " + "-" * 73)
    for name, r in results.items():
        t = r["test"]
        print(f"  {name:<26}{t['accuracy']:>10.4f}{t['precision']:>11.4f}"
              f"{t['recall']:>9.4f}{t['f1']:>10.4f}{r['cv']['cv_f1']:>9.4f}")


# ----------------------------------------------------------------------
# Step 8: Model selection
# ----------------------------------------------------------------------
SELECTION_CRITERIA = [
    "1. Highest mean F1-score for the scam class in 5-fold cross-validation on the "
    "training set. The test set is NOT used for choosing, so the test scores stay an "
    "unbiased estimate of performance on new messages.",
    f"2. If another model's CV F1 is within {F1_TIE_MARGIN} of the best, the one with the "
    "higher CV scam recall is chosen, because missing a scam (false negative) is more "
    "harmful than a false alarm.",
    "3. The model must give genuine probability estimates for the confidence value "
    "(Naive Bayes and Logistic Regression do natively; Linear SVM does via calibration).",
]


def select_model(results):
    best_f1 = max(r["cv"]["cv_f1"] for r in results.values())
    close = {n: r for n, r in results.items() if best_f1 - r["cv"]["cv_f1"] <= F1_TIE_MARGIN}
    chosen = max(close, key=lambda n: (close[n]["cv"]["cv_recall"], close[n]["cv"]["cv_f1"]))

    cv = results[chosen]["cv"]
    if len(close) == 1:
        reason = (f"{chosen} had the highest cross-validation F1-score "
                  f"({cv['cv_f1']:.4f}) for the scam class.")
    else:
        tied = ", ".join(close)
        reason = (f"The cross-validation F1-scores of {tied} were within {F1_TIE_MARGIN} "
                  f"of the best, so the tie was decided by scam recall: {chosen} had the "
                  f"highest cross-validation scam recall ({cv['cv_recall']:.4f}).")
    return chosen, reason


# ----------------------------------------------------------------------
# Explainability: which terms push a prediction towards "scam"?
# ----------------------------------------------------------------------
def top_scam_terms(pipeline, n=15):
    """Return the n terms with the strongest association with the scam class."""
    vectorizer = pipeline.named_steps["tfidf"]
    clf = pipeline.named_steps["clf"]
    if isinstance(clf, MultinomialNB):
        weights = clf.feature_log_prob_[1] - clf.feature_log_prob_[0]
    elif isinstance(clf, LogisticRegression):
        weights = clf.coef_[0]
    elif isinstance(clf, CalibratedClassifierCV):
        weights = np.mean([c.estimator.coef_[0] for c in clf.calibrated_classifiers_], axis=0)
    else:
        return []
    terms = vectorizer.get_feature_names_out()
    top = np.argsort(weights)[::-1][:n]
    return [(str(terms[i]), round(float(weights[i]), 4)) for i in top]


def show_examples(title, messages, limit=5):
    print(f"\n  {title} ({len(messages)} in total):")
    if not messages:
        print("    (none)")
    for msg in messages[:limit]:
        short = msg if len(msg) <= 100 else msg[:97] + "..."
        print(f"    - {short}")


# ----------------------------------------------------------------------
# Step 9: Save
# ----------------------------------------------------------------------
def save_outputs(pipeline, evaluation):
    os.makedirs(Config.MODEL_DIR, exist_ok=True)
    # Saved separately, as required: the vectorizer turns text into numbers,
    # the model turns numbers into a prediction.
    joblib.dump(pipeline.named_steps["tfidf"], Config.VECTORIZER_PATH)
    joblib.dump(pipeline.named_steps["clf"], Config.MODEL_PATH)

    with open(EVALUATION_JSON, "w", encoding="utf-8") as f:
        json.dump(evaluation, f, indent=2)

    with open(COMPARISON_CSV, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["model", "accuracy", "precision", "recall", "f1",
                         "cv_f1", "cv_recall", "tn", "fp", "fn", "tp", "selected"])
        for name, r in evaluation["models"].items():
            t, cm = r["test"], r["test"]["confusion_matrix"]
            writer.writerow([name, *(round(t[k], 4) for k in ("accuracy", "precision", "recall", "f1")),
                             round(r["cv"]["cv_f1"], 4), round(r["cv"]["cv_recall"], 4),
                             cm["tn"], cm["fp"], cm["fn"], cm["tp"],
                             name == evaluation["selected_model"]])


def main():
    parser = argparse.ArgumentParser(description="Train and evaluate the scam detection model.")
    parser.add_argument("--data", default=Config.DATA_PATH, help="Path to the labelled dataset")
    parser.add_argument("--test-size", type=float, default=0.2, help="Share of data kept for testing")
    parser.add_argument("--seed", type=int, default=42, help="Random seed for reproducible results")
    args = parser.parse_args()

    # Avoid crashes when a message contains characters the console cannot show.
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(errors="replace")

    heading("STEP 1-2: Load, validate and clean the dataset")
    print(f"Dataset: {args.data}")
    try:
        df, cleaning_report = load_dataset(args.data)
    except DatasetError as error:
        print(f"\nERROR: {error}")
        sys.exit(1)

    heading("STEP 3: Exploratory data analysis")
    eda = explore_data(df)

    heading("STEP 4: Train/test split")
    # stratify=y keeps the same scam/legitimate ratio in both sets.
    X_train, X_test, y_train, y_test = train_test_split(
        df["message"], df["label"], test_size=args.test_size,
        stratify=df["label"], random_state=args.seed,
    )
    print(f"  Training set: {len(X_train)} messages ({int(y_train.sum())} scams)")
    print(f"  Test set    : {len(X_test)} messages ({int(y_test.sum())} scams)")
    print("  The test set is set aside and used only for the final evaluation.")

    heading("STEP 5: Text preprocessing examples")
    for msg in X_train[y_train == 1].head(3):
        print(f"  Original: {msg[:90]}")
        print(f"  Cleaned : {clean_text(msg)[:90]}\n")

    heading("STEP 6: Train models (TF-IDF + classifier, 5-fold cross-validation)")
    results, fitted = {}, {}
    for name, (classifier, grid) in candidate_models(args.seed).items():
        fitted[name], cv = tune_with_cross_validation(name, classifier, grid, X_train, y_train, args.seed)
        results[name] = {"cv": cv}
    vocab = len(next(iter(fitted.values())).named_steps["tfidf"].vocabulary_)
    print(f"\n  TF-IDF vocabulary size: {vocab} terms (words and word pairs)")

    heading("STEP 7: Evaluate on the unseen test set")
    predictions = {}
    for name, pipeline in fitted.items():
        results[name]["test"], predictions[name] = evaluate(pipeline, X_test, y_test)
        print(f"\n  {name}")
        print_confusion_matrix(results[name]["test"]["confusion_matrix"])

    print("\nModel comparison (precision, recall and F1 are for the SCAM class):\n")
    print_comparison_table(results)

    heading("STEP 8: Model selection")
    print("Criteria:")
    for rule in SELECTION_CRITERIA:
        print(f"  {rule}")
    chosen, reason = select_model(results)
    print(f"\n  SELECTED MODEL: {chosen}")
    print(f"  Reason: {reason}")

    best = fitted[chosen]
    print(f"\n  Detailed report for {chosen} on the test set:")
    print(classification_report(y_test, predictions[chosen],
                                target_names=["Legitimate", "Scam"], digits=4))

    terms = top_scam_terms(best)
    print("  Terms most strongly associated with scams by this model:")
    print("    " + ", ".join(t for t, _ in terms))

    wrong_fn = X_test[(y_test == 1) & (predictions[chosen] == 0)].tolist()
    wrong_fp = X_test[(y_test == 0) & (predictions[chosen] == 1)].tolist()
    show_examples("Missed scams (false negatives)", wrong_fn)
    show_examples("Legitimate messages flagged as scam (false positives)", wrong_fp)

    heading("STEP 9: Save model, vectorizer and evaluation results")
    evaluation = {
        "created_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "dataset_path": os.path.basename(args.data),
        "cleaning": cleaning_report,
        "eda": eda,
        "split": {"test_size": args.test_size, "random_state": args.seed,
                  "train_size": int(len(X_train)), "test_size_rows": int(len(X_test))},
        "tfidf_vocabulary_size": vocab,
        "decision_threshold": DECISION_THRESHOLD,
        "selection_criteria": SELECTION_CRITERIA,
        "selected_model": chosen,
        "selection_reason": reason,
        "selected_model_test_metrics": results[chosen]["test"],
        "top_scam_terms": terms,
        "models": results,
        "library_versions": {"scikit-learn": sklearn.__version__, "numpy": np.__version__},
    }
    save_outputs(best, evaluation)
    for path in (Config.MODEL_PATH, Config.VECTORIZER_PATH, EVALUATION_JSON, COMPARISON_CSV):
        print(f"  Saved: {os.path.relpath(path)}")
    print("\nTraining complete.")


if __name__ == "__main__":
    main()

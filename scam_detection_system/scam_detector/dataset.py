"""
dataset.py - Load, validate and standardise the labelled dataset.

Target format used by the rest of the project:

    message : str   the message text
    label   : int   0 = legitimate, 1 = scam

Public datasets use many different column names and label values
(for example the Kaggle SMS spam file uses columns "v1"/"v2" with labels
"ham"/"spam"). This module converts those variations to the format above
so the training code only has to deal with one format.
"""

import os

import pandas as pd

LEGITIMATE = 0
SCAM = 1

# Column names that will be renamed to "message" / "label".
# Compared in lowercase with surrounding spaces removed.
MESSAGE_COLUMN_ALIASES = ["message", "text", "sms", "content", "body", "msg", "v2"]
LABEL_COLUMN_ALIASES = ["label", "class", "category", "target", "type", "is_scam", "spam", "v1"]

# Label values that will be converted to 0 / 1 (compared in lowercase).
LABEL_VALUE_MAP = {
    # legitimate -> 0
    "0": LEGITIMATE, "ham": LEGITIMATE, "legit": LEGITIMATE, "legitimate": LEGITIMATE,
    "not_scam": LEGITIMATE, "not scam": LEGITIMATE, "normal": LEGITIMATE,
    "safe": LEGITIMATE, "genuine": LEGITIMATE, "false": LEGITIMATE, "no": LEGITIMATE,
    # scam -> 1
    "1": SCAM, "spam": SCAM, "scam": SCAM, "fraud": SCAM, "phishing": SCAM,
    "smishing": SCAM, "malicious": SCAM, "true": SCAM, "yes": SCAM,
}


class DatasetError(Exception):
    """Raised when the dataset is missing or cannot be used. The message is
    written for the user, so it can be printed directly."""


def read_raw_file(path):
    """
    Read a CSV or TSV file into a DataFrame.

    Tries UTF-8 first, then Latin-1 (the Kaggle SMS spam file is Latin-1).
    Files ending in .tsv/.txt, or with no extension (like the UCI file
    "SMSSpamCollection"), are read as tab-separated "label<TAB>message"
    without a header row.
    """
    if not os.path.isfile(path):
        raise DatasetError(
            f"Dataset not found at: {path}\n"
            "Place a labelled CSV file there (columns: message,label). "
            "See data/README.md for the expected format."
        )

    ext = os.path.splitext(path)[1].lower()
    is_tab_file = ext in (".tsv", ".txt", "")

    last_error = None
    for encoding in ("utf-8", "latin-1"):
        try:
            if is_tab_file:
                return pd.read_csv(path, sep="\t", header=None, names=["label", "message"],
                                   encoding=encoding, quoting=3, dtype=str)
            return pd.read_csv(path, encoding=encoding, dtype=str)
        except UnicodeDecodeError as error:
            last_error = error
        except pd.errors.ParserError as error:
            raise DatasetError(f"The dataset file could not be parsed as CSV: {error}")
        except pd.errors.EmptyDataError:
            raise DatasetError("The dataset file is empty.")
    raise DatasetError(f"Could not decode the dataset file: {last_error}")


def _find_column(columns, aliases):
    """Return the first column whose name matches one of the aliases."""
    normalised = {str(col).strip().lower(): col for col in columns}
    for alias in aliases:
        if alias in normalised:
            return normalised[alias]
    return None


def standardise_columns(df):
    """Rename the text and label columns to "message" and "label" and drop
    every other column (e.g. empty "Unnamed: 2" columns)."""
    message_col = _find_column(df.columns, MESSAGE_COLUMN_ALIASES)
    label_col = _find_column(df.columns, LABEL_COLUMN_ALIASES)

    if message_col is None or label_col is None:
        raise DatasetError(
            "Could not find the required columns. Found columns: "
            f"{list(df.columns)}.\nThe dataset needs a text column (e.g. 'message' or 'text') "
            "and a label column (e.g. 'label' or 'class')."
        )
    return df[[message_col, label_col]].rename(columns={message_col: "message", label_col: "label"})


def standardise_labels(labels):
    """
    Convert label values to 0 (legitimate) / 1 (scam).

    Returns a pandas Series of floats where unrecognised values are NaN,
    so the caller can report and drop them.
    """
    def convert(value):
        if pd.isna(value):
            return float("nan")
        key = str(value).strip().lower()
        # Accept numeric strings such as "1.0"
        try:
            key = str(int(float(key)))
        except ValueError:
            pass
        return LABEL_VALUE_MAP.get(key, float("nan"))

    return labels.map(convert)


def load_dataset(path, verbose=True):
    """
    Load the dataset and return a clean DataFrame with columns
    message (str) and label (int 0/1), plus a dict describing what was
    removed during cleaning.

    Cleaning steps:
        1. Standardise column names and label values
        2. Drop rows with a missing/empty message
        3. Drop rows with an unrecognised label
        4. Drop exact duplicate messages (duplicates could appear in both
           the training and the test set, which would inflate the scores)
    """
    raw = read_raw_file(path)
    df = standardise_columns(raw)
    report = {"rows_in_file": int(len(df))}

    df["message"] = df["message"].astype("string").str.strip()
    missing_text = df["message"].isna() | (df["message"] == "")
    report["dropped_missing_message"] = int(missing_text.sum())
    df = df[~missing_text]

    df["label"] = standardise_labels(df["label"])
    bad_label = df["label"].isna()
    report["dropped_unknown_label"] = int(bad_label.sum())
    df = df[~bad_label]

    before = len(df)
    df = df.drop_duplicates(subset="message", keep="first")
    report["dropped_duplicates"] = int(before - len(df))

    df = df.astype({"message": str, "label": int}).reset_index(drop=True)
    report["rows_after_cleaning"] = int(len(df))

    validate_dataset(df)

    if verbose:
        for key, value in report.items():
            print(f"  {key.replace('_', ' '):<28}: {value}")
    return df, report


def validate_dataset(df, min_rows=20, min_per_class=5):
    """Check there is enough data of BOTH classes to train and evaluate."""
    if len(df) < min_rows:
        raise DatasetError(
            f"Only {len(df)} usable rows after cleaning. At least {min_rows} are needed "
            "(a few thousand are recommended)."
        )
    counts = df["label"].value_counts()
    for label, name in ((LEGITIMATE, "legitimate (0)"), (SCAM, "scam (1)")):
        if counts.get(label, 0) < min_per_class:
            raise DatasetError(
                f"The dataset has only {counts.get(label, 0)} {name} messages. "
                f"At least {min_per_class} of each class are required."
            )

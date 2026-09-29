"""
prepare_dataset.py - Convert a downloaded public dataset into the project's
standard format and save it as data/scam_dataset.csv.

Usage (Windows):
    python prepare_dataset.py "C:\\Users\\you\\Downloads\\SMSSpamCollection"
    python prepare_dataset.py "C:\\Users\\you\\Downloads\\spam.csv"

Supported inputs include:
    - UCI "SMSSpamCollection" (tab-separated: label<TAB>message, no header)
    - Kaggle "spam.csv" (columns v1 = label, v2 = message, Latin-1 encoding)
    - Any CSV with a text column and a label column (see scam_detector/dataset.py)

The output always has exactly two columns: message,label (0 = legitimate,
1 = scam). Running this step is optional - train_model.py applies the same
standardisation - but it gives you a clean, readable CSV to inspect.
"""

import argparse
import os
import sys

from config import Config
from scam_detector.dataset import DatasetError, load_dataset


def main():
    parser = argparse.ArgumentParser(description="Standardise a labelled message dataset.")
    parser.add_argument("input", help="Path to the downloaded dataset file")
    parser.add_argument("--output", default=Config.DATA_PATH,
                        help="Where to write the standard CSV (default: data/scam_dataset.csv)")
    args = parser.parse_args()

    print(f"Reading: {args.input}")
    try:
        df, _ = load_dataset(args.input)
    except DatasetError as error:
        print(f"\nERROR: {error}")
        sys.exit(1)

    os.makedirs(os.path.dirname(os.path.abspath(args.output)), exist_ok=True)
    df.to_csv(args.output, index=False, encoding="utf-8")

    counts = df["label"].value_counts()
    print(f"\nSaved {len(df)} messages to: {args.output}")
    print(f"  legitimate (0): {counts.get(0, 0)}")
    print(f"  scam       (1): {counts.get(1, 0)}")
    print("\nNext step:  python train_model.py")


if __name__ == "__main__":
    main()

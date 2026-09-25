# Dataset folder

The model is trained from:

```
data/scam_dataset.csv
```

## Included dataset

`scam_dataset.csv` is the **SMS Spam Collection v.1** (UCI Machine Learning
Repository), converted to the standard format with `prepare_dataset.py`:

- Source: <https://archive.ics.uci.edu/dataset/228/sms+spam+collection>
- 5,574 real English SMS messages labelled `ham`/`spam`; after removing 414
  exact duplicates, 5,160 remain (4,518 legitimate, 642 scam/spam).
- Licence: Creative Commons Attribution 4.0 (CC BY 4.0).
- Citation: Almeida, T.A., Gomez Hidalgo, J.M. and Yamakami, A. (2011)
  "Contributions to the Study of SMS Spam Filtering: New Collection and
  Results", *Proceedings of the 2011 ACM Symposium on Document Engineering
  (DocEng'11)*, Mountain View, CA, USA.

No data in this file was invented. To use a different dataset, replace the
file (keeping the format below) or convert it with `prepare_dataset.py`, then
re-run `python train_model.py`.

## Required CSV format

| Column    | Type    | Description                              |
|-----------|---------|------------------------------------------|
| `message` | text    | The full text of the message             |
| `label`   | 0 or 1  | `0` = legitimate, `1` = scam             |

Example:

```csv
message,label
"Hi, are we still meeting at 3pm tomorrow?",0
"URGENT! Your account has been suspended. Verify your PIN at http://example.com now",1
```

Rules:

- The first row must be the header row.
- Messages containing commas must be wrapped in double quotes (spreadsheet
  programs such as Excel do this automatically when saving as CSV).
- Save the file with UTF-8 encoding.

## Other label or column names

Many public datasets use different names. `prepare_dataset.py` and
`train_model.py` standardise common variations automatically, for example:

- Column names such as `text`, `sms`, `v2` → `message`; `class`, `category`, `v1` → `label`
- Label values such as `ham` / `legitimate` / `not_scam` → `0` and
  `spam` / `scam` / `fraud` / `phishing` → `1`

The full list is in `scam_detector/dataset.py`. To convert a downloaded file:

```bat
python prepare_dataset.py "C:\path\to\SMSSpamCollection"
```

## Suggested public datasets

- SMS Spam Collection (included, see above). Kaggle's `spam.csv` is the same
  data (columns `v1`, `v2`).
- For local relevance, a dataset of East African mobile-money scam messages
  could be collected and labelled (with consent and anonymisation) and added.

Note: "spam" is broader than "scam" (it includes unwanted advertising). Mention
this in your report's limitations if you use a spam dataset.

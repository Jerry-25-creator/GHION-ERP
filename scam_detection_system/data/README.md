# Dataset folder

Place your labelled dataset here as:

```
data/scam_dataset.csv
```

No dataset is included, and none should be invented. Use a real, publicly
available labelled dataset and cite it in your report.

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

Many public datasets use different names. The training script (Phase 2) will
standardise common variations automatically, for example:

- Column names such as `text`, `sms`, `v2` → `message`; `class`, `category`, `v1` → `label`
- Label values such as `ham` / `legitimate` / `not_scam` → `0` and
  `spam` / `scam` / `fraud` / `phishing` → `1`

The exact list of supported variations is documented in `train_model.py`.

## Suggested public datasets

- **SMS Spam Collection** (UCI Machine Learning Repository) - 5,574 English SMS
  messages labelled `ham`/`spam`.
  <https://archive.ics.uci.edu/dataset/228/sms+spam+collection>
- Kaggle versions of the same dataset (`spam.csv`, columns `v1`, `v2`).

Note: "spam" is broader than "scam" (it includes unwanted advertising). Mention
this in your report's limitations if you use a spam dataset.

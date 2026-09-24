# Machine Learning-Based Scam Detection System

A web application that analyses a text message and predicts whether it is
**Legitimate** or a **Potential Scam**, using a machine learning model trained and
evaluated on a real labelled dataset.

> **Important:** every result is an automated assessment, not a guarantee. The
> system can be wrong in both directions. Users should always verify suspicious
> messages independently.

---

## Development status

The project is built in phases. Sections marked *(Phase N)* are completed in that phase.

| Phase | Content | Status |
|-------|---------|--------|
| 1 | Project structure, environment, Flask app, basic frontend, README | Done |
| 2 | Dataset preparation, `train_model.py`, TF-IDF, model training and evaluation | Pending |
| 3 | Model integrated with Flask, `POST /api/predict`, analysis results | Pending |
| 4 | SQLite database, analysis history, admin dashboard | Pending |
| 5 | Full test suite, security hardening, UI polish, final documentation | Pending |

---

## 1. Problem being solved

Scam messages (fake prizes, fake bank or mobile-money alerts, requests for PINs,
"urgent" payment demands) cause real financial loss. Many people cannot easily tell
whether a message is genuine. This system gives a quick, explainable second opinion
based on patterns learned from real labelled messages.

## 2. Features

- Landing page that explains the system and its limits
- Analysis page with a large text area, character counter, Analyze and Clear buttons
- ML prediction (Legitimate / Potential Scam) with a confidence estimate *(Phase 3)*
- Warning indicators, shown **separately** from the ML prediction *(Phase 3)*
- REST API: `GET /api/health`, `POST /api/predict` *(Phase 3)*
- Analysis history stored in SQLite *(Phase 4)*
- Admin dashboard with statistics and model performance *(Phase 4)*
- Friendly error pages; no stack traces shown to users
- Security: CSRF protection, input limits, security headers, secrets in `.env`

## 3. Technology stack

| Layer | Technology |
|-------|------------|
| Backend | Python 3, Flask, Flask-WTF (CSRF) |
| Machine learning | scikit-learn, pandas, NumPy, joblib |
| Frontend | HTML5, CSS3, JavaScript, Bootstrap 5 |
| Database | SQLite (designed for later migration to MySQL) |
| Testing | pytest |

## 4. System architecture

```
 Browser (HTML / CSS / JS)
        |  HTTP  (form page + JSON requests to /api/...)
        v
 Flask application (app.py)
        |-- input validation, CSRF, error handling
        |-- preprocessing  -> TF-IDF vectorizer (models/vectorizer.pkl)
        |                  -> trained classifier  (models/model.pkl)
        |-- warning-indicator checks (separate from the ML model)
        |-- SQLite database (database/database.db)
        v
 JSON / HTML response to the browser

 Offline:  data/scam_dataset.csv --> train_model.py --> models/*.pkl + evaluation results
```

The model is trained **once, offline** by `train_model.py`. The web application
only loads the saved model and uses it for predictions.

### Project structure

```
scam_detection_system/
├── app.py               Flask application (routes, error handling, security headers)
├── config.py            Settings read from environment variables / .env
├── train_model.py       ML training and evaluation pipeline      (Phase 2)
├── requirements.txt     Python packages
├── pytest.ini           Test configuration
├── .env.example         Example configuration (copy to .env)
├── .gitignore
├── data/
│   ├── README.md        Expected dataset format
│   └── scam_dataset.csv Your dataset (you provide this)
├── models/              Saved model, vectorizer, evaluation results (generated)
├── database/            SQLite database (generated)                (Phase 4)
├── templates/           HTML pages (Jinja2)
├── static/css, js, images
└── tests/               Automated tests
```

## 5. Machine learning methodology *(implemented in Phase 2)*

```
Dataset -> Data cleaning -> Exploratory analysis -> Train/test split
        -> Text preprocessing -> TF-IDF -> Train 3 models -> Evaluate
        -> Select model -> Save with joblib -> Used by Flask for predictions
```

### Why TF-IDF?

ML models need numbers, not words. **TF-IDF (Term Frequency - Inverse Document
Frequency)** gives each word in a message a score that is:

- **high** when the word appears often in *this* message (term frequency), and
- **lower** when the word appears in *most* messages (inverse document frequency),
  so very common words like "the" count for little.

Words such as "prize", "claim" or "urgent" therefore get strong weights in scam
messages. TF-IDF is simple, fast, easy to explain and works very well for short texts
like SMS messages.

### Why these three algorithms?

| Algorithm | Why it was considered |
|-----------|----------------------|
| Multinomial Naive Bayes | Classic baseline for text classification; very fast; works well with word counts/TF-IDF |
| Logistic Regression | Strong linear model; gives real probabilities; coefficients are easy to interpret |
| Linear SVM | Often the most accurate linear model for high-dimensional sparse text data |

### How the final model is selected

The model is not chosen arbitrarily. Selection criteria (documented in `train_model.py`):

1. Highest **F1-score for the scam class** on the held-out test set (balances catching
   scams against false alarms).
2. If F1-scores are very close, prefer the higher **scam recall** (fewer missed scams).
3. The model must provide a **genuine probability estimate** for the confidence value.
   Linear SVM does not produce probabilities on its own, so it is wrapped in
   scikit-learn's `CalibratedClassifierCV`, which learns a calibrated mapping from SVM
   scores to probabilities using cross-validation. No confidence value is invented.

### Evaluation metrics explained

For the **scam** class:

- **True Positive (TP):** a scam correctly flagged as scam
- **False Positive (FP):** a legitimate message wrongly flagged as scam
- **False Negative (FN):** a scam wrongly marked legitimate (a *missed scam*)
- **True Negative (TN):** a legitimate message correctly marked legitimate

| Metric | Formula | Meaning |
|--------|---------|---------|
| Accuracy | (TP + TN) / all | Share of all messages classified correctly. Can be misleading when classes are imbalanced. |
| Precision | TP / (TP + FP) | Of the messages flagged as scams, how many really were scams. Low precision = many false alarms. |
| Recall | TP / (TP + FN) | Of all real scams, how many were caught. Low recall = many missed scams. |
| F1-score | 2 × P × R / (P + R) | Harmonic mean of precision and recall; high only when both are high. |

**Confusion matrix** - a 2×2 table showing the counts of TN, FP, FN and TP, so you can
see exactly which kinds of mistakes the model makes:

```
                    Predicted legitimate   Predicted scam
Actual legitimate          TN                   FP
Actual scam                FN                   TP
```

**Why false negatives matter:** a false negative tells a user that a scam looks fine,
which may lead them to send money or reveal a PIN. A false positive only causes extra
caution. That is why scam-class **recall** receives special attention.

**No evaluation on training data:** the dataset is split into training and test sets
(stratified, so both keep the same scam/legitimate ratio). The TF-IDF vectorizer is
fitted on the training set only, and all reported metrics come from the unseen test set.

## 6. Dataset requirements

Place a real labelled CSV file at `data/scam_dataset.csv`. No data is invented or
included. See [`data/README.md`](data/README.md) for full details.

| Column | Values |
|--------|--------|
| `message` | the message text |
| `label` | `0` = legitimate, `1` = scam |

Common alternative names (`text`, `v1`/`v2`, `ham`/`spam`, ...) are standardised
automatically by the training script.

## 7. Installation (Windows 11 + VS Code)

Requirements: **Python 3.10 or newer** (tick "Add python.exe to PATH" when installing).

Open the `scam_detection_system` folder in VS Code, open a terminal
(**Terminal → New Terminal**) and run:

```bat
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
copy .env.example .env
python -c "import secrets; print(secrets.token_hex(32))"
```

Paste the printed value into `.env` as `SECRET_KEY=...`.

> If PowerShell blocks `venv\Scripts\activate`, run once:
> `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`, or use a Command Prompt terminal.

In VS Code, press `Ctrl+Shift+P` → **Python: Select Interpreter** → choose the one
inside `venv`.

## 8. Training the model *(Phase 2)*

```bat
python train_model.py
```

## 9. Running the web application

```bat
python app.py
```

Open <http://127.0.0.1:5000> in a browser. Stop the server with `Ctrl+C`.

## 10. API documentation

### `GET /api/health`

```json
{ "status": "ok" }
```

### `POST /api/predict` *(Phase 3)*

Request:

```json
{ "message": "message text here" }
```

Response:

```json
{
  "prediction": "scam",
  "confidence": 0.94,
  "indicators": ["Urgent language", "Prize/reward claim"]
}
```

Errors are returned as JSON, e.g. `{ "error": "..." }` with an appropriate HTTP status code.

## 11. Testing

```bat
pytest -v
```

## 12. Security measures

- Secret key and settings come from environment variables (`.env`), never from source code
- `.env`, the database and generated model files are excluded from Git
- CSRF protection (Flask-WTF) for browser requests
- Maximum message length and maximum request size
- Security headers (Content-Security-Policy, X-Frame-Options, X-Content-Type-Options)
- User text is displayed with `textContent` / Jinja2 auto-escaping (no HTML injection)
- Submitted URLs are **never opened, fetched or executed**
- Friendly error pages; stack traces are only written to the server log
- Parameterised SQL queries and hashed admin passwords *(Phase 4)*

## 13. Limitations

- Machine learning predictions are not always correct.
- New scam techniques that do not resemble the training data may not be recognised.
- Performance depends heavily on the quality, size and relevance of the dataset
  (e.g. an English SMS spam dataset may not represent local mobile-money scams).
- A legitimate message can be incorrectly flagged as a scam (false positive).
- A scam message can be incorrectly classified as legitimate (false negative).
- The system must not be treated as a guarantee of safety.

## 14. Future improvements

The code is organised so these can be added later without a rewrite:

- SMS gateway integration and real-time detection
- Email scam and URL/phishing detection
- WhatsApp/message analysis where legally and technically possible
- Mobile application using the existing REST API
- Multilingual detection (e.g. Luganda, Swahili)
- Transformer models such as BERT
- Migration from SQLite to MySQL

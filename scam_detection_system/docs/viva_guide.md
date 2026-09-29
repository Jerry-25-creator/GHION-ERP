# Viva Guide - Machine Learning-Based Scam Detection System

Plain-language notes for presenting and defending the project. All numbers come from
`models/evaluation_results.json` (UCI SMS Spam Collection, random seed 42); they may
differ very slightly with other library versions.

---

## 1. The project in one minute

A Flask web application where a user pastes a text message and receives an automated
assessment: **Potential Scam** or **No obvious scam patterns detected**, with a
confidence estimate and a list of warning indicators. The decision is made by a
machine learning model (TF-IDF + calibrated Linear SVM) trained on 5,160 real,
labelled SMS messages. Separate rule-based indicators explain which common scam
tactics appear in the message. Analyses are stored in SQLite for an admin dashboard.
The system is honest about uncertainty: it never says a message is "100% safe".

## 2. The pipeline

```
Dataset (5,574 SMS) -> cleaning (414 duplicates removed -> 5,160)
  -> exploratory analysis (12.4% scam, 7:1 imbalance)
  -> stratified 80/20 split (4,128 train / 1,032 test)
  -> text preprocessing (lowercase, URLs/phones/money -> tokens)
  -> TF-IDF (10,135 words and word pairs)
  -> 3 models tuned with 5-fold cross-validation on the TRAINING set
  -> evaluation on the untouched TEST set
  -> model selection by documented rule -> saved with joblib -> used by Flask
```

## 3. Key concepts

**TF-IDF (Term Frequency - Inverse Document Frequency).** Converts text to numbers.
A word's weight is high when it appears in this message (TF) but is rare across all
messages (IDF). "Prize" in a message gets a high weight; "the" gets almost none. We
use single words and word pairs, so "claim prize" is also a feature.

**Why replace URLs, phone numbers and amounts with tokens?** Their *presence* is a
strong scam signal (59% of scams contain a phone number vs 0.1% of legitimate messages),
but the exact values almost never repeat. Deleting them would lose information;
keeping raw values would make the model memorise individual numbers.

**The three algorithms.**
- *Multinomial Naive Bayes*: probabilistic baseline, assumes words are independent, very fast.
- *Logistic Regression*: learns a weight per word, outputs probabilities directly.
- *Linear SVM*: finds the boundary with the widest margin between classes; strong on
  sparse, high-dimensional text data. It does not output probabilities, so we wrapped it
  in `CalibratedClassifierCV`, which learns (with cross-validation) how to turn SVM
  scores into genuine probabilities.

**Confusion matrix (selected model, test set).**

```
                    Predicted legitimate   Predicted scam
Actual legitimate          902 (TN)             2 (FP)
Actual scam                  5 (FN)           123 (TP)
```

**Metrics (scam class).**
- *Accuracy* = (TP+TN)/all = 99.3%. Misleading on its own: predicting "legitimate" for
  everything would already score 87.6%.
- *Precision* = TP/(TP+FP) = 98.4%: of messages flagged as scams, how many were scams.
- *Recall* = TP/(TP+FN) = 96.1%: of all real scams, how many we caught.
- *F1-score* = harmonic mean of precision and recall = 97.2%.

**Why false negatives matter most.** A false negative tells someone a scam looks fine,
and they may send money or reveal a PIN. A false positive only causes extra caution.
So scam recall is used as the tie-breaker when choosing a model, and LR/SVM use
`class_weight="balanced"` so the minority scam class is not ignored.

## 4. Why the Linear SVM was selected

The rule was decided *before* looking at results and is in `train_model.py`:

1. Highest cross-validation F1 for the scam class (on the training set).
2. If models are within 0.01 F1, choose the higher cross-validation scam recall.
3. The model must give genuine probabilities.

CV F1: Naive Bayes 0.9486, Logistic Regression 0.9554, Linear SVM 0.9573 - all within
0.01, so rule 2 applied: the SVM had the highest CV recall (0.9436). It also turned out
best on the test set (F1 0.9723), but the test set was **not** used to choose.

## 5. Likely questions

**Q: How do you know you did not evaluate on training data?**
The data is split once, before any training. The TF-IDF vectorizer is fitted on the
training set only (inside a scikit-learn `Pipeline`, including within each CV fold).
Duplicates were removed first, so the same message cannot be in both sets. A unit test
(`test_split_has_no_overlap_and_is_stratified`) checks there is no overlap.

**Q: Why use cross-validation as well as a test set?**
Cross-validation (5 folds on the training set) is used to tune settings and choose the
model. The test set is kept for one final, unbiased measurement. Choosing on the test
set would make the reported scores optimistic.

**Q: Is the confidence real?**
Yes. It is the calibrated probability from the model (Platt/sigmoid calibration learned
by cross-validation). The interface shows certainty levels and never shows 100%.

**Q: Aren't the warning indicators just keyword matching?**
They are, and they are deliberately kept separate. They never change the prediction; the
page labels them "Detected warning indicators" beneath the "Machine learning prediction".
They exist to explain the message to a non-technical user.

**Q: 99% accuracy - is the problem solved?**
No. The dataset is English SMS spam from around 2012, mostly UK and Singapore. Our own
example tests show the model misses gift-card, impersonation ("new number") and
advance-fee scams that contain no links or numbers, and flags genuine MoMo
transaction alerts (see README section 12). Local, current training data is the most
important improvement.

**Q: How is the system secured?**
Secrets in `.env`; CSRF tokens on forms; parameterised SQL; salted scrypt password hashes;
login lockout and rate limiting; strict Content-Security-Policy; Jinja2 auto-escaping; input
length limits; submitted links are never opened; no stack traces shown; minimal personal
data stored (phone numbers and e-mails redacted).

**Q: Why is `/api/predict` exempt from CSRF?**
CSRF attacks abuse a logged-in user's cookies. The API uses no login or cookies, so a
forged request gains nothing an attacker could not do directly. It also only accepts
JSON, which normal HTML forms on other websites cannot send.

**Q: Why SQLite, and can it move to MySQL?**
SQLite needs no server - ideal for a first version. All SQL is in `db.py` and uses
standard types; `database/schema_mysql.sql` has the MySQL version, so migration means
changing the connection and placeholders in one file.

**Q: How would you use BERT?**
Replace TF-IDF + SVM with a fine-tuned transformer (e.g. multilingual BERT). It
understands word order and meaning, which would help with scams that have no obvious
keywords, but needs more data, more computing power and is harder to explain.

## 6. Demonstration script (about 5 minutes)

1. Home page: purpose and the "verify independently" notice.
2. Analyze page: click **Prize message** -> Potential Scam, 98%, four indicators.
3. Click **Lunch plans** -> No obvious scam patterns detected.
4. Type the gift-card scam from section 5 -> classified legitimate: discuss limitations.
5. **How it works** page: metrics and limitations.
6. Admin login -> Dashboard (statistics, confusion matrix, model comparison) -> History.
7. Terminal: `pytest -v` (106 passed, 5 expected failures) and the API call with
   `Invoke-RestMethod` (README section 10).

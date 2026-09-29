"""
preprocessing.py - Text cleaning applied to every message before TF-IDF.

The SAME function (clean_text) is used when training the model and when
predicting new messages in the web app. If training and prediction used
different cleaning, the model would see different "words" and perform badly.

Design choice - normalise, do not delete:
    URLs, phone numbers, e-mail addresses and money amounts are strong scam
    signals, but the exact values rarely repeat (every scam uses a different
    link or number). So instead of deleting them we replace each one with a
    placeholder token, e.g.

        "Claim at http://win-now.biz/abc"  ->  "claim at urltoken"

    The model can then learn "messages containing a link are more often
    scams" without memorising individual links.

    Stop-words (e.g. "you", "your", "now") are NOT removed: words like
    "your" and "now" are common in scams, and TF-IDF already gives very
    common words a low weight.
"""

import math
import re
import unicodedata

# Placeholder tokens. Plain lowercase words so TF-IDF treats them like
# any other word.
URL_TOKEN = "urltoken"
EMAIL_TOKEN = "emailtoken"
PHONE_TOKEN = "phonetoken"
MONEY_TOKEN = "moneytoken"
NUMBER_TOKEN = "numtoken"
EXCLAMATION_TOKEN = "exclamationmark"

# --- Regular expressions (compiled once for speed) --------------------

EMAIL_RE = re.compile(r"\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b")

# http(s)://..., www...., or a bare domain such as bit.ly/abc or example.com
URL_RE = re.compile(
    r"(?:https?://\S+)"
    r"|(?:www\.\S+)"
    r"|(?:\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|info|biz|co|uk|ug|ke|tz|ly|io|me|xyz|top|online|site)\b(?:/\S*)?)"
)

# Money: a currency symbol/code before or after an amount,
# e.g. "£1000", "$50", "5,000,000 UGX", "ugx 20000", "shs 5000".
_CURRENCY = r"(?:£|\$|€|ugx|ushs|shs|ksh|kes|tzs|usd|gbp|eur)"
_AMOUNT = r"\d[\d,]*(?:\.\d+)?"
MONEY_RE = re.compile(
    rf"{_CURRENCY}\s?{_AMOUNT}(?:\s?(?:k|m|million|bn))?"
    rf"|{_AMOUNT}\s?(?:k|m|million)?\s?{_CURRENCY}(?![a-z])"
)

# Phone numbers: 7 or more digits, optionally with +, spaces or dashes.
PHONE_RE = re.compile(r"\+?\d[\d\s-]{5,}\d")

NUMBER_RE = re.compile(r"\d+")

# Anything that is not a letter, digit or whitespace.
PUNCTUATION_RE = re.compile(r"[^\w\s]|_")

WHITESPACE_RE = re.compile(r"\s+")


def clean_text(text):
    """
    Clean one message and return the normalised string.

    Steps:
        1. Handle missing values (None / NaN -> empty string)
        2. Unicode normalisation (e.g. fancy characters -> standard ones)
        3. Lower-case
        4. Replace e-mails, URLs, money amounts, phone numbers and other
           numbers with placeholder tokens (order matters: e-mails before
           URLs, money before phone numbers, phone numbers before numbers)
        5. Replace "!" with a token (scam messages use many exclamations)
        6. Remove remaining punctuation
        7. Collapse repeated whitespace
    """
    # 1. Missing values
    if text is None or (isinstance(text, float) and math.isnan(text)):
        return ""
    text = str(text)

    # 2 + 3. Normalise Unicode and convert to lowercase
    text = unicodedata.normalize("NFKC", text).lower()

    # 4. Replace useful-but-variable information with tokens
    text = EMAIL_RE.sub(f" {EMAIL_TOKEN} ", text)
    text = URL_RE.sub(f" {URL_TOKEN} ", text)
    text = MONEY_RE.sub(f" {MONEY_TOKEN} ", text)
    text = PHONE_RE.sub(f" {PHONE_TOKEN} ", text)
    text = NUMBER_RE.sub(f" {NUMBER_TOKEN} ", text)

    # 5. Keep exclamation marks as a feature
    text = text.replace("!", f" {EXCLAMATION_TOKEN} ")

    # 6. Remove the remaining punctuation
    text = PUNCTUATION_RE.sub(" ", text)

    # 7. Tidy whitespace
    return WHITESPACE_RE.sub(" ", text).strip()

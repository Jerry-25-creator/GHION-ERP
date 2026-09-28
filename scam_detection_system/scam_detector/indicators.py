"""
indicators.py - Simple, explainable warning-indicator checks.

IMPORTANT: these checks do NOT decide whether a message is a scam. The
decision is made only by the trained machine learning model
(see predictor.py). The indicators are shown next to the ML prediction to
help the user understand WHICH common scam patterns appear in the text.

Each indicator is a list of regular expressions. If any of them matches the
lower-cased message, the indicator is reported with a short explanation.
Links are only matched as text - they are never opened or visited.
"""

import re

from .preprocessing import MONEY_RE, URL_RE


class Indicator:
    """One warning sign: a name, an explanation and the patterns that detect it."""

    def __init__(self, name, explanation, patterns):
        self.name = name
        self.explanation = explanation
        self.patterns = [p if isinstance(p, re.Pattern) else re.compile(p) for p in patterns]

    def found_in(self, text):
        return any(pattern.search(text) for pattern in self.patterns)


INDICATORS = [
    Indicator(
        "Contains a link",
        "Scam messages often include links to fake websites. Do not open links "
        "you were not expecting; visit the organisation's official site yourself.",
        [URL_RE],
    ),
    Indicator(
        "Urgent language",
        "Pressure to act quickly is a common tactic to stop people from "
        "thinking carefully or checking with someone.",
        [r"\burgent(ly)?\b", r"\bimmediate(ly)?\b", r"\basap\b", r"\bexpir(e|es|ed|ing|y)\b",
         r"\bfinal (notice|warning|reminder|attempt)\b", r"\blast chance\b", r"\bact now\b",
         r"\bright (now|away)\b", r"\blimited time\b", r"\btoday only\b",
         r"\bwithin \d+\s?(hours?|hrs?|minutes?|mins?|days?)\b",
         r"\b(don'?t|do not) delay\b", r"\btoo late\b"],
    ),
    Indicator(
        "Prize or reward claim",
        "Unexpected prizes, winnings or rewards are one of the most common "
        "scam hooks. Genuine competitions rarely contact you out of the blue.",
        [r"\bcongrat(s|ulations)\b", r"\b(you('ve| have)? )?won\b", r"\bwinner\b", r"\bprize\b",
         r"\breward\b", r"\bjackpot\b", r"\blottery\b", r"\blucky (winner|draw|number|customer)\b", r"\bfree (gift|entry|cash)\b",
         r"\bbonus\b", r"\bvoucher\b", r"\b(have been|are|were|was) selected\b",
         r"\bclaim (your|now|it|the|a|this)\b"],
    ),
    Indicator(
        "Requests sensitive information",
        "Banks, mobile-money providers and other legitimate organisations never "
        "ask for your PIN, password or one-time code by message.",
        [r"\bpin\b", r"\bpass(word|code)\b", r"\botp\b", r"\bone[- ]time (pin|code|password)\b",
         r"\bverification code\b", r"\bsecurity code\b", r"\bcvv\b",
         r"\b(card|account|id) (number|details)\b", r"\bbank details\b", r"\blog ?in details\b",
         r"\bnational id\b", r"\bdate of birth\b"],
    ),
    Indicator(
        "Suspicious financial language",
        "Mentions of payments, transfers, fees or money amounts are common in "
        "scams that try to get you to send money.",
        [MONEY_RE, r"\bsend (money|cash|funds)\b", r"\btransfer\b", r"\bmobile money\b",
         r"\bm-?pesa\b", r"\bmomo\b", r"\bloan\b", r"\b(processing|registration|clearance) fee\b",
         r"\bdeposit\b", r"\brefund\b", r"\bwithdraw", r"\bcash (prize|reward|bonus)\b"],
    ),
    Indicator(
        "Account threat or problem",
        "Claims that your account is blocked, suspended or at risk are used to "
        "frighten people into acting quickly.",
        [r"\b(account|sim|line|card|wallet)\b.{0,40}\b(suspend|block|lock|deactivat|clos|restrict)",
         r"\b(suspend|block|lock|deactivat|restrict)\w*\b.{0,40}\b(account|sim|line|card|wallet)\b",
         r"\bunusual (activity|sign[- ]?in|login)\b", r"\bunauthori[sz]ed\b"],
    ),
    Indicator(
        "Suspicious call to action",
        "The message asks you to click, call, reply or confirm details. Contact "
        "the sender through official channels instead of replying directly.",
        [r"\bclick\b", r"\btap (here|the link|below)\b", r"\bcall (now|this|us|the number)\b",
         r"\b(text|sms|reply|send)\b\s+\w+\s+to\s+\d+", r"\breply (now|yes|with)\b",
         r"\b(verify|confirm|update|validate) (your|ur)\b", r"\bdial\b", r"\bvisit (our|the|this) (link|site|website|page)\b"],
    ),
]

# Formatting checks work on the ORIGINAL text (capital letters matter).
SHOUTING_RE = re.compile(r"\b[A-Z]{4,}\b")
EXCLAMATIONS_RE = re.compile(r"!{2,}")

FORMATTING_INDICATOR = (
    "Attention-grabbing formatting",
    "Many words in CAPITAL LETTERS or repeated exclamation marks are often used "
    "to create excitement or alarm.",
)


def find_indicators(message):
    """
    Return a list of {"name": ..., "explanation": ...} for every warning
    sign found in the message (empty list if none).
    """
    if not message:
        return []
    text = message.lower()
    found = [{"name": ind.name, "explanation": ind.explanation}
             for ind in INDICATORS if ind.found_in(text)]

    if len(SHOUTING_RE.findall(message)) >= 3 or EXCLAMATIONS_RE.search(message):
        name, explanation = FORMATTING_INDICATOR
        found.append({"name": name, "explanation": explanation})
    return found

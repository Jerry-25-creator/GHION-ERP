"""
example_messages.py - Example messages used to test the trained model.

These messages were WRITTEN FOR TESTING and are clearly labelled as
examples. They are never used for training. They include styles that
are NOT well represented in the training data (e.g. East African
mobile-money scams) so the tests also show the model's limitations.

Each entry: (message, expected_label)   0 = legitimate, 1 = scam
"""

SCAM_EXAMPLES = [
    # Prize / lottery style (similar to the training data)
    ("Congratulations! You have won 5,000,000 UGX. Click this link immediately to claim your prize.", 1),
    ("WINNER!! You have been selected to receive a £900 prize reward. To claim call 09061701461 now.", 1),
    ("FREE entry into our weekly draw to win a brand new phone! Text WIN to 80086 now. T&Cs apply", 1),
    ("You have won a guaranteed cash award of $2000. Call 0906 170 1461 to claim before it expires", 1),
    # Phishing / account threats
    ("URGENT: Your account has been suspended. Verify your details at http://secure-login-update.xyz within 24 hours.", 1),
    ("Your bank card has been blocked due to unusual activity. Click www.card-unlock-now.com to reactivate.", 1),
    # Mobile-money style (less common in the training data)
    ("Your mobile money account has been blocked. Send your PIN to 0772123456 to unlock it.", 1),
    ("Dear customer you have received 350,000 UGX by mistake. Kindly send it back to 0701234567 urgently.", 1),
    ("You qualify for an instant loan of 2,000,000 UGX. Pay a processing fee of 50,000 UGX to receive the money today.", 1),
]

LEGITIMATE_EXAMPLES = [
    ("Hi, are we still meeting for lunch tomorrow at 1pm? Let me know if you are running late.", 0),
    ("Reminder: your dentist appointment is on Monday at 10am. Please arrive 10 minutes early.", 0),
    ("Hi mum, I will be home late tonight. Can you keep some food for me?", 0),
    ("Can you send me the notes from today's lecture when you get a chance?", 0),
    ("Happy birthday! Hope you have a wonderful day with your family.", 0),
    ("The meeting has been moved to room 4 at 3pm. See you there.", 0),
    ("I'm at the shop, do we need milk or bread?", 0),
    ("Thanks for your help yesterday, I really appreciate it.", 0),
    ("Did you watch the match last night? What a goal in the second half!", 0),
]

ALL_EXAMPLES = SCAM_EXAMPLES + LEGITIMATE_EXAMPLES

# Messages the current model is KNOWN to get wrong (observed when testing the
# model trained on the SMS Spam Collection). Kept as evidence for the
# "Limitations" section: they are scam types or message styles that are rare
# in the training data. The tests mark them as expected failures (xfail).
KNOWN_DIFFICULT_EXAMPLES = [
    # Scams with no links, numbers or prize words
    ("Hi, this is your manager. I'm in a meeting, please buy three gift cards for a client "
     "and send me the codes quickly.", 1),
    ("Hello dear, I am a soldier on a peace mission and need your help to move some funds. "
     "You will get 30 percent.", 1),
    ("Hello mum, I lost my phone, this is my new number. Please send me 200,000 for an emergency.", 1),
    # Genuine transactional messages that contain numbers and money amounts
    ("Your MTN MoMo transaction of UGX 20,000 to John was successful. New balance UGX 45,000.", 0),
    ("Dear customer, your electricity token is 1234-5678", 0),
]

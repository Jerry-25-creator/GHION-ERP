"""
scam_detector - reusable machine learning code for the Scam Detection System.

Modules:
    dataset.py        load, validate and standardise the labelled dataset
    preprocessing.py  clean/normalise message text before TF-IDF

Keeping this code in a package (instead of inside app.py or train_model.py)
means the SAME preprocessing is used during training and during prediction.
"""

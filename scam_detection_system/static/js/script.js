/* ------------------------------------------------------------
   Scam Detection System - front-end behaviour
   ------------------------------------------------------------ */

document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("analyze-form");
    if (!form) {
        return; // Not on the analyze page.
    }

    const textarea = document.getElementById("message");
    const charCount = document.getElementById("char-count");
    const feedback = document.getElementById("form-feedback");
    const clearBtn = document.getElementById("clear-btn");
    const resultBox = document.getElementById("result");
    const maxLength = parseInt(textarea.dataset.maxLength, 10);

    // Update the "0 / 5000 characters" counter as the user types.
    function updateCounter() {
        charCount.textContent = textarea.value.length;
    }

    textarea.addEventListener("input", function () {
        updateCounter();
        feedback.textContent = "";
    });

    // Clear button: empty the text box and any previous result.
    clearBtn.addEventListener("click", function () {
        textarea.value = "";
        feedback.textContent = "";
        resultBox.replaceChildren();
        updateCounter();
        textarea.focus();
    });

    form.addEventListener("submit", function (event) {
        event.preventDefault();
        const message = textarea.value.trim();

        // Client-side validation (the server validates again in Phase 3).
        if (message.length === 0) {
            feedback.textContent = "Please enter a message to analyze.";
            return;
        }
        if (message.length > maxLength) {
            feedback.textContent = "The message is too long (maximum " + maxLength + " characters).";
            return;
        }

        // Phase 1: the ML model is not connected yet.
        // In Phase 3 this will send the message to POST /api/predict.
        showInfo("The analysis engine will be connected in Phase 3, after the model has been trained.");
    });

    // Build the message with textContent (never innerHTML) so user text
    // cannot inject HTML or scripts into the page.
    function showInfo(text) {
        const card = document.createElement("div");
        card.className = "result-card result-info";
        const p = document.createElement("p");
        p.className = "mb-0";
        p.textContent = text;
        card.appendChild(p);
        resultBox.replaceChildren(card);
    }

    updateCounter();
});

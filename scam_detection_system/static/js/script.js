/* ------------------------------------------------------------
   Scam Detection System - front-end behaviour
   The form works without JavaScript; this script only adds a
   character counter, a Clear button and quick input checks.
   The server always validates the message again.
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
    const analyzeBtn = document.getElementById("analyze-btn");
    const maxLength = parseInt(textarea.dataset.maxLength, 10);

    // Update the "0 / 5000 characters" counter as the user types.
    function updateCounter() {
        charCount.textContent = textarea.value.length;
    }

    textarea.addEventListener("input", function () {
        updateCounter();
        feedback.textContent = "";
    });

    // Clear button: empty the text box.
    clearBtn.addEventListener("click", function () {
        textarea.value = "";
        feedback.textContent = "";
        updateCounter();
        textarea.focus();
    });

    form.addEventListener("submit", function (event) {
        const message = textarea.value.trim();

        // Quick checks in the browser, so the user gets instant feedback.
        if (message.length === 0) {
            event.preventDefault();
            feedback.textContent = "Please enter a message to analyze.";
            return;
        }
        if (message.length > maxLength) {
            event.preventDefault();
            feedback.textContent = "The message is too long (maximum " + maxLength + " characters).";
            return;
        }

        // Prevent double submission while the server is working.
        analyzeBtn.disabled = true;
        analyzeBtn.textContent = "Analyzing...";
    });

    // Keep the counter correct when the page is re-shown with a message
    // (e.g. after a validation error or using the browser's Back button).
    window.addEventListener("pageshow", function () {
        analyzeBtn.disabled = false;
        analyzeBtn.textContent = "Analyze Message";
        updateCounter();
    });

    updateCounter();
});

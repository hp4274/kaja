(function () {
  "use strict";
  var form = document.getElementById("appointmentBookingForm");
  var fb = document.getElementById("ap-feedback");
  if (!form || !fb) return;

  var apDate = document.getElementById("ap-date");
  function resetMinDate() {
    if (apDate) apDate.min = new Date().toISOString().split("T")[0];
  }
  resetMinDate();

  /* The appointment page does not load the shared form stylesheet, so the
     feedback banner is styled inline to stay self-contained. */
  var ALERT_STYLES = {
    pending: { bg: "rgba(0, 55, 62, 0.06)", color: "#00373e", border: "rgba(0, 55, 62, 0.18)" },
    success: { bg: "rgba(31, 122, 90, 0.08)", color: "#1f7a5a", border: "rgba(31, 122, 90, 0.28)" },
    error: { bg: "rgba(211, 47, 47, 0.07)", color: "#c62828", border: "rgba(211, 47, 47, 0.28)" }
  };

  function setFeedback(kind, text) {
    var s = ALERT_STYLES[kind];
    fb.hidden = false;
    fb.textContent = text;
    fb.style.display = "block";
    fb.style.padding = "0.85rem 1rem";
    fb.style.marginBottom = "1rem";
    fb.style.borderRadius = "8px";
    fb.style.fontSize = "0.88rem";
    fb.style.lineHeight = "1.5";
    fb.style.backgroundColor = s.bg;
    fb.style.color = s.color;
    fb.style.border = "1px solid " + s.border;
  }

  /* Error text is appended to the field's wrapper. The appointment markup uses
     Bootstrap grid columns rather than .ra-form-group, so fall back to the
     direct parent when no known wrapper class is present. */
  function fieldWrapper(field) {
    return field.closest(".ra-form-group") || field.closest(".col-12, .col-md-6") || field.parentElement;
  }

  function clearFieldError(field) {
    var wrap = fieldWrapper(field);
    if (!wrap) return;
    var err = wrap.querySelector(".invalid-feedback-custom");
    if (err) err.remove();
  }

  function clearAllFieldErrors() {
    form.querySelectorAll(".invalid-feedback-custom").forEach(function (el) {
      el.remove();
    });
  }

  function showAppointmentFormValidationErrors() {
    form.classList.add("was-validated");
    clearAllFieldErrors();

    form.querySelectorAll(":invalid").forEach(function (field) {
      if (field.type === "hidden" || field.tagName === "BUTTON") return;

      var wrap = fieldWrapper(field);
      if (!wrap || wrap.querySelector(".invalid-feedback-custom")) return;

      var errorDiv = document.createElement("div");
      errorDiv.className = "invalid-feedback-custom";
      errorDiv.style.color = "#d32f2f";
      errorDiv.style.fontSize = "0.78rem";
      errorDiv.style.marginTop = "0.35rem";
      errorDiv.style.fontFamily = "var(--font-body)";
      errorDiv.textContent = field.validationMessage || "This field is required.";
      wrap.appendChild(errorDiv);
    });

    var firstInvalid = form.querySelector(":invalid");
    if (firstInvalid && firstInvalid.type !== "hidden") {
      firstInvalid.scrollIntoView({ behavior: "smooth", block: "center" });
      firstInvalid.focus({ preventScroll: true });
    }
  }

  function onFieldChanged(e) {
    if (!form.classList.contains("was-validated")) return;
    if (e.target.checkValidity && e.target.checkValidity()) clearFieldError(e.target);
  }

  form.addEventListener("input", onFieldChanged);
  form.addEventListener("change", onFieldChanged);

  form.addEventListener("submit", function (e) {
    e.preventDefault();

    if (!form.checkValidity()) {
      showAppointmentFormValidationErrors();
      return;
    }

    var submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    setFeedback("pending", "Submitting request...");

    fetch("submit-form.php", {
      method: "POST",
      body: new FormData(form)
    })
      .then(function (response) {
        return response.json().catch(function () {
          throw new Error("Unexpected server response.");
        });
      })
      .then(function (data) {
        if (data.success) {
          setFeedback("success", data.message || "Thank you! Your request has been sent successfully.");
          form.reset();
          form.classList.remove("was-validated");
          clearAllFieldErrors();
          resetMinDate();
        } else {
          setFeedback("error", data.error || "An error occurred. Please try again.");
        }
      })
      .catch(function () {
        setFeedback("error", "Network error. Please check your connection and try again.");
      })
      .finally(function () {
        if (submitBtn) submitBtn.disabled = false;
        fb.scrollIntoView({ behavior: "smooth", block: "center" });
      });
  });
})();

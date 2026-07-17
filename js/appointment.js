(function () {
  "use strict";
  var form = document.getElementById("appointmentBookingForm");
  var fb = document.getElementById("ap-feedback");
  if (!form || !fb) return;
  var apDate = document.getElementById("ap-date");
  if (apDate) {
    apDate.min = new Date().toISOString().split("T")[0];
  }

  function showAppointmentFormValidationErrors() {
    form.classList.add("was-validated");
    form.querySelectorAll(".invalid-feedback-custom").forEach(function (el) {
      el.remove();
    });
    
    var invalidFields = form.querySelectorAll(":invalid");
    invalidFields.forEach(function (field) {
      if (field.type === "hidden" || field.tagName === "BUTTON") return;
      
      var parent = field.closest(".ra-form-group");
      if (parent) {
        var errorDiv = document.createElement("div");
        errorDiv.className = "invalid-feedback-custom";
        errorDiv.style.color = "#d32f2f";
        errorDiv.style.fontSize = "0.78rem";
        errorDiv.style.marginTop = "0.35rem";
        errorDiv.style.fontFamily = "var(--font-body)";
        errorDiv.textContent = field.validationMessage || "This field is required.";
        parent.appendChild(errorDiv);
      }
    });
  }

  form.addEventListener("input", function (e) {
    if (form.classList.contains("was-validated")) {
      var field = e.target;
      if (field.checkValidity()) {
        var parent = field.closest(".ra-form-group");
        if (parent) {
          var err = parent.querySelector(".invalid-feedback-custom");
          if (err) err.remove();
        }
      }
    }
  });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    
    if (!form.checkValidity()) {
      showAppointmentFormValidationErrors();
      return;
    }
    
    var formData = new FormData(form);
    fb.hidden = false;
    fb.className = "ra-form-alert";
    fb.style.backgroundColor = "rgba(0, 55, 62, 0.05)";
    fb.style.color = "#00373e";
    fb.textContent = "Submitting request...";
    
    fetch("submit-form.php", {
      method: "POST",
      body: formData
    })
    .then(function (response) {
      return response.json();
    })
    .then(function (data) {
      fb.removeAttribute("style");
      if (data.success) {
        fb.className = "ra-form-alert success";
        fb.textContent = data.message || "Thank you! Your request has been sent successfully.";
        form.reset();
        form.classList.remove("was-validated");
        form.querySelectorAll(".invalid-feedback-custom").forEach(function (el) {
          el.remove();
        });
        if (apDate) {
          apDate.min = new Date().toISOString().split("T")[0];
        }
      } else {
        fb.className = "ra-form-alert error";
        fb.textContent = data.error || "An error occurred. Please try again.";
      }
    })
    .catch(function (err) {
      fb.removeAttribute("style");
      fb.className = "ra-form-alert error";
      fb.textContent = "Network error. Please check your connection and try again.";
    });
  });
})();

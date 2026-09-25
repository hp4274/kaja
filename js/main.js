/**
 * Rewire With Kajal — behaviour aligned with live React app (carousel, FAQ, scroll reveal)
 */
(function () {
  "use strict";

  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* --- Hero carousel: auto-advance + arrow keys (no on-screen controls) --- */
  function initHeroCarousel() {
    var panel = document.getElementById("hero-panel");
    if (!panel) return;
    var slides = panel.querySelectorAll(".rw-hero-slide");
    if (!slides.length) return;

    var heroIndex = 0;
    var heroTimer = null;

    function setHeroSlide(index) {
      heroIndex = (index + slides.length) % slides.length;
      slides.forEach(function (el, i) {
        var on = i === heroIndex;
        el.classList.toggle("is-active", on);
        el.setAttribute("aria-hidden", on ? "false" : "true");
      });
    }

    function restartHeroTimer() {
      if (heroTimer) window.clearInterval(heroTimer);
      heroTimer = window.setInterval(function () {
        setHeroSlide(heroIndex + 1);
      }, 6500);
    }

    setHeroSlide(0);
    restartHeroTimer();

    document.addEventListener("keydown", function heroKeyNav(e) {
      if (!slides.length) return;
      var t = e.target;
      if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.isContentEditable)) return;
      if (e.key === "ArrowLeft") {
        setHeroSlide(heroIndex - 1);
        restartHeroTimer();
      } else if (e.key === "ArrowRight") {
        setHeroSlide(heroIndex + 1);
        restartHeroTimer();
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initHeroCarousel);
  } else {
    initHeroCarousel();
  }

  /* --- Sticky / scrolled navbar --- */
  const nav = document.getElementById("site-nav");
  function onScrollNav() {
    if (!nav) return;
    if (window.scrollY > 24) {
      nav.classList.add("navbar--scrolled");
    } else {
      nav.classList.remove("navbar--scrolled");
    }
  }
  window.addEventListener("scroll", onScrollNav, { passive: true });
  onScrollNav();

  /* --- Mobile nav drawer --- */
  const navToggle = document.querySelector("[data-nav-toggle]");
  const navDrawer = document.getElementById("nav-drawer");
  if (navToggle && navDrawer) {
    navToggle.addEventListener("click", function () {
      const isHidden = navDrawer.hasAttribute("hidden");
      if (isHidden) {
        navDrawer.removeAttribute("hidden");
        navToggle.setAttribute("aria-expanded", "true");
      } else {
        navDrawer.setAttribute("hidden", "");
        navToggle.setAttribute("aria-expanded", "false");
      }
    });
    navDrawer.querySelectorAll("a").forEach(function (a) {
      a.addEventListener("click", function () {
        navDrawer.setAttribute("hidden", "");
        navToggle.setAttribute("aria-expanded", "false");
      });
    });
  }

  /* --- FAQ accordion (single open, matches Home.tsx pattern) --- */
  const faqRoot = document.getElementById("faq-list");
  if (faqRoot) {
    faqRoot.addEventListener("click", function (e) {
      const btn = e.target.closest("[data-faq-btn]");
      if (!btn || !faqRoot.contains(btn)) return;
      const item = btn.closest(".rw-faq-item");
      if (!item) return;
      const wasOpen = item.classList.contains("open");
      faqRoot.querySelectorAll(".rw-faq-item").forEach(function (art) {
        art.classList.remove("open");
        const b = art.querySelector("[data-faq-btn]");
        const ic = art.querySelector(".rw-faq-toggle");
        if (b) b.setAttribute("aria-expanded", "false");
        if (ic) ic.innerHTML = '<i class="bi bi-chevron-down"></i>';
      });
      if (!wasOpen) {
        item.classList.add("open");
        btn.setAttribute("aria-expanded", "true");
        const ic = item.querySelector(".rw-faq-toggle");
        if (ic) ic.innerHTML = '<i class="bi bi-chevron-up"></i>';
      }
    });
  }

  /* --- Scroll reveal (.rw-reveal.is-visible) --- */
  const revealEls = document.querySelectorAll(".rw-reveal");
  if (reduced) {
    revealEls.forEach(function (el) {
      el.classList.add("is-visible");
    });
  } else if ("IntersectionObserver" in window) {
    const obs = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            obs.unobserve(entry.target);
          }
        });
      },
      { root: null, rootMargin: "0px 0px -6% 0px", threshold: 0.06 }
    );
    revealEls.forEach(function (el) {
      obs.observe(el);
    });
  } else {
    revealEls.forEach(function (el) {
      el.classList.add("is-visible");
    });
  }

  /* --- Process: sequential steps from vertical scroll (not per-column IO — avoids all-at-once) --- */
  function initProcessTimeline() {
    var section = document.getElementById("process-section") || document.querySelector(".rw-process-steps");
    var root = document.querySelector(".rw-process-columns");
    if (!section || !root) return;
    var steps = root.querySelectorAll("[data-process-step]");
    var total = steps.length;
    if (!total) return;

    function setStepState(completedCount) {
      var n = Math.max(0, Math.min(total, completedCount));
      root.style.setProperty("--rw-process-fill", String(n / total));
      steps.forEach(function (step, i) {
        var done = i < n;
        var current = done && i === n - 1;
        step.classList.toggle("is-complete", done);
        step.classList.toggle("is-current", current);
        var circle = step.querySelector(".rw-process-node__circle");
        if (circle) circle.classList.toggle("rw-process-node__circle--active", done);
      });
    }

    /*
      Natural progress: (vh - rect.top) / (vh + rect.height), clamped — no (p-0.1)/0.9 remap.
      Six steps use custom thresholds so 5 & 6 sit earlier; other counts use equal bands.
    */
    function sectionScrollProgress(rect, vh) {
      var h = rect.height;
      if (h <= 0) return 0;
      var denom = vh + h;
      if (denom <= 0) return 0;
      var p = (vh - rect.top) / denom;
      if (p < 0) p = 0;
      if (p > 1) p = 1;
      return p;
    }

    function progressToCompletedCount(progress, totalSteps) {
      if (totalSteps <= 0) return 0;
      if (progress <= 0) return 0;
      if (progress >= 1) return totalSteps;
      if (totalSteps === 6) {
        var t = [0.15, 0.3, 0.4, 0.45, 0.5, 0.52];
        var n = 0;
        for (var i = 0; i < t.length; i++) {
          if (progress >= t[i]) n = i + 1;
        }
        return n;
      }
      return Math.min(totalSteps, Math.floor(progress * totalSteps + 1e-9));
    }

    function scrollProgressThroughSection() {
      var rect = section.getBoundingClientRect();
      var vh = window.innerHeight || 1;
      var progress = sectionScrollProgress(rect, vh);
      var completedCount = Math.min(total, progressToCompletedCount(progress, total));
      setStepState(completedCount);
    }

    if (reduced) {
      setStepState(total);
      return;
    }

    var ticking = false;
    function onScrollOrResize() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        ticking = false;
        scrollProgressThroughSection();
      });
    }

    window.addEventListener("scroll", onScrollOrResize, { passive: true });
    window.addEventListener("resize", onScrollOrResize);
    window.addEventListener("load", function () {
      scrollProgressThroughSection();
    });

    /* Center band: keeps rAF scroll updates aligned with middle-of-viewport focus */
    if (section && "IntersectionObserver" in window) {
      var processIo = new IntersectionObserver(
        function () {
          scrollProgressThroughSection();
        },
        {
          root: null,
          rootMargin: "-35% 0px -35% 0px",
          threshold: [0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1],
        }
      );
      processIo.observe(section);
    }

    scrollProgressThroughSection();
    requestAnimationFrame(function () {
      scrollProgressThroughSection();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initProcessTimeline);
  } else {
    initProcessTimeline();
  }

  /* --- Booking form (PHP AJAX submission) --- */
  const bf = document.getElementById("bookingForm");
  const bff = document.getElementById("bf-feedback");
  
  function showBookingFormValidationErrors() {
    bf.classList.add("was-validated");
    bf.querySelectorAll(".invalid-feedback-custom").forEach(function (el) {
      el.remove();
    });
    
    var invalidFields = bf.querySelectorAll(":invalid");
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

  if (bf && bff) {
    var bfDate = document.getElementById("bf-date");
    if (bfDate) {
      bfDate.min = new Date().toISOString().split("T")[0];
    }
    
    bf.addEventListener("input", function (e) {
      if (bf.classList.contains("was-validated")) {
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

    bf.addEventListener("submit", function (e) {
      e.preventDefault();
      
      if (!bf.checkValidity()) {
        showBookingFormValidationErrors();
        return;
      }
      
      const formData = new FormData(bf);
      bff.hidden = false;
      bff.className = "ra-form-alert";
      bff.style.backgroundColor = "rgba(0, 55, 62, 0.05)";
      bff.style.color = "#00373e";
      bff.textContent = "Submitting request...";
      
      fetch("api/submit-form.php", {
        method: "POST",
        body: formData
      })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        bff.removeAttribute("style"); // Clear loading styles
        if (data.success) {
          bff.className = "ra-form-alert success";
          bff.textContent = data.message || "Thank you! Your request has been sent successfully.";
          bf.reset();
          bf.classList.remove("was-validated");
          bf.querySelectorAll(".invalid-feedback-custom").forEach(function (el) {
            el.remove();
          });
          if (bfDate) {
            bfDate.min = new Date().toISOString().split("T")[0];
          }
        } else {
          bff.className = "ra-form-alert error";
          bff.textContent = data.error || "An error occurred. Please try again.";
        }
      })
      .catch(function (err) {
        bff.removeAttribute("style");
        bff.className = "ra-form-alert error";
        bff.textContent = "Network error. Please check your connection and try again.";
      });
    });
  }

  /* --- Smooth scroll for same-page hash links --- */
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener("click", function (e) {
      const id = a.getAttribute("href");
      if (!id || id === "#") return;
      const t = document.querySelector(id);
      if (!t) return;
      e.preventDefault();
      t.scrollIntoView({ behavior: reduced ? "auto" : "smooth", block: "start" });
    });
  });
})();

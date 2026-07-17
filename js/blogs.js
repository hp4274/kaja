/**
 * Topic filters for blogs.html (static stand-in for live /blogs filters).
 */
(function () {
  "use strict";
  const root = document.getElementById("blog-filters");
  if (!root) return;

  function applyTopic(topic) {
    var items = document.querySelectorAll("[data-topics]");
    items.forEach(function (el) {
      var topics = (el.getAttribute("data-topics") || "").split(/\s+/).filter(Boolean);
      var show = topic === "all" || topics.indexOf(topic) !== -1;
      el.style.display = show ? "" : "none";
    });
  }

  root.querySelectorAll("[data-topic]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var topic = btn.getAttribute("data-topic") || "all";
      root.querySelectorAll(".blog-premium-filter-pill").forEach(function (b) {
        b.classList.toggle("active", b === btn);
      });
      applyTopic(topic);
    });
  });
})();

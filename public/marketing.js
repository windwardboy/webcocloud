/*
 * Daily totals for Webco Cloud marketing. No cookies and no visitor id.
 *
 * Webco Cloud includes this itself. Each demo adds one script tag:
 *   <script src="https://webcocloud.net/marketing.js" data-webco-site="essential"></script>
 *   <script src="https://webcocloud.net/marketing.js" data-webco-site="professional"></script>
 * Mark only the floating purchase control with data-webco-cta.
 * A printed or QR link can add ?src=mail so the source is counted as direct mail.
 */
(function () {
  if (navigator.webdriver || document.prerendering || document.visibilityState === "prerender") return;

  var script = document.querySelector("script[data-webco-site]");
  if (!script) return;
  var site = script.getAttribute("data-webco-site");
  if (site !== "essential" && site !== "professional" && site !== "cloud") return;

  var endpoint = "https://webcocloud.net/marketing-collect.php";

  function pathName() {
    var path = location.pathname || "/";
    if (path.length > 1 && path.charAt(path.length - 1) === "/") path = path.slice(0, -1);
    return path.toLowerCase();
  }

  function pageAllowed() {
    var path = pathName();
    if (site === "cloud") {
      return path === "/web-design/hgv-driver-training" || path === "/start";
    }
    return path.length <= 120 && /^\/[a-z0-9/_.~-]*$/.test(path) && path.indexOf("..") === -1;
  }

  function hostMatches(host, names) {
    for (var i = 0; i < names.length; i++) {
      if (host === names[i] || host.endsWith("." + names[i])) return true;
    }
    return false;
  }

  function source() {
    var mailed = false;
    try {
      mailed = new URLSearchParams(location.search).get("src") === "mail";
    } catch (error) {
      mailed = false;
    }
    if (mailed) return "mail";

    var host = "";
    try {
      host = new URL(document.referrer).hostname.toLowerCase();
    } catch (error) {
      host = "";
    }
    if (!host || host === location.hostname.toLowerCase()) return "direct";
    if (hostMatches(host, ["google.com", "google.co.uk", "bing.com", "yahoo.com", "yahoo.co.uk", "duckduckgo.com", "ecosia.org", "brave.com"])) {
      return "search";
    }
    if (hostMatches(host, ["facebook.com", "instagram.com", "linkedin.com", "twitter.com", "x.com", "t.co", "tiktok.com", "youtube.com", "youtu.be", "threads.net"])) {
      return "social";
    }
    return "referral";
  }

  function packageName() {
    if (site !== "cloud" || pathName() !== "/start") return "";
    var value = "";
    try {
      value = new URLSearchParams(location.search).get("package") || "";
    } catch (error) {
      value = "";
    }
    return value === "essential" || value === "professional" ? value : "";
  }

  function send(events) {
    if (!pageAllowed()) return;
    var body = JSON.stringify({
      events: events,
      path: location.pathname,
      source: source(),
      package: packageName(),
    });
    try {
      var blob = new Blob([body], { type: "text/plain;charset=UTF-8" });
      if (navigator.sendBeacon && navigator.sendBeacon(endpoint, blob)) return;
    } catch (error) {
      /* fetch below */
    }
    try {
      fetch(endpoint, {
        method: "POST",
        mode: "no-cors",
        keepalive: true,
        headers: { "Content-Type": "text/plain;charset=UTF-8" },
        body: body,
      });
    } catch (error) {
      /* counting is best-effort */
    }
  }

  document.addEventListener(
    "click",
    function (event) {
      var target = event.target;
      if (!target || !target.closest || !target.closest("[data-webco-cta]")) return;
      send(["cta_click"]);
    },
    true
  );

  var events = ["page_view"];
  try {
    if (sessionStorage.getItem("webco-mkt-visit") !== "1") {
      sessionStorage.setItem("webco-mkt-visit", "1");
      events.push("visit");
    }
  } catch (error) {
    /* storage blocked: count the page view only */
  }
  send(events);
})();

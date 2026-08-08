(function () {
  "use strict";

  var SALES_PHONE = "919311966189"; // Zest Exhibitions sales WhatsApp

  var toggle = document.getElementById("chatToggle");
  var panel = document.getElementById("chatPanel");
  var body = document.getElementById("chatBody");
  var quickWrap = document.getElementById("chatQuick");
  var inputRow = document.getElementById("chatInputRow");
  var input = document.getElementById("chatInput");
  if (!toggle || !panel) return;

  var opened = false;
  var greeted = false;

  var lead = {};
  var state = "idle"; // idle | pricing_name | pricing_phone | lead_service | lead_name | lead_phone | lead_city | lead_area | lead_interior_req

  var PRICING_WORDS = ["price","pricing","cost","rate","rates","budget","quote","quotation","charge","charges","expensive","cheap","how much","fees","fee","estimate","discount"];

  var KB = {
    about: "Zest Exhibitions is a Noida-based exhibition stall design and fabrication company. Design, fabrication, printing, installation and dismantling all happen in-house &mdash; for exhibition stalls, pavilions and commercial interiors, across India and internationally. We've delivered 250+ stalls across 18+ industries.",
    services: "We offer Exhibition Stall Design, Exhibition Stall Fabrication &amp; Erection, Modular &amp; Double-Deck Stalls, Country Pavilion Design, Turnkey Solutions, Commercial Interiors, Project Management, Graphic Printing, and Installation/Dismantling/Storage. Want to explore our <a href='services.html'>full services list</a>?",
    portfolio: "We've built for brands like Danone, Mars, Royal Canin, Towa, Amul, Mario Industries and more, across FMCG, manufacturing, technology and government pavilions. Real project photos are on our <a href='portfolio.html'>Portfolio page</a>.",
    why: "Three things clients tell us matter most: No Missed Deadlines (stalls handed over ahead of the organiser's build window), Flawless Finish Quality (everything fabricated in-house under one quality standard), and a Transparent Process (one accountable team from design to teardown, no vendor handoffs).",
    industries: "We work across Manufacturing, MNCs &amp; Corporates, Government Bodies, Event Agencies, Startups and International Brands. More detail on our <a href='industries.html'>Industries page</a>.",
    policies: "We don't outsource fabrication &mdash; everything happens in our own Noida workshop. For international shows we also handle customs, freight and on-ground installation. Full details are in our <a href='privacy.html'>Privacy Policy</a> and <a href='terms.html'>Terms</a>.",
    contact: "You can reach us at <a href='tel:+919311966189'>+91 93119 66189</a>, <a href='mailto:info@zestexhibitions.com'>info@zestexhibitions.com</a>, or our Noida office at Block-B, 103, B Block, Sector 2, Noida, Uttar Pradesh 201301.",
    workshop: "Our design studio, fabrication floor and print unit are all under one roof in Noida &mdash; that's how we keep quality and timelines consistent across every build."
  };

  function scrollDown() { body.scrollTop = body.scrollHeight; }

  function addBot(html, delay) {
    setTimeout(function () {
      var d = document.createElement("div");
      d.className = "chat-msg bot";
      d.innerHTML = html;
      body.appendChild(d);
      scrollDown();
    }, delay || 0);
  }

  function addUser(text) {
    var d = document.createElement("div");
    d.className = "chat-msg user";
    d.textContent = text;
    body.appendChild(d);
    scrollDown();
  }

  function setQuick(options) {
    quickWrap.innerHTML = "";
    options.forEach(function (opt) {
      var b = document.createElement("button");
      b.type = "button";
      b.textContent = opt.label;
      b.addEventListener("click", function () {
        addUser(opt.label);
        quickWrap.innerHTML = "";
        opt.action();
      });
      quickWrap.appendChild(b);
    });
  }

  function clearQuick() { quickWrap.innerHTML = ""; }

  function mainMenu() {
    state = "idle";
    addBot("What would you like to do?", 200);
    setTimeout(function () {
      setQuick([
        { label: "About Zest", action: function () { showInfo("about"); } },
        { label: "Our Services", action: function () { showInfo("services"); } },
        { label: "Get a Quote", action: startLead },
        { label: "Talk to Sales", action: function () { startPricing(); } }
      ]);
    }, 220);
  }

  function showInfo(topic) {
    addBot(KB[topic] || "Let me get someone from our team to help with that.", 150);
    setTimeout(function () {
      addBot("Anything else?", 100);
      setQuick([
        { label: "Our Portfolio", action: function () { showInfo("portfolio"); } },
        { label: "Why Choose Zest", action: function () { showInfo("why"); } },
        { label: "Industries We Serve", action: function () { showInfo("industries"); } },
        { label: "Get a Quote", action: startLead },
        { label: "Main Menu", action: mainMenu }
      ]);
    }, 900);
  }

  /* ---------- Pricing deflection ---------- */
  function startPricing() {
    state = "pricing_name";
    lead = {};
    addBot("Pricing depends on your stall size, materials and finish, so I'll have our sales team send you an accurate quote directly rather than guess here. Could I get your name?", 150);
  }

  function waLink(message) {
    return "https://wa.me/" + SALES_PHONE + "?text=" + encodeURIComponent(message);
  }

  // ---- Google Sheets logging ----
  // Paste your deployed Google Apps Script Web App URL below (see setup guide).
  var SHEET_WEBHOOK_URL = "https://script.google.com/macros/s/AKfycbzNnDl4rKM6YuQ0Bu-0nDZ7-Be9-ndyxOJfBvanxwkfUnwBWn1aj0WuZB7stiRxy5to/exec";

  function logLeadToSheet(data) {
    if (!SHEET_WEBHOOK_URL || SHEET_WEBHOOK_URL.indexOf("PASTE_") === 0) return; // not configured yet
    try {
      fetch(SHEET_WEBHOOK_URL, {
        method: "POST",
        headers: { "Content-Type": "text/plain;charset=utf-8" },
        body: JSON.stringify(data)
      }).catch(function (err) { console.warn("Lead logging failed:", err); });
    } catch (e) { /* fail silently — WhatsApp handoff still works even if logging fails */ }
  }


  function finishPricing() {
    var msg = "Hi Zest team, I'm " + lead.name + " (" + lead.phone + "). I'd like to know more about pricing for an exhibition stall.";
    logLeadToSheet({ timestamp: new Date().toISOString(), type: "Pricing Enquiry", name: lead.name, phone: lead.phone, page: location.pathname });
    addBot("Thanks, " + lead.name + "! Tap below to send this to our team on WhatsApp &mdash; someone from our team will connect with you shortly.", 150);
    setTimeout(function () {
      setQuick([
        { label: "Send to Sales on WhatsApp \u2192", action: function () { window.open(waLink(msg), "_blank"); setTimeout(mainMenu, 400); } }
      ]);
    }, 500);
  }

  /* ---------- Lead qualification ---------- */
  function startLead() {
    state = "lead_service";
    lead = {};
    addBot("Great, happy to help. Which service are you interested in?", 150);
    setTimeout(function () {
      setQuick([
        { label: "Exhibition Stall Design", action: function () { lead.service = "Exhibition Stall Design"; askName(); } },
        { label: "Exhibition Stall Fabrication", action: function () { lead.service = "Exhibition Stall Fabrication"; askName(); } },
        { label: "Commercial Interiors", action: function () { lead.service = "Commercial Interiors"; askNameInterior(); } },
        { label: "Turnkey Solutions", action: function () { lead.service = "Turnkey Solutions"; askName(); } },
        { label: "Not Sure Yet", action: function () { lead.service = "Not Sure Yet"; askName(); } }
      ]);
    }, 300);
  }

  function askName() {
    state = "lead_name";
    addBot("What's your name?", 200);
  }
  function askNameInterior() {
    state = "lead_name_interior";
    addBot("What's your name?", 200);
  }

  function askPhone() {
    state = "lead_phone";
    addBot("Thanks, " + lead.name + "! Best phone number for our team to reach you on?", 150);
  }

  function askCity() {
    state = "lead_city";
    addBot("Which city is this for?", 150);
  }

  function askArea() {
    state = "lead_area";
    addBot("What stall area are you planning? (Length x Breadth, e.g. 6m x 6m)", 150);
  }

  function askSides() {
    state = "lead_sides";
    addBot("How many sides of the stall are open?", 150);
    setTimeout(function () {
      setQuick([
        { label: "1 Side Open", action: function () { lead.sides = "1 side open"; askGoal(); } },
        { label: "2 Sides Open", action: function () { lead.sides = "2 sides open"; askGoal(); } },
        { label: "3 Sides Open", action: function () { lead.sides = "3 sides open"; askGoal(); } },
        { label: "4 Sides / Island", action: function () { lead.sides = "4 sides open (Island)"; askGoal(); } }
      ]);
    }, 300);
  }

  function askGoal() {
    state = "lead_goal";
    addBot("What's your primary goal for this event?", 150);
    setTimeout(function () {
      setQuick([
        { label: "Lead Generation", action: function () { lead.goal = "Lead Generation"; askFeatures(); } },
        { label: "Product Demo", action: function () { lead.goal = "Product Demo"; askFeatures(); } },
        { label: "New Launch", action: function () { lead.goal = "New Launch"; askFeatures(); } },
        { label: "Relationship Building", action: function () { lead.goal = "Relationship Building"; askFeatures(); } },
        { label: "Brand Building", action: function () { lead.goal = "Brand Building"; askFeatures(); } },
        { label: "Other", action: function () { lead.goal = "Other"; askFeatures(); } }
      ]);
    }, 300);
  }

  var FEATURE_OPTIONS = ["Discussion Tables","Meeting Rooms","Storage Rooms","Lounge Area","Reception","Backlit Panels","Hanging Signage","TV Screens","LED Walls","Hospitality Desk","Interactive Displays"];

  function askFeatures() {
    state = "lead_features";
    lead.features = [];
    addBot("What would you like inside your booth? Tap all that apply, then Done.", 150);
    setTimeout(renderFeaturePicker, 300);
  }

  function renderFeaturePicker() {
    quickWrap.innerHTML = "";
    quickWrap.classList.add("chat-quick-persist");
    FEATURE_OPTIONS.forEach(function (f) {
      var b = document.createElement("button");
      b.type = "button";
      b.textContent = f;
      if (lead.features.indexOf(f) !== -1) b.classList.add("selected");
      b.addEventListener("click", function () {
        var i = lead.features.indexOf(f);
        if (i === -1) { lead.features.push(f); b.classList.add("selected"); }
        else { lead.features.splice(i, 1); b.classList.remove("selected"); }
      });
      quickWrap.appendChild(b);
    });
    var done = document.createElement("button");
    done.type = "button";
    done.textContent = "\u2713 Done";
    done.className = "chat-quick-done";
    done.addEventListener("click", function () {
      addUser(lead.features.length ? lead.features.join(", ") : "No specific requirements");
      quickWrap.classList.remove("chat-quick-persist");
      quickWrap.innerHTML = "";
      askBudget();
    });
    quickWrap.appendChild(done);
  }

  function askBudget() {
    state = "lead_budget";
    addBot("Roughly what budget are you working with for this stall? (A ballpark figure is fine)", 200);
  }

  function askAnythingElse() {
    state = "lead_extra";
    addBot("Anything else important we should know before our team gets in touch?", 150);
    setTimeout(function () {
      setQuick([{ label: "Nothing else, that's it", action: function () { lead.extra = "None"; finishLead(); } }]);
    }, 500);
  }

  function askInteriorReq() {
    state = "lead_interior_req";
    addBot("Briefly, what space are you looking to fit out (retail / office / showroom) and roughly what size?", 150);
  }

  function finishLead() {
    state = "idle";
    var featuresStr = (lead.features && lead.features.length) ? lead.features.join(", ") : "None specified";
    var summary = "<b>Here's what I've got:</b><br>Service: " + lead.service +
      "<br>Name: " + lead.name + "<br>Phone: " + lead.phone +
      "<br>City: " + lead.city + "<br>Stall area: " + lead.area +
      "<br>Open sides: " + lead.sides + "<br>Goal: " + lead.goal +
      "<br>Booth features: " + featuresStr +
      "<br>Budget: " + lead.budget +
      "<br>Notes: " + lead.extra;
    addBot(summary, 150);
    var waMsg = "Hi Zest team, I'm " + lead.name + " (" + lead.phone + ") from " + lead.city +
      ". Interested in: " + lead.service + ". Stall area: " + lead.area +
      ", " + lead.sides + ". Goal: " + lead.goal + ". Booth features: " + featuresStr +
      ". Budget: " + lead.budget + ". Notes: " + lead.extra + ".";
    logLeadToSheet({
      timestamp: new Date().toISOString(), type: "Full Enquiry", service: lead.service,
      name: lead.name, phone: lead.phone, city: lead.city, area: lead.area, sides: lead.sides,
      goal: lead.goal, features: featuresStr, budget: lead.budget, notes: lead.extra, page: location.pathname
    });
    setTimeout(function () {
      addBot("Tap below and our team will get this on WhatsApp &mdash; they'll come back with a concept and quote within 48 hours.", 100);
      setQuick([
        { label: "Send to Sales on WhatsApp \u2192", action: function () { window.open(waLink(waMsg), "_blank"); setTimeout(mainMenu, 400); } }
      ]);
    }, 900);
  }

  function finishLeadInterior() {
    state = "idle";
    var summary = "<b>Here's what I've got:</b><br>Service: Commercial Interiors<br>Name: " + lead.name +
      "<br>Phone: " + lead.phone + "<br>City: " + lead.city + "<br>Requirement: " + lead.interiorReq;
    addBot(summary, 150);
    var waMsg = "Hi Zest team, I'm " + lead.name + " (" + lead.phone + ") from " + lead.city +
      ". Interested in Commercial Interiors. Requirement: " + lead.interiorReq + ".";
    logLeadToSheet({
      timestamp: new Date().toISOString(), type: "Full Enquiry", service: "Commercial Interiors",
      name: lead.name, phone: lead.phone, city: lead.city, notes: lead.interiorReq, page: location.pathname
    });
    setTimeout(function () {
      addBot("Tap below and our team will get this on WhatsApp &mdash; they'll follow up shortly.", 100);
      setQuick([
        { label: "Send to Sales on WhatsApp \u2192", action: function () { window.open(waLink(waMsg), "_blank"); setTimeout(mainMenu, 400); } }
      ]);
    }, 900);
  }

  function containsPricingWord(text) {
    var t = text.toLowerCase();
    return PRICING_WORDS.some(function (w) { return t.indexOf(w) !== -1; });
  }

  function looksLikePhone(text) {
    var digits = text.replace(/\D/g, "");
    // Strip a leading country code (91) or trunk 0, if present, to isolate the 10-digit number.
    if (digits.length === 12 && digits.indexOf("91") === 0) digits = digits.slice(2);
    else if (digits.length === 11 && digits.indexOf("0") === 0) digits = digits.slice(1);
    return /^[6-9]\d{9}$/.test(digits);
  }

  /* ---------- Free text router ---------- */
  function handleFreeText(text) {
    var t = text.trim();
    if (!t) return;
    addUser(t);
    clearQuick();

    switch (state) {
      case "pricing_name":
        lead.name = t; state = "pricing_phone";
        addBot("And the best phone number to reach you on?", 250);
        return;
      case "pricing_phone":
        if (!looksLikePhone(t)) { addBot("That doesn't look like a valid 10-digit mobile number &mdash; could you share it again?", 250); return; }
        lead.phone = t; finishPricing();
        return;
      case "lead_name":
        lead.name = t; askPhone(); return;
      case "lead_name_interior":
        lead.name = t; state = "lead_phone_interior";
        addBot("Thanks, " + lead.name + "! Best phone number for our team to reach you on?", 250);
        return;
      case "lead_phone_interior":
        if (!looksLikePhone(t)) { addBot("That doesn't look like a valid 10-digit mobile number &mdash; mind sharing it again?", 250); return; }
        lead.phone = t; state = "lead_city_interior";
        addBot("Which city is this for?", 250);
        return;
      case "lead_city_interior":
        lead.city = t; askInteriorReq(); return;
      case "lead_interior_req":
        lead.interiorReq = t; finishLeadInterior(); return;
      case "lead_phone":
        if (!looksLikePhone(t)) { addBot("That doesn't look like a valid 10-digit mobile number &mdash; could you share it again?", 250); return; }
        lead.phone = t; askCity(); return;
      case "lead_city":
        lead.city = t; askArea(); return;
      case "lead_area":
        lead.area = t; askSides(); return;
      case "lead_budget":
        lead.budget = t; askAnythingElse(); return;
      case "lead_extra":
        lead.extra = t; finishLead(); return;
      default:
        if (containsPricingWord(t)) { startPricing(); return; }
        if (/\b(about|who are you|company)\b/i.test(t)) { showInfo("about"); return; }
        if (/\b(service|services|what do you do)\b/i.test(t)) { showInfo("services"); return; }
        if (/\b(portfolio|work|projects|clients)\b/i.test(t)) { showInfo("portfolio"); return; }
        if (/\b(why|choose|trust)\b/i.test(t)) { showInfo("why"); return; }
        if (/\b(industr)/i.test(t)) { showInfo("industries"); return; }
        if (/\b(policy|policies|terms|privacy)\b/i.test(t)) { showInfo("policies"); return; }
        if (/\b(contact|phone|email|address|office)\b/i.test(t)) { showInfo("contact"); return; }
        if (/\b(quote|stall|fabricat|design my|need a stall)\b/i.test(t)) { startLead(); return; }
        addBot("I'm not 100% sure on that one &mdash; you can pick an option below, or I can connect you straight to our team.", 250);
        setTimeout(function () {
          setQuick([
            { label: "Get a Quote", action: startLead },
            { label: "Talk to Sales", action: startPricing },
            { label: "Main Menu", action: mainMenu }
          ]);
        }, 500);
        return;
    }
  }

  /* ---------- Open / close ---------- */
  function openPanel(isAuto) {
    opened = true;
    toggle.classList.add("open");
    panel.classList.add("open");
    if (!greeted) {
      greeted = true;
      addBot("Thank you for connecting with Zest Exhibitions. How can I assist you today?", 200);
      setTimeout(mainMenu, 900);
    }
    if (!isAuto) input.focus();
  }
  function closePanel() {
    opened = false;
    toggle.classList.remove("open");
    panel.classList.remove("open");
  }

  toggle.addEventListener("click", function () { opened ? closePanel() : openPanel(false); });

  inputRow.addEventListener("submit", function (e) {
    e.preventDefault();
    var val = input.value;
    input.value = "";
    handleFreeText(val);
  });

  // Auto-open once per browser session, shortly after the page loads,
  // so the chat bubble and the WhatsApp bubble read as clearly different things.
  try {
    if (!sessionStorage.getItem("zestChatAutoOpened")) {
      sessionStorage.setItem("zestChatAutoOpened", "1");
      setTimeout(function () { openPanel(true); }, 900);
    }
  } catch (e) { /* sessionStorage unavailable — skip auto-open, widget still works on click */ }

  /* ---------- Static website enquiry forms (Contact page, service pages, etc.) ---------- */
  function initWebsiteForms() {
    var forms = document.querySelectorAll(".website-enquiry-form");
    forms.forEach(function (form) {
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var get = function (n) { var el = form.querySelector("[name='" + n + "']"); return el ? el.value.trim() : ""; };
        var name = get("name");
        var phone = get("phone");
        if (!name || !phone) {
          alert("Please fill in your name and phone number so we can reach you.");
          return;
        }
        if (!looksLikePhone(phone)) {
          alert("Please enter a valid 10-digit mobile number.");
          return;
        }
        var data = {
          timestamp: new Date().toISOString(),
          type: "Website Form Enquiry",
          name: name,
          company: get("company"),
          phone: phone,
          area: get("area"),
          dimensions: get("dimensions"),
          sides: get("sides"),
          exhibition: get("exhibition"),
          notes: get("notes"),
          page: location.pathname
        };
        logLeadToSheet(data);

        var waMsg = "Hi Zest team, I'm " + name + " (" + phone + ")" +
          (data.company ? " from " + data.company : "") + ". " +
          (data.exhibition ? "Exhibition: " + data.exhibition + ". " : "") +
          (data.area ? "Stall area: " + data.area + ". " : "") +
          (data.notes ? "Notes: " + data.notes + "." : "");

        var successBox = form.querySelector(".form-success");
        var grid = form.querySelector(".form-grid");
        var btn = form.querySelector("button[type='submit']");
        var note = form.querySelector(".form-note");
        if (grid) grid.style.display = "none";
        if (btn) btn.style.display = "none";
        if (note) note.style.display = "none";
        if (successBox) {
          successBox.style.display = "block";
          successBox.innerHTML =
            "<p style='margin-bottom:14px;'>Thanks, " + name + " &mdash; we've got your enquiry. Our team will come back to you within 48 hours.</p>" +
            "<a href='" + waLink(waMsg) + "' target='_blank' rel='noopener' class='btn-whatsapp'>Also notify us on WhatsApp \u2192</a>";
        }
      });
    });
  }
  initWebsiteForms();
})();

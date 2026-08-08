# Connecting the Chatbot to Google Sheets — Setup Guide

This is a one-time, ~10 minute setup. No coding needed beyond copy/paste. No hosting cost — Google Apps Script is completely free.

## Why this approach (not the Hostinger server)

You mentioned Zest has shared server space on Hostinger and offered to host a database there. That would work, but it needs someone to build and maintain a PHP script + MySQL database, and the website (on GitHub Pages) would need to call across to a different domain, which adds complexity and a few extra failure points.

Since you specifically asked for a **Google worksheet**, Google Apps Script is the more direct route: it lives inside the Sheet itself, costs nothing, needs no server, and is what most small businesses use for exactly this "form submissions → spreadsheet" pattern. If you'd still prefer the Hostinger route later (e.g. to also power other internal tools), that's a separate, larger build — happy to scope it if wanted.

## Step 1 — Create the Sheet

1. Go to [sheets.google.com](https://sheets.google.com) and create a new blank spreadsheet.
2. Rename it (top-left) to something like **"Zest Exhibitions — Website Leads"**.
3. Leave it otherwise empty — the script creates the right columns automatically on the first lead.

## Step 2 — Add the script

1. In the Sheet, go to **Extensions → Apps Script**.
2. Delete any starter code in the editor (the `function myFunction() {}` placeholder).
3. Open the file **`zest-leads-google-apps-script.gs`** (included in your site zip), copy its entire contents, and paste it into the Apps Script editor.
4. Click the **Save** icon (or Ctrl+S).

## Step 3 — Deploy it as a Web App

1. Click **Deploy → New deployment** (top right).
2. Click the gear icon next to "Select type" → choose **Web app**.
3. Fill in:
   - **Description:** `Zest Leads Webhook`
   - **Execute as:** `Me`
   - **Who has access:** `Anyone`
4. Click **Deploy**.
5. Google will ask you to authorize the script — click **Authorize access**, choose your Google account, and if you see an "unsafe app" warning, click **Advanced → Go to [project name] (unsafe)**. This warning appears because Google can't automatically verify small personal scripts — it's expected and safe since it's your own script.
6. Once deployed, copy the **Web app URL** shown (it ends in `/exec`). Keep this safe.

## Step 4 — Connect it to the website

1. Open `chatbot.js` (in your site files).
2. Find this line near the top:
   ```js
   var SHEET_WEBHOOK_URL = "PASTE_YOUR_GOOGLE_APPS_SCRIPT_URL_HERE";
   ```
3. Replace the placeholder text with the URL you copied in Step 3, so it looks like:
   ```js
   var SHEET_WEBHOOK_URL = "https://script.google.com/macros/s/AKfycb.../exec";
   ```
4. Save the file and re-upload it to your GitHub repo (same drag-and-drop process as before — it'll overwrite the existing `chatbot.js`).

## Step 5 — Test it

1. Open the live site, click the chat bubble, and go through a full "Get a Quote" flow with test details.
2. Go back to your Google Sheet — a new row should appear within a couple of seconds, dated and timed automatically (India time).
3. If nothing shows up: re-check the URL was pasted correctly with no extra spaces, and that "Who has access" was set to **Anyone** in Step 3.

## What gets logged

Every lead — both full stall enquiries and pricing-deflection contacts — is logged with: date, time, enquiry type, service, name, phone, city, stall area, open sides, goal, booth features, budget, notes, and which page they were on. New leads always append as new rows, so the sheet builds a running, date-ordered log automatically.

## Note on reliability

The website sends this data quietly in the background — the visitor never sees it and it never blocks or slows down their chat experience. If the Sheet is ever unreachable (rare), the WhatsApp handoff still works independently, so no lead is ever lost outright — worst case, it only reaches your team via WhatsApp and not the Sheet that one time.

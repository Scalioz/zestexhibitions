/**
 * ZEST EXHIBITIONS — Website Lead Logger + Sales Notifier
 * ---------------------------------------------------------
 * Receives lead data from BOTH the chatbot and the static website
 * enquiry forms, logs every one as a dated row in this Sheet, and
 * instantly emails the sales team so no lead depends on someone
 * remembering to check the spreadsheet.
 *
 * SETUP: see chatbot-google-sheets-setup.md for full step-by-step instructions.
 */

// ↓↓↓ CHANGE THIS to the email address that should get instant lead alerts.
// You can list more than one, comma-separated, e.g. "sales@zestexhibitions.com,owner@zestexhibitions.com"
var SALES_EMAIL = "info@zestexhibitions.com";

function doPost(e) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("Leads");
  if (!sheet) {
    sheet = SpreadsheetApp.getActiveSpreadsheet().insertSheet("Leads");
  }

  var headers = [
    "Date", "Time", "Enquiry Type", "Service", "Name", "Company", "Phone",
    "City", "Stall Area", "Dimensions", "Open Sides", "Exhibition / Event",
    "Goal", "Booth Features", "Budget", "Notes", "Page URL"
  ];

  if (sheet.getLastRow() === 0) {
    sheet.appendRow(headers);
    sheet.setFrozenRows(1);
  }

  var data = {};
  try {
    data = JSON.parse(e.postData.contents);
  } catch (err) {
    data = {};
  }

  var now = data.timestamp ? new Date(data.timestamp) : new Date();

  var row = [
    Utilities.formatDate(now, "Asia/Kolkata", "dd-MM-yyyy"),
    Utilities.formatDate(now, "Asia/Kolkata", "HH:mm:ss"),
    data.type || "",
    data.service || "",
    data.name || "",
    data.company || "",
    data.phone || "",
    data.city || "",
    data.area || "",
    data.dimensions || "",
    data.sides || "",
    data.exhibition || "",
    data.goal || "",
    data.features || "",
    data.budget || "",
    data.notes || "",
    data.page || ""
  ];

  sheet.appendRow(row);

  notifySalesByEmail(headers, row, data);

  return ContentService
    .createTextOutput(JSON.stringify({ status: "success" }))
    .setMimeType(ContentService.MimeType.JSON);
}

function notifySalesByEmail(headers, row, data) {
  if (!SALES_EMAIL) return;
  try {
    var subject = "New Website Lead: " + (data.name || "Unnamed") + " — " + (data.type || "Enquiry");
    var lines = [];
    for (var i = 0; i < headers.length; i++) {
      if (row[i]) lines.push(headers[i] + ": " + row[i]);
    }
    var body =
      "A new lead just came in from the Zest Exhibitions website.\n\n" +
      lines.join("\n") +
      "\n\nFull sheet: " + SpreadsheetApp.getActiveSpreadsheet().getUrl();

    MailApp.sendEmail({
      to: SALES_EMAIL,
      subject: subject,
      body: body
    });
  } catch (err) {
    // If email fails for any reason, the lead is still safely logged in the Sheet above.
  }
}

// Lets you open the deployed URL directly in a browser to confirm it's alive.
function doGet(e) {
  return ContentService.createTextOutput("Zest Exhibitions lead webhook is live and ready.");
}

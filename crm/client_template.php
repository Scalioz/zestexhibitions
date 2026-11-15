<?php
// Sales / admin: blank Excel template for importing clients (with an example row and instructions)
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/xlsx.php';
$me = current_user();
if (!$me) { header('Location: login.php'); exit; }
$S = [
  'Clients' => [
    ['Company name', 'Contact person', 'Designation', 'Phone', 'Email', 'City', 'Address', 'GSTIN', 'Notes', 'Expo name', 'Expo city', 'Expo start date', 'Area (sqm)', 'Stall no', 'Space type', 'Sides open'],
    ['Example Glass Pvt Ltd', 'Rahul Mehta', 'Marketing Manager', '9876543210', 'rahul@exampleglass.com', 'Delhi', 'Plot 12, Okhla Phase 2, New Delhi', '07ABCDE1234F1Z5', 'Met at Zak Expo', 'Zak Glass Technology Expo 2026', 'Dwarka, Delhi', '10-12-2026', '36', 'H2-B14', 'Bare space', '2'],
  ],
  'How to fill' => [
    ['Column', 'Required?', 'How to fill'],
    ['Company name', 'Yes', 'One client per row. Delete the example row before importing.'],
    ['Contact person, Designation, Phone, Email, City, Address, GSTIN, Notes', 'No', 'Phone: 10-digit mobile (country code if outside India). Email: one address.'],
    ['Expo name', 'No', 'Fill only if the client is enquiring for an expo — this also creates their first expo order.'],
    ['Expo city, Expo start date, Area (sqm), Stall no', 'No', 'Date as DD-MM-YYYY (e.g. 10-12-2026). Area as a number.'],
    ['Space type', 'No', 'Bare space  or  Shell scheme'],
    ['Sides open', 'No', '1, 2, 3 or 4'],
    ['Duplicates', '', 'A client already in the CRM (same company name, phone or email) is skipped and listed after the import.'],
    ['Column names', '', 'Your own sheet works too — the CRM matches the columns by their names and lets you correct them before importing.'],
  ],
];
$bin = xlsx_build($S);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Zest-client-import-template.xlsx"');
header('Content-Length: ' . strlen($bin));
echo $bin;

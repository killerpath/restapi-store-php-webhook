restapi.store — PHP Webhook for Jira Epic Rollup
=================================================

Endpoints (after upload):
- GET  /public/healthz
- GET  /public/debug/rollup?epic=PG-221
- POST /public/jira-hook   (Jira Automation -> Send web request)

Install
-------
1) Upload all files to your desired folder (e.g., public_html/public/).
2) Edit .env.php:
   - JIRA_API_TOKEN = your Atlassian token
   - WEBHOOK_SECRET = a long random string
   - (optional) TOTAL_SUB_TICKETS_FIELD_ID = customfield_XXXXX (to skip name lookup)
3) Make sure the "Total Sub Tickets" field is on the Epic Edit screen in Jira.

Test
----
- https://restapi.store/public/healthz
- https://restapi.store/public/debug/rollup?epic=PG-221

Wire Jira Automation
--------------------
Send web request:
- URL: https://restapi.store/public/jira-hook
- Method: POST
- Headers:
  Content-Type: application/json
  X-Auth-Secret: <same as WEBHOOK_SECRET>
- Body (create/field-changed):
  { "project":"PG", "issueKey":"{{issue.key}}" }
- Body (deleted):
  {
    "project":"PG",
    "issueKey":"{{issue.key}}",
    "epicKey":"{{issue.epic.key}}",
    "parentKey":"{{issue.parent.key}}",
    "parentEpicKey":"{{issue.parent.epic.key}}"
  }

Notes
-----
- If your host disables mod_rewrite, you can call the scripts directly:
  /public/healthz.php, /public/jira_hook.php, /public/debug_rollup.php
- /debug/rollup returns {editable_on_epic:true/false}. If false, add the field to the Epic Edit screen or fix field context.

<?php
require_once __DIR__ . '/lib.php';

// Verify secret
$hdrs = function_exists('getallheaders') ? getallheaders() : [];
$secret = $hdrs['X-Auth-Secret'] ?? $hdrs['x-auth-secret'] ?? '';
if (WEBHOOK_SECRET !== '' && $secret !== WEBHOOK_SECRET) {
    json_response(['ok'=>false, 'error'=>'forbidden: bad secret'], 403);
}

// Parse JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$issue_key = trim($data['issueKey'] ?? '');
$hint_epic = trim($data['epicKey'] ?? ($data['parentEpicKey'] ?? ''));

$epic_key = $hint_epic ?: ($issue_key ? find_epic_key_for_issue($issue_key) : null);
if (!$epic_key) {
    json_response(['ok'=>true, 'skipped'=>'no-epic-found', 'issue'=>$issue_key], 200);
}

// Optional project guard
$epic = get_issue($epic_key, ['project']);
if ($epic) {
    $proj = $epic['fields']['project']['key'] ?? null;
    if ($proj && $proj !== PROJECT_KEY) {
        json_response(['ok'=>true, 'skipped'=>"epic-not-in-project-" . PROJECT_KEY, 'epic'=>$epic_key], 200);
    }
}

$total = count_children_under_epic($epic_key);
$field_id = TOTAL_SUB_TICKETS_FIELD_ID !== '' ? TOTAL_SUB_TICKETS_FIELD_ID : get_field_id_by_name(TOTAL_SUB_TICKETS_FIELD_NAME);
if (!$field_id) {
    json_response(['ok'=>false, 'error'=>"Could not resolve field id for '" . TOTAL_SUB_TICKETS_FIELD_NAME . "'"], 500);
}

$res = update_issue_fields($epic_key, [$field_id => $total]);
if (($res['status'] ?? 0) >= 400) {
    $body = $res['body'] ?? ['raw'=>$res['raw'] ?? ''];
    json_response(['ok'=>false, 'error'=>'Jira update failed', 'jira'=>$body], $res['status']);
}

json_response(['ok'=>true, 'epic'=>$epic_key, 'total_children'=>$total, 'field'=>$field_id], 200);
?>

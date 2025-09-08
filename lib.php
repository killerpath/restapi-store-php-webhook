<?php
// lib.php — shared helpers
require_once __DIR__ . '/.env.php';

function json_response($data, $status=200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function jira_request($method, $path, $params = [], $body = null) {
    $url = rtrim(JIRA_SITE, '/') . $path;
    if ($params) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_USERPWD, JIRA_EMAIL . ':' . JIRA_API_TOKEN);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    if (!is_null($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    // simple retry on 429
    for ($i=0; $i<3; $i++) {
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status != 429) break;
        sleep(5);
    }
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status'=>0, 'error'=>$err, 'body'=>null];
    }
    curl_close($ch);
    $decoded = json_decode($resp, true);
    return ['status'=>$status, 'body'=>$decoded, 'raw'=>$resp];
}

function get_field_id_by_name($name) {
    $override = trim(constant('TOTAL_SUB_TICKETS_FIELD_ID'));
    if ($override !== '') return $override;

    $res = jira_request('GET', '/rest/api/3/field');
    if (($res['status'] ?? 0) !== 200) return null;
    $key = strtolower(trim($name));
    foreach ($res['body'] as $f) {
        $fname = strtolower(trim($f['name'] ?? ''));
        $fid = $f['id'] ?? null;
        if ($fname === $key && $fid) return $fid;
    }
    return null;
}

function get_issue($key, $fields = null) {
    $params = [];
    if ($fields) $params['fields'] = is_array($fields) ? implode(',', $fields) : $fields;
    $res = jira_request('GET', '/rest/api/3/issue/' . urlencode($key), $params);
    if (($res['status'] ?? 0) === 404) return null;
    if (($res['status'] ?? 0) >= 400) return null;
    return $res['body'];
}

function search_issues($jql, $fields = null, $max_per_page = 100) {
    $out = [];
    $startAt = 0;
    while (true) {
        $params = ['jql' => $jql, 'maxResults' => $max_per_page, 'startAt' => $startAt];
        if ($fields) $params['fields'] = is_array($fields) ? implode(',', $fields) : $fields;
        $res = jira_request('GET', '/rest/api/3/search', $params);
        if (($res['status'] ?? 0) >= 400) break;
        $data = $res['body'];
        $issues = $data['issues'] ?? [];
        $out = array_merge($out, $issues);
        $startAt += count($issues);
        if ($startAt >= intval($data['total'] ?? 0)) break;
    }
    return $out;
}

function update_issue_fields($issue_key, $fields_assoc) {
    $payload = ['fields' => $fields_assoc];
    $res = jira_request('PUT', '/rest/api/3/issue/' . urlencode($issue_key), [], $payload);
    return $res;
}

function find_epic_key_for_issue($issue_key) {
    $issue = get_issue($issue_key, ['issuetype','parent','project']);
    if (!$issue) return null;
    $f = $issue['fields'] ?? [];
    $itype = ($f['issuetype']['name'] ?? '');
    $is_subtask = !!($f['issuetype']['subtask'] ?? false);

    if (strtolower($itype) === 'epic') return $issue['key'];

    $parent = $f['parent'] ?? null;
    $parent_key = $parent['key'] ?? null;
    $parent_type = strtolower($parent['fields']['issuetype']['name'] ?? '');
    if ($parent_key && $parent_type === 'epic') return $parent_key;

    // Company-managed: "Epic Link"
    $epic_link_id = get_field_id_by_name('Epic Link');
    if ($epic_link_id) {
        $iss2 = get_issue($issue_key, [$epic_link_id]);
        if ($iss2) {
            $val = $iss2['fields'][$epic_link_id] ?? null;
            if (is_array($val)) return ($val['key'] ?? $val['id'] ?? null);
            if (is_string($val) && $val !== '') return $val;
        }
    }
    if ($is_subtask && $parent_key) {
        return find_epic_key_for_issue($parent_key);
    }
    return null;
}

function count_children_under_epic($epic_key) {
    $allowed = array_map('strtolower', array_map('trim', explode(',', constant('ALLOWED_CHILD_TYPES'))));
    $count_subs = constant('COUNT_SUBTASKS') ? true : false;

    $jql = sprintf('project = "%s" AND ( "Epic Link" = "%s" OR parent = "%s" )',
        PROJECT_KEY, $epic_key, $epic_key);
    $direct_children = search_issues($jql, ['issuetype','subtasks']);

    $direct = 0; $subtasks = 0;
    foreach ($direct_children as $it) {
        $f = $it['fields'] ?? [];
        $itype = strtolower(trim($f['issuetype']['name'] ?? ''));
        $is_subtask = !!($f['issuetype']['subtask'] ?? false);
        if ($is_subtask) continue;
        if (empty($allowed) || in_array($itype, $allowed)) {
            $direct += 1;
            if ($count_subs) {
                $subs = $f['subtasks'] ?? [];
                $subtasks += is_array($subs) ? count($subs) : 0;
            }
        }
    }
    return $direct + $subtasks;
}

function epic_editable_has_field($epic_key, $field_id) {
    $res = jira_request('GET', '/rest/api/3/issue/' . urlencode($epic_key) . '/editmeta');
    if (($res['status'] ?? 0) !== 200) return null;
    $fields = $res['body']['fields'] ?? [];
    return isset($fields[$field_id]);
}
?>

<?php
require_once __DIR__ . '/lib.php';

$epic = isset($_GET['epic']) ? trim($_GET['epic']) : '';
if ($epic === '') json_response(['error' => 'pass ?epic=PG-221'], 400);

$total = count_children_under_epic($epic);
$field_id = TOTAL_SUB_TICKETS_FIELD_ID !== '' ? TOTAL_SUB_TICKETS_FIELD_ID : get_field_id_by_name(TOTAL_SUB_TICKETS_FIELD_NAME);
$editable = $field_id ? epic_editable_has_field($epic, $field_id) : null;

json_response([
  'epic' => $epic,
  'total_children' => $total,
  'field_id' => $field_id,
  'editable_on_epic' => $editable
]);
?>

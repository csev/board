<?php
// https://github.com/csev/board
require_once "../config.php";

use \Tsugi\Util\U;
use \Tsugi\Util\LTI13;
use \Tsugi\Core\LTIX;
use \Tsugi\UI\Output;

function get_health() {
    global $LTI, $OUTPUT, $CFG, $PDOX, $users, $row_count;

// Handle all forms of launch
$LTI = LTIX::requireData();
$p = $CFG->dbprefix;

$context_id = $LTI->context->id;


// Clean up the cache - No course lasts more than 10 minutes
$sql = "DELETE FROM {$p}board_cache WHERE `created_at` < ADDDATE(NOW(), INTERVAL -10 MINUTE)";
$PDOX->queryDie($sql);

$sql =
    "SELECT row_count, health, users FROM {$p}board_cache
     WHERE context_id = :CID";

$arr = array("CID" => $context_id);

$row = $PDOX->rowDie($sql, $arr);
if ( is_array($row) ) {
    $row_count = $row["row_count"];
    $health = unserialize($row["health"]);
    $users = unserialize($row["users"]);
    error_log("Board retrieved from cache...");
    return $health;
}    

// TODO: Make this in the last 90 days and order by the link created_at desc
// TODO: Some kind of limit for number of records - some kind of latest nnn links
// TODO: Some kind of "too few" so we won't show you anything logic

$sql =
    "SELECT L.link_id AS link_id, R.user_id AS user_id,
      R.attempts AS attempts, R.attempted_at AS attempted_at,
      R.grade AS grade, R.created_at AS created_at, R.updated_at AS updated_at,
      U.displayname AS displayname, U.email AS email
    FROM {$p}lti_link AS L
    JOIN {$p}lti_result AS R
        ON L.link_id =  R.link_id
    JOIN {$p}lti_user AS U
        ON R.user_id =  U.user_id
    WHERE L.context_id = :CID
    ORDER BY link_id, user_id, created_at
    LIMIT 10000";

$sqlold =
    "SELECT L.link_id AS link_id, R.user_id AS user_id,
      0 AS attempts, 0 AS attempted_at,
      R.grade AS grade, R.created_at AS created_at, R.updated_at AS updated_at,
      U.displayname AS displayname, U.email AS email
    FROM {$p}lti_link AS L
    JOIN {$p}lti_result AS R
        ON L.link_id =  R.link_id
    JOIN {$p}lti_user AS U
        ON R.user_id =  U.user_id
    WHERE L.context_id = :CID
    ORDER BY link_id, user_id, created_at
    LIMIT 10000";
// echo("<pre>\n");echo($sql);echo("</pre>\n");

$stmt = $PDOX->queryReturnError($sql, $arr);
if ( ! $stmt->success ) {
    $stmt = $PDOX->queryDie($sqlold, $arr);
}
$rows = array();
$users = array();

while ( $row = $stmt->fetch(\PDO::FETCH_ASSOC) ) {
    $user_id = $row['user_id'];
    if ( ! array_key_exists($user_id, $users) ) $users[$user_id] = array($row['displayname'], $row['email']);
    unset($row['displayname']);
    unset($row['email']);
    array_push($rows, array_merge($row));
}

$row_count = count($rows);
$stmt->closeCursor();

// Compute ranges
$links = array();
$ranges = array();

$curr_link_id = -1;
$create_min = null;
$create_max = null;
$update_min = null;
$update_max = null;
$attempted_at_min = null;
$attempted_at_max = null;
$attempts_min = null;
$attempts_max = null;
foreach($rows as $row ) {
    if ( $curr_link_id != $row['link_id'] ) {
        if ( $curr_link_id != -1 ) {
            $ranges[$curr_link_id] = array($create_min, $create_max, $update_min, $update_max, $attempted_at_min, $attempted_at_max, $attempts_min, $attempts_max);
        }
        $curr_link_id = $row['link_id'];
        $create_min = null;
        $create_max = null;
        $update_min = null;
        $update_max = null;
        $attempted_at_min = null;
        $attempted_at_max = null;
        $attempts_min = null;
        $attempts_max = null;
    }
    if ( $create_max == null || $row['created_at'] > $create_max ) $create_max = $row['created_at'];
    if ( $create_min == null || $row['created_at'] < $create_min ) $create_min = $row['created_at'];
    if ( $update_max == null || $row['updated_at'] > $update_max ) $update_max = $row['updated_at'];
    if ( $update_min == null || $row['updated_at'] < $update_min ) $update_min = $row['updated_at'];
    if ( $attempted_at_max == null || $row['attempted_at'] > $attempted_at_max ) $attempted_at_max = $row['attempted_at'];
    if ( $attempted_at_min == null || $row['attempted_at'] < $attempted_at_min ) $attempted_at_min = $row['attempted_at'];
    if ( $attempts_max == null || $row['attempts'] > $attempts_max ) $attempts_max = $row['attempts'];
    if ( $attempts_min == null || $row['attempts'] < $attempts_min ) $attempts_min = $row['attempts'];
}

if ( $curr_link_id != -1 ) {
    $ranges[$curr_link_id] = array($create_min, $create_max, $update_min, $update_max, $attempted_at_min, $attempted_at_max, $attempts_min, $attempts_max);
}

// Now we have each link, its title, and the ranges of create, update, attempted_at, and attempts
// echo("<pre>\n");var_dump($links);echo("</pre>\n");
// echo("<pre>\n");var_dump($ranges);echo("</pre>\n");

// Compute health
function computeHealthContribution($user_value, $min, $max, &$total, &$count) {
    if ( $user_value == null || $min == null || $max == null ) return;
    if ( is_string($user_value) ) $user_value = strtotime($user_value);
    if ( is_string($min) ) $min = strtotime($max);
    if ( is_string($max) ) $max = strtotime($max);
    if ( ! is_int($user_value) || ! is_int($min) || ! is_int($max) ) return;
    if ( $max == 0 || $min == 0 || $user_value == 0 ) return;

    // First two hours - are a gimme
    if ( $user_value < ($min + 7200)) {
        $total = $total + 1;
        $count = $count + 1;
    }

    // Cant divide by a range of zero - maybe only one result..
    if ( ($max - $min) <= 0.0 ) return;

    $pos = abs($max - $user_value) / abs($max - $min);;

    // Scale 1.0 - 0.0 up to 1.0 - 0.5 (i.e. you get no lower than 0.5 for finishing)
    $pos = ($pos / 2.0) + 0.5;

    $total = $total + $pos;
    $count = $count + 1;
}

$health_detail = array();
$health = array();
foreach($rows as $row ) {
    $user_id = $row['user_id'];
    if ( ! array_key_exists($user_id, $health) ) $health[$user_id] = 0;
    $link_id = $row['link_id'];
    $create_min = $ranges[$link_id][0];
    $create_max = $ranges[$link_id][1];
    $update_min = $ranges[$link_id][2];
    $update_max = $ranges[$link_id][3];
    $attempted_at_min = $ranges[$link_id][4];
    $attempted_at_max = $ranges[$link_id][5];
    $attempts_min = $ranges[$link_id][6];
    $attempts_max = $ranges[$link_id][7];

    $created_at = $row['created_at'];
    $updated_at = $row['updated_at'];
    $attempted_at = $row['attempted_at'];
    $attempts = $row['attempts'];

    $total = 0;
    $count = 0;
    computeHealthContribution($created_at, $create_min, $create_max, $total, $count);
    computeHealthContribution($updated_at, $update_min, $update_max, $total, $count);
    computeHealthContribution($attempted_at, $attempted_at_min, $attempted_at_max, $total, $count);
    computeHealthContribution($attempts, $attempts_min, $attempts_max, $total, $count);

    $relative = $total / $count;
    // echo("<pre>\n");echo("$user_id, $link_id, $total, $count $relative");echo("</pre>\n");

    array_push($health_detail, array($user_id, $link_id, $relative) ) ;

    $health[$user_id] = $health[$user_id] + $relative;
}

// Store for later

$sql =
    "INSERT INTO {$p}board_cache (context_id, row_count, health, users, created_at)
    VALUES(:CID, :ROWS, :HEALTH, :USERS, NOW())
    ON DUPLICATE KEY UPDATE
    row_count=:ROWS, health=:HEALTH, users=:USERS, created_at=NOW();";

$arr = array(
    "CID" => $context_id,
    "ROWS" => $row_count,
    "HEALTH" => serialize($health),
    "USERS" => serialize($users)
);
error_log("Board stored in cache...");
    var_dump($health);
    var_dump($users);

$PDOX->queryDie($sql, $arr);

return $health;
}

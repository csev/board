<?php
// https://github.com/csev/board
require_once "../config.php";

use \Tsugi\Util\U;
use \Tsugi\Util\LTI13;
use \Tsugi\Core\LTIX;
use \Tsugi\UI\Output;

// Handle all forms of launch
$LTI = LTIX::requireData();
$p = $CFG->dbprefix;

// Render view
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav();

$OUTPUT->welcomeUserCourse();

$context_id = $LTI->context->id;
echo("Context $context_id \n");

$sql =
    "SELECT L.link_id AS link_id, L.title AS link_title, R.user_id AS user_id,
      R.grade AS grade, R.created_at AS created_at, R.updated_at AS updated_at,
      R.json AS json
    FROM {$p}lti_link AS L
    JOIN {$p}lti_result AS R
        ON L.link_id =  R.link_id
    WHERE L.context_id = :CID
    ORDER BY link_id, user_id, created_at";
echo("<pre>\n");echo($sql);echo("</pre>\n");

$arr = array("CID" => $context_id);

$stmt = $PDOX->queryDie($sql, $arr);
$rows = array();
while ( $row = $stmt->fetch(\PDO::FETCH_ASSOC) ) {
    $row['tries'] = null;
    $row['when'] = null;
    if ( is_string($row['json']) ) {
        try {
            $js = json_decode($row['json'], true);
            $row['tries'] = $js['tries'] ?? null;
            if ( is_int($js['when']) ) {
                // $row['when'] = date("Y-m-d H:i:s",$js['when']);
                $row['when'] = $PDOX->timeToMySqlTimeStamp($js['when']);
            } else {
                $row['when'] = $js['when'] ?? null;
            }
        } catch (\Exception $e) {
            // pass
        }
    }
    unset($row['json']);
    array_push($rows, $row);
}
$stmt->closeCursor();

// Compute ranges
$links = array();
$ranges = array();

$curr_link_id = -1;
$create_min = null;
$create_max = null;
$update_min = null;
$update_max = null;
$when_min = null;
$when_max = null;
$tries_min = null;
$tries_max = null;
foreach($rows as $row ) {
    if ( $curr_link_id != $row['link_id'] ) {
        $links[$row['link_id']] = $row['link_title'];
        if ( $curr_link_id != -1 ) {
            $ranges[$curr_link_id] = array($create_min, $create_max, $update_min, $update_max, $when_min, $when_max, $tries_min, $tries_max);
        }
        $curr_link_id = $row['link_id'];
        $create_min = null;
        $create_max = null;
        $update_min = null;
        $update_max = null;
        $when_min = null;
        $when_max = null;
        $tries_min = null;
        $tries_max = null;
    }
    if ( $create_max == null || $row['created_at'] > $create_max ) $create_max = $row['created_at'];
    if ( $create_min == null || $row['created_at'] < $create_min ) $create_min = $row['created_at'];
    if ( $update_max == null || $row['updated_at'] > $update_max ) $update_max = $row['updated_at'];
    if ( $update_min == null || $row['updated_at'] < $update_min ) $update_min = $row['updated_at'];
    if ( $when_max == null || $row['when'] > $when_max ) $when_max = $row['when'];
    if ( $when_min == null || $row['when'] < $when_min ) $when_min = $row['when'];
    if ( $tries_max == null || $row['tries'] > $tries_max ) $tries_max = $row['tries'];
    if ( $tries_min == null || $row['tries'] < $tries_min ) $tries_min = $row['tries'];
}

if ( $curr_link_id != -1 ) {
    $ranges[$curr_link_id] = array($create_min, $create_max, $update_min, $update_max, $when_min, $when_max, $tries_min, $tries_max);
}

// Now we have each link, its title, and the ranges of create, update, when, and tries
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
    $when_min = $ranges[$link_id][4];
    $when_max = $ranges[$link_id][5];
    $tries_min = $ranges[$link_id][6];
    $tries_max = $ranges[$link_id][7];

    $created_at = $row['created_at'];
    $updated_at = $row['updated_at'];
    $when = $row['when'];
    $tries = $row['tries'];

    $total = 0;
    $count = 0;
    computeHealthContribution($created_at, $create_min, $create_max, $total, $count);
    computeHealthContribution($updated_at, $update_min, $update_max, $total, $count);
    computeHealthContribution($when, $when_min, $when_max, $total, $count);
    computeHealthContribution($tries, $tries_min, $tries_max, $total, $count);

    $relative = $total / $count;
    // echo("<pre>\n");echo("$user_id, $link_id, $total, $count $relative");echo("</pre>\n");

    array_push($health_detail, array($user_id, $link_id, $relative) ) ;

    $health[$user_id] = $health[$user_id] + $relative;
}

echo("<pre>\n");var_dump($health);echo("</pre>\n");
echo("<pre>\n");var_dump($health_detail);echo("</pre>\n");

if ( $LTI->user->instructor ) {
    $OUTPUT->footer();
    return;
}

$OUTPUT->footerStart();
$OUTPUT->footerEnd();

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
?>
<style>
th, td {
  padding-top: 5px;
  padding-bottom: 5px;
  padding-left: 5px;
  padding-right: 5px;
}
</style>
<?php
$OUTPUT->bodyStart();
$OUTPUT->topNav();

$OUTPUT->welcomeUserCourse();

/*
if ( ! $LTI->user->instructor ) {
    echo("<p>This tool is in test mode - check back later</p>.");
    $OUTPUT->footer();
    return;
}
 */

$context_id = $LTI->context->id;

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

$arr = array("CID" => $context_id);

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

$count = count($health);

if ( (! $LTI->user->instructor) && $count < 3 ) {
    echo("<p>We are still gathering data, please check back later...</p>");
    $OUTPUT->footer();
    return;
}

if ( $count < 1 ) {
    echo("<p>No data...</p>");
    $OUTPUT->footer();
    return;
}

$total = 0;
$rank = 0;
$median = 0;
foreach($health as $user_id => $value ) {
    $rank = $rank + 1;
    if ( $median == 0 && $rank >= $count/2 ) $median = $value;
    $total = $total + $value;
}
$average = $total / $count;

arsort($health);

$notes = array();
$rank = 0;
foreach($health as $user_id => $value ) {
    $rank = $rank + 1;
    $note = "Below Average";
    if ( $rank < ($count/10) ) {
	    $note = "Top 10 %";
    } else if ( $value > $average || $value > $median ) {
	    $note = "Above average";
    } else if ( $rank < ($count*0.90) ) {
	    $note = "Keeping up";
    }
    $notes[$user_id] = $note;
}

if ( ! $LTI->user->instructor ) {
    $current_user_id = $LTI->user->id;
    $health = $health[$current_user_id] ?? 'Not computed';
    $note = $notes[$current_user_id] ?? 'Not computed';
    echo("<p><b>Status:</b> $note</p>\n");
    echo("<p><b>Health points:</b> $health</p>\n");
   ?>
<p>This is a rough approximation of you well you are doing in the homework relative to the rest of the class.
It looks at how long it takes for you to start your home work, how long it takes for you to complete your
homework, and how many tries it takes for you to finish your homework relative to the other students in the class.
If this were a game, it would be an indication of how "healthy" you are while taking the course.
</p>
<p>
The value you see in this tool does not enter into your grade computation in any way. You can earn full points in assignments
even if you finish them one minute before the due date or it takes many tries for each assignment.  The teaching staff might
use this data to see which students might be struggling or might need extra help. You cannot see the data of any other student
but you can check with another student and compare your health numbers as long as you check at the same time.
</p>
<p>
Other than doing your homework earlier and finishing it more quickly, there is not much you can do to alter this number.  Each
time any student does any homework, this number will change.  As more assignments are done, the health number just goes up.  The status
is computed by comparing your "health" points in the course with the other students health points at this moment in the course.
</p>
<p>
This whole idea is an <b>experiment</b> to see if (like in video games) we can measure "health" in addition to "gold" or "score".
We hope this helps you learn better and helps us teach better.  Feel free to give feedback on this tool to the course staff.
</p>
<?php
    $OUTPUT->footer();
    return;
}

// Double check.

if ( ! $LTI->user->instructor ) return;
echo("<p>SQL Rows: ".count($rows)."</p>\n");

echo("<p>Average: $average Median: $median</p>\n");

echo('<table border="2">'."\n");
$rank = 0;
foreach($health as $user_id => $value ) {
    $rank = $rank + 1;
    $name = "*** name hidden during test ***";
    $name = $users[$user_id][0].' '.$users[$user_id][1];
    echo("<tr><td>".$name);
    echo("</td><td>".$value);
    echo("</td><td>".$rank."/".$count);
    echo("</td><td>".$notes[$user_id]);
    echo("</td></tr>");
}
echo("</table>\n");

// echo("<pre>\n");var_dump($health);echo("</pre>\n");
// echo("<pre>\n");var_dump($health_detail);echo("</pre>\n");

$OUTPUT->footerStart();
$OUTPUT->footerEnd();

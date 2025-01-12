<?php
// https://github.com/csev/board
require_once "../config.php";
require_once "health.php";

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

$health = get_health();

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
echo("<p>SQL Rows: ".$row_count."</p>\n");

echo("<p>Average: $average Median: $median</p>\n");

echo("<button onclick=\"$('.noname').toggle();$('.showname').toggle();\">Show/Hide</button>");
echo('<table border="2">'."\n");
$rank = 0;
foreach($health as $user_id => $value ) {
    $rank = $rank + 1;
    $name = "*** name hidden during test ***";
    $name = $users[$user_id][0].' '.$users[$user_id][1];
    echo("<tr><td>");
    echo('<span class="noname">*** Name Hidden ****</span><span class="showname" style="display:none;">');
    echo(htmlentities($name));
    echo('</span>');
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

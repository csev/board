<?php

$REGISTER_LTI = array(
"name" => "Leader Board",
"FontAwesome" => "fa-award",
"short_name" => "Board",
"description" => "This tool approximated how punctual students are relative to their peers in the class.",
    "messages" => array("launch"),
    "privacy_level" => "name_only",  // anonymous, name_only, public
    "license" => "Apache",
    "languages" => array(
        "English"
    ),
    "analytics" => array(
        "internal"
    ),
    "tool_phase" => "fun",
    "source_url" => "https://github.com/csev/board",
    // For now Tsugi tools delegate this to /lti/store
    "placements" => array(
        /*
        "course_navigation", "homework_submission",
        "course_home_submission", "editor_button",
        "link_selection", "migration_selection", "resource_selection",
        "tool_configuration", "user_navigation"
        */
    ),
    "screen_shots" => array(
/*
        "store/screen-01.png",
        "store/screen-02.png",
        "store/screen-03.png",
        "store/screen-views.png",
        "store/screen-analytics.png"
*/
    )
);

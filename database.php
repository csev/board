<?php

// The SQL to uninstall this tool
$DATABASE_UNINSTALL = array(
  "drop table if exists {$CFG->dbprefix}board_cache;",
);

// The SQL to create the necessary tables if they don't exist

$DATABASE_INSTALL = array(

  array( "{$CFG->dbprefix}board_cache",
  "CREATE TABLE {$CFG->dbprefix}board_cache (
    context_id          INTEGER NOT NULL,
    row_count           INTEGER NULL,
    health              MEDIUMTEXT NULL,
    users               MEDIUMTEXT NULL,

    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT '1970-01-02 00:00:00',

    CONSTRAINT {$CFG->dbprefix}board_cache_ibfk_1
        FOREIGN KEY (context_id)
        REFERENCES {$CFG->dbprefix}lti_context (context_id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    UNIQUE(context_id)

  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
  "),

);


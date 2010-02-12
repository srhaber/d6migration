<?php

/**
 * @file
 * All post-import processing code goes here.
 */
 
include 'includes/custom.inc';

/**
 * Default function to process imported content.
 *
 * These samples were taken from the veronicas6 migration from Drupal 5 to 6.  It may be
 * helpful to break some of these into smaller arbitrary functions, especially 
 * for any time or memory intensive operations.
 */
function __process() {
  // Bring config vars into function namespace  
  extract(__config());

  // Append underscore to emails to prevent rogue emails from going out
  drush_print("Appending underscores to email addresses.");
  db_query("UPDATE {users} SET mail = CONCAT(mail, '_') WHERE uid > 1");

  // Add additional code here
}

/**
 * Placeholder function for additional processing code.
 *
 * Feel free to use this function ore rename it to something else.
 * Example usage in drush script:
 *   drush -l <url> script import.drush.php process_1
 *
 */
function __process_1() {
  // Add code here
}
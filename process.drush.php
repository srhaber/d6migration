<?php

/**
 * @file
 * Place all post-import processing code in a drush script.
 */
 
include $_SERVER['PWD'] . '/config.php';
include 'includes/custom.inc';


// Wrap code in function to prevent from clobbering global namespace.
__main();

function __main() {
  $args = $_SERVER['argv'];
  
  // Ignore if user invoked using php command
  if ($_SERVER['argv'][0] === 'php' || $_SERVER['argv'][0] === 'php5') {
    array_shift($args);
  }
  
  // Check for legal parameter count
  if (count($args) != 2) {
    __printusage();
  }

  // Run the invoked command
  $command = $args[1];
  $func = '__' . $command;
  if (function_exists($func)) {
    $func();
  }
  else {
    __printusage();
  }
}

/**
 * Sample process function taken from veronicas6 migration.  It may be
 * helpful to break some of these into smaller arbitrary functions, especially 
 * for any time or memory intensive operations.
 */
function __process() {
  // Bring config vars into function namespace  
  extract(__config());

  // Append underscore to emails to prevent rogue emails from going out
  db_query("UPDATE {users} SET mail = CONCAT(mail, '_') WHERE uid > 1");

  // Enable comment for profile nodes
  db_query("UPDATE node SET comment = 2 WHERE type = 'profile';");
  
  // Remove wbr_callout nodes
  $rs = db_query("SELECT nid FROM node WHERE type = 'wbr_callout'");
  while($row = db_fetch_object($rs)) {
    my_node_delete($row->nid);
    drush_print("Delete node {$row->nid}");
  }  
}

/**
 * Import webform data into new site.  
 * 
 * Typically this will be done during the import phase, but it's common to import 
 * new data during the processing phase as well.  The webform data maps cleanly from
 * Drupal 5 to 6.
 */
function __webform() {
  // Bring config vars into function namespace  
  extract(__config());
  
  // Webform mapping
  db_query("REPLACE INTO {webform} SELECT * FROM `{$sourcedb}`.webform");
  db_query("REPLACE INTO {webform_component} SELECT `nid`, `cid`, `pid`, `form_key`, `name`, `type`, `value`, `extra`, `mandatory`, '', `weight` FROM `{$sourcedb}`.webform_component");
  db_query("REPLACE INTO {webform_submissions} SELECT * FROM `{$sourcedb}`.webform_submissions");
  db_query("REPLACE INTO {webform_submitted_data} SELECT * FROM `{$sourcedb}`.webform_submitted_data");  
}


/**
 * Convert tour_appearance nodes to show nodes.
 *
 * The old veronicas site contained a node type called "tour_appearance" to store
 * tour dates.  The new site uses a much more concise "show" node type.  This function
 * maps and imports the tour data from the old site to the new site.
 */
function __tour() {
  // Bring config vars into function namespace so we can get the sourcedb
  extract(__config());  
  
  // Change all tour_appearance nodes to show nodes.
  db_query("UPDATE {node} SET type = 'show' WHERE type='tour_appearance'");
  
  // Set default values for mapping the data in content_type_show on the new site
  $fields = array(
    'vid' => '',
    'nid' => '',
    'field_reference_setlist_view_id'=> 60,
    'field_reference_setlist_arguments' => '',
    'field_show_location_lid' => '',
    'field_show_date_value' => '',
    'field_show_time_value' => '',
    'field_show_bands_value' => '',
    'field_show_price_value' => '',
    'field_show_venue_url' => '',
    'field_show_venue_title' => '',
    'field_show_venue_attributes' => '',
    'field_show_buy_url' => '',
    'field_show_buy_title' => '',
    'field_show_buy_attributes' => '',
    'field_show_ages_value' => '',
    'field_show_photo_fid' => '',
    'field_show_photo_list' => '',
    'field_show_photo_data' => '',
  );
  
  // Query the old site and map the data for each tour_appearance node into a
  // show node, one at a time.
  $rs = db_query("SELECT * FROM `{$sourcedb}`.`content_type_tour_appearance`");
  while($row = db_fetch_object($rs)) {
    $fields['vid'] = $row->vid;
    $fields['nid'] = $row->nid;
    $fields['field_show_venue_url'] = $row->field_venue_link_url;
    $fields['field_show_venue_title'] = $row->field_venue_link_title;
    $fields['field_show_venue_attributes'] = $row->field_venue_link_attributes;
    $fields['field_show_date_value'] = date("Y-m-d", $row->field_tour_appearance_date_value) . "T00:00:00";
    $fields['field_show_bands_value'] = $row->field_other_bands_value;
    $fields['field_show_price_value'] = $row->field_price_value;
    $fields['field_show_time_value'] = $row->field_showtime_value;
    $fields['field_show_buy_url'] = $row->field_tour_stop_ticket_purchase_url;
    $fields['field_show_buy_title'] = $row->field_tour_stop_ticket_purchase_title;
    $fields['field_show_buy_attributes'] = $row->field_tour_stop_ticket_purchase_attributes;

    // Build and execute our query to import the show data
    $sql_fields = array();
    foreach($fields as $field => $value) {
      $value = mysql_real_escape_string($value);
      $sql_fields[] = "`{$field}` = '{$value}'";
    }
    $set_clause = implode(", ", $sql_fields); 
    db_query("INSERT IGNORE INTO {content_type_show} SET " . $set_clause);
  }  
}
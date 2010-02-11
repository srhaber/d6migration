<?php

/**
 * @file
 * Place all post-import processing code in a drush script.
 */
 
include $_SERVER['PWD'] . '/config.php';
include 'includes/custom.inc';


// Wrap code in function to prevent from clobbering global namespace.
__main();

/**
 * A simple engine to run a specific function during processing.
 *
 * A function name (minus the two underscores) may be passed as an argument
 * to the drush script.  If no argument is given, the script will default to 
 * the __process() function.
 *
 * Don't place underscores when passing an argument.
 * Examples:
 *  drush -l <url> script process.drush.php           # CORRECT, invoke __process()
 *  drush -l <url> script process.drush.php process   # CORRECT, invoke __process()
 *  drush -l <url> script process.drush.php nodemap   # CORRECT, invoke __nodemap()
 *  drush -l <url> script process.drush.php __nodemap   # INCORRECT, won't invoke __nodemap()
 *
 * If no function exists, a usage message is displayed and the script terminates without
 * invoking any function.
 */
function __main() {
  $args = $_SERVER['argv'];
  $cmd = $args[count($args) - 1];
  
  if ($cmd == basename(__FILE__)) {
    __process();
  }
  elseif (function_exists('__' . $cmd)) {
    $func = '__' . $cmd;
    $func();
  }
  else {
    __printusage();
  }
}

/**
 * Display usage message to the screen.
 */
function __printusage() {
  echo "Usage: drush -l <url> script " . basename(__FILE__) . " [command]\n";
  echo "Commands:\n";
  echo "  process - The default process command to run.\n";
  echo "  <user defined> - Other user defined functions, ignore the prepending underscores.\n\n";
}

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
  db_query("UPDATE {users} SET mail = CONCAT(mail, '_') WHERE uid > 1");

  // Enable comments for profile nodes
  db_query("UPDATE node SET comment = 2 WHERE type = 'profile';");
  
  // Remove wbr_callout nodes, these are no longer used on the new site
  $rs = db_query("SELECT nid FROM node WHERE type = 'wbr_callout'");
  while($row = db_fetch_object($rs)) {
    // We use a custom function that wraps the core node_delete.
    my_node_delete($row->nid);
    drush_print("Delete node {$row->nid}");
  }
  
  // Delete one image.  The nid was determined by examining the node table.
  my_node_delete(39);
  
  // Delete two featured_video nodes. The first attempt failed due to a bug in the location module.  
  // Thus, we temporarily disable that module to perform the deletion.  The call to 
  // module_list(TRUE) is necessary so that the internal module list gets rebuilt.
  // Refer to http://api.drupal.org, especially when encountering situations like this.
  db_query("UPDATE {system} SET status = 0 WHERE name = 'location' AND type = 'module'");
  module_list(TRUE);
  my_node_delete(1041);
  my_node_delete(1050);
  db_query("UPDATE {system} SET status = 1 WHERE name = 'location' AND type = 'module'");
  module_list(TRUE);
  
  // Delete imported blog node
  my_node_delete(25);
  
  // Populate content_type_news table for news nodes
  db_query("INSERT IGNORE INTO content_type_news (vid, nid) SELECT vid, nid FROM node WHERE type = 'news'");
  
  // Delete imported video nodes
  $rs = db_query("SELECT nid FROM node WHERE type = 'video' AND nid < 10000");
  while ($row = db_fetch_object($rs)) {
    my_node_delete($row->nid);
    drush_print("Deleted node {$row->nid}");
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

/**
 * Convert general_image nodes to photo nodes.
 *
 * All general_images nodes on the old site will become standard photo nodes
 * on the new site.  All physical files are preserved when we migrate sites,
 * so the filepaths stay the same.
 */
function __photo() {
  // Bring config vars into function namespace  
  extract(__config());  

  // Change all general_image nodes to photo nodes
  db_query("UPDATE {node} SET type = 'photo' WHERE type='general_image'");
  
  // Set default values for mapping the data in content_type_photo.
  $fields = array(
    'vid' => '',
    'nid' => '',
    'field_photo_image_fid' => '',
    'field_photo_image_list' => '',
    'field_photo_image_data' => '',
    );

  // Map the data for each general_image node into photo node, one at a time.
  $rs = db_query("SELECT * FROM `{$sourcedb}`.`content_type_general_image`");
  while ($row = db_fetch_object($rs)) {
    $fields['vid'] = $row->vid;
    $fields['nid'] = $row->nid;
    $fields['field_photo_image_fid'] = $row->field_general_image_fid;
    $fields['field_photo_image_list'] = 0; 
    $fields['field_photo_image_data'] = serialize(array('alt' => $row->field_general_image_alt, 'title' => $row->field_general_image_title));

    // Build and execute the query
    $sql_fields = array();
    foreach($fields as $field => $value) {
      $value = mysql_real_escape_string($value);
      $sql_fields[] = "`{$field}` = '{$value}'";
    }
    $set_clause = implode(", ", $sql_fields);
    db_query("INSERT IGNORE INTO {content_type_photo} SET " . $set_clause);    
  }  
}

/**
 * Fix taxonomy for photos
 *
 * It is often necessary to map new taxonomy terms to imported objects. Here, we
 * are assigning correct terms to newly converted photo nodes so they will appear
 * in the site's photo gallery.
 */
function __photostax() {
  // Replace old term IDs with new equivalends
  db_query("UPDATE {term_node} SET `tid` = %d WHERE `tid` = %d", 123, 8);
  db_query("UPDATE {term_node} SET `tid` = %d WHERE `tid` = %d", 124, 7);
  db_query("UPDATE {term_node} SET `tid` = %d WHERE `tid` = %d", 120, 3);
  db_query("UPDATE {term_node} SET `tid` = %d WHERE `tid` = %d", 121, 4);
  db_query("UPDATE {term_node} SET `tid` = %d WHERE `tid` = %d", 122, 6);
  db_query("UPDATE {term_node} SET `tid` = %d WHERE `tid` = %d", 126, 5);
  
  // Add Official or Fan terms
  $rs = db_query("SELECT nid, vid FROM {node} WHERE type = 'photo' AND nid < 10000");
  while ($row = db_fetch_object($rs)) {
    $nid = $row->nid;
    $vid = $row->vid;
    $tid = db_result(db_query("SELECT tid FROM {term_node} WHERE vid = %d", $vid));
    // Remove record from bad insert on previous run
    db_query("DELETE FROM {term_node} WHERE vid = %d AND tid = %d", $vid, 100);
        
    if ($tid === 126) { // Fan term
      db_query("INSERT INTO {term_node} VALUES(%d, %d, %d)", $nid, $vid, 102);      
    }
    else {  // Official term
      db_query("INSERT INTO {term_node} VALUES(%d, %d, %d)", $nid, $vid, 100);           
    }
  }
}
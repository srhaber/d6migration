#!/usr/bin/env php
<?php

/**
 * @file
 * Import data into new site.
 */

include 'config.php';

// Wrap all code in functions to prevent from clobberring the global namespace.
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
 * Display command usage to the screen and exit.
 */
function __printusage() {
  echo "Usage: " . basename($_SERVER['SCRIPT_FILENAME']) . " <command>\n";
  echo "Commands:\n";
  echo "  init - Initialize the dev site. Should only be run once.\n";
  echo "  import - Import data from the source site.\n\n";
  exit(1);  
}

/**
 * Test function for usage.
 */
function __test() {
  echo "pid [" . posix_getpid() . "]\n";
  exit(0);
}

/**
 * Initialize auto-inc values on dev site.  Run this function only once, at setup.
 */
function __init() {
  // Bring config vars into function namespace  
  extract(__config());

  // Enable profile module 
  module_enable(array('profile'));
  
  // Set auto-increment values
  db_query("ALTER TABLE `authmap` AUTO_INCREMENT = %d", $authmap);
  db_query("ALTER TABLE `comments` AUTO_INCREMENT = %d", $comments);
  db_query("ALTER TABLE `files` AUTO_INCREMENT = %d", $files);
  db_query("ALTER TABLE `node` AUTO_INCREMENT = %d", $node);
  db_query("ALTER TABLE `node_revisions` AUTO_INCREMENT = %d", $node_revisions);
  db_query("ALTER TABLE `node_type` AUTO_INCREMENT = %d", $node_type);
  db_query("ALTER TABLE `profile_fields` AUTO_INCREMENT = %d", $profile_fields);
  db_query("ALTER TABLE `role` AUTO_INCREMENT = %d", $role);
  db_query("ALTER TABLE `term_data` AUTO_INCREMENT = %d", $term_data);
  db_query("ALTER TABLE `url_alias` AUTO_INCREMENT = %d", $url_alias);
  db_query("ALTER TABLE `users` AUTO_INCREMENT = %d", $users);
  db_query("ALTER TABLE `vocabulary` AUTO_INCREMENT = %d", $vocabulary);
}

/**
 * Import data from key tables from Drupal 5.x to Drupal 6.x
 */
function __import() {
  // Bring config vars into function namespace
  extract(__config());

  // authmap
  db_query("REPLACE INTO {authmap} SELECT a.* FROM `{$sourcedb}`.authmap a WHERE a.aid < %d", $authmap);
  
  // comments
  db_query("REPLACE INTO {comments} SELECT `cid`, `pid`, `nid`, `uid`, `subject`, `comment`, `hostname`, `timestamp`, `status`, `format`, `thread`, `name`, `mail`, `homepage` FROM `{$sourcedb}`.comments c WHERE c.cid < %d", $comments);

  // files
  db_query("REPLACE INTO {files} SELECT f.`fid`, n.`uid`, f.`filename`, f.`filepath`, f.`filemime`, f.`filesize`, n.`status`, n.`created` FROM `{$sourcedb}`.files f, `{$sourcedb}`.node n WHERE f.fid < %d AND n.nid < %d AND f.nid = n.nid", $files, $node);

  // node
  db_query("REPLACE INTO {node} SELECT `nid`, `vid`, `type`, 'en', `title`, `uid`, `status`, `created`, `changed`, `comment`, `promote`, `moderate`, `sticky`, 0, 0 FROM `{$sourcedb}`.node n WHERE n.nid < %d", $node);

  // node_access
  db_query("REPLACE INTO {node_access} SELECT n.* FROM `{$sourcedb}`.node_access n WHERE n.nid < %d", $node);

  // node_comment_statistics
  db_query("REPLACE INTO {node_comment_statistics} SELECT n.* FROM `{$sourcedb}`.node_comment_statistics n WHERE n.nid < %d", $node);

  // node_counter
  db_query("REPLACE INTO {node_counter} SELECT n.* FROM `{$sourcedb}`.node_counter n WHERE n.nid < %d", $node);

  // node_revisions
  db_query("REPLACE INTO {node_revisions} SELECT `nid`, `vid`, `uid`, `title`, `body`, `teaser`, `log`, `timestamp`, `format` FROM `{$sourcedb}`.node_revisions n WHERE n.vid < %d", $node_revisions);

  // node_type
  db_query("REPLACE INTO {node_type} SELECT n.* FROM `{$sourcedb}`.node_type n WHERE n.type < %d", $node_type);

  // profile_fields
  db_query("REPLACE INTO {profile_fields} SELECT f.* FROM `{$sourcedb}`.profile_fields f WHERE f.fid < %d", $profile_fields);

  // profile_values
  db_query("REPLACE INTO {profile_values} SELECT p.* FROM `{$sourcedb}`.profile_values p WHERE p.uid < %d AND p.fid < %d AND p.uid > 1", $users, $profile_fields);

  // role
  db_query("REPLACE INTO {role} SELECT r.* FROM `{$sourcedb}`.role r WHERE r.rid < %d", $role);

  // term_data
  db_query("REPLACE INTO {term_data} SELECT t.* FROM `{$sourcedb}`.term_data t WHERE t.tid < %d", $term_data);

  // term_hierarchy
  db_query("REPLACE INTO {term_hierarchy} SELECT t.* FROM `{$sourcedb}`.term_hierarchy t WHERE t.tid < %d", $term_hierarchy);

  // term_node
  db_query("REPLACE INTO {term_node} SELECT t.`nid`, n.`vid`, t.`tid` FROM `{$sourcedb}`.term_node t, `{$sourcedb}`.node n WHERE t.tid < %d AND n.nid < %d AND t.nid = n.nid", $term_data, $node);

  // term_relation
  db_query("REPLACE INTO {term_relation} SELECT '', t.`tid1`, t.`tid2` FROM `{$sourcedb}`.term_relation t WHERE t.tid1 < %d AND t.tid2 < %d", $term_data, $term_data);

  // term_synonym
  db_query("INSERT IGNORE INTO {term_synonym} SELECT '', t.`tid`, t.`name` FROM `{$sourcedb}`.term_synonym t WHERE t.tid < %d", $term_data);

  // url_alias
  db_query("REPLACE INTO {url_alias} SELECT pid, src, dst, '' FROM `{$sourcedb}`.url_alias u WHERE u.pid < %d", $url_alias);

  // users
  db_query("REPLACE INTO {users} SELECT `uid`, `name`, `pass`, `mail`, `mode`, `sort`, `threshold`, `theme`, `signature`, 0, `created`, `access`, `login`, `status`, `timezone`, `language`, `picture`, `init`, `data`, '' FROM `{$sourcedb}`.users u WHERE u.uid < %d AND u.uid > 1", $users);

  // user_roles
  db_query("REPLACE INTO {user_roles} SELECT u.* FROM `{$sourcedb}`.user_roles u WHERE u.uid < %d AND r.rid < %d", $user, $role);

  // vocabulary
  db_query("REPLACE INTO {vocabulary} SELECT `vid`, `name`, `description`, `help`, `relations`, `hierarchy`, `multiple`, `required`, `tags`, `module`, `weight` FROM `{$sourcedb}`.vocabulary v WHERE v.vid < %d", $vocabulary);

  // vocabulary_node_types
  db_query("REPLACE INTO {vocabulary_node_types} SELECT * FROM `{$sourcedb}`.vocabulary_node_types v WHERE v.vid < %d", $vocabulary);  
}

function __process() {
  // Nodes need re-settings: filter, comment 
}
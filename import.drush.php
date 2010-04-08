<?php

/**
 * @file
 * Drush script that handles all content migration code.
 */
 
include $_SERVER['PWD'] . '/config.inc';
include $_SERVER['PWD'] . '/process.inc';

// Wrap code in function to prevent from clobbering global namespace.
__main();

/**
 * A simple engine to run a specific function during processing.
 *
 * A function name (minus the two underscores) is passed as an argument
 * to the drush script.  If no argument is given, the script will display
 * an usage message to the screen and terminate.
 *
 * Don't place underscores when passing an argument.
 * Examples:
 *  drush -l <url> script import.drush.php init        # CORRECT, invoke __init()
 *  drush -l <url> script import.drush.php import      # CORRECT, invoke __import()
 *  drush -l <url> script import.drush.php __process   # INCORRECT, won't invoke __process()
 *
 * If no function exists, a usage message is displayed and the script terminates without
 * invoking any function.
 */
function __main() {
  $args = $_SERVER['argv'];
  $cmd = $args[count($args) - 1];

  if (function_exists('__' . $cmd)) {
    $func = '__' . $cmd;
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
  echo "Usage: drush -l <url> script " . basename(__FILE__) . " <command>\n";
  echo "Commands:\n";
  echo "  init - Initialize the dev site. Should only be run once.\n";
  echo "  import - Import data from the source site.\n";
  echo "  process - The default process command to run.\n";
  echo "  <user defined> - Other user defined functions, ignore the prepending underscores.\n\n";  
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
  drush_print("Installing profile module.");
  if (drush_drupal_major_version() < 7) {
    include_once 'includes/install.inc';
    drupal_install_modules(array('profile'));
  }
  else {
    module_enable(array('profile'));
  }
  
  // Set auto-increment values
  $tables = array('authmap', 'comments', 'files', 'node', 'node_revisions', 
    'profile_fields', 'role', 'term_data', 'url_alias', 'users', 'vocabulary');
    
  foreach($tables as $table) {
    $value = $$table;
    db_query("ALTER TABLE `{$table}` AUTO_INCREMENT = %d", $value);
    drush_print("Set auto-increment value for {$table} to {$value}");
  }
  unset($value);  
    
  drush_print("Initialization done!");
}

/**
 * Import data from key tables from Drupal 5.x to Drupal 6.x
 */
function __import() {
  // Bring config vars into function namespace
  extract(__config());

  drush_print("Importing data.");

  // authmap
  db_query("REPLACE INTO {authmap} SELECT a.* FROM `{$sourcedb}`.authmap a WHERE a.aid < %d", $authmap);
  drush_print("Imported data into authmap. Affected rows: " . db_affected_rows());
  
  // comments
  db_query("REPLACE INTO {comments} SELECT `cid`, `pid`, `nid`, `uid`, `subject`, `comment`, `hostname`, `timestamp`, `status`, `format`, `thread`, `name`, `mail`, `homepage` FROM `{$sourcedb}`.comments c WHERE c.cid < %d", $comments);
  drush_print("Imported data into comments. Affected rows: " . db_affected_rows());
  
  // files
  db_query("REPLACE INTO {files} SELECT f.`fid`, n.`uid`, f.`filename`, f.`filepath`, f.`filemime`, f.`filesize`, n.`status`, n.`created` FROM `{$sourcedb}`.files f, `{$sourcedb}`.node n WHERE f.fid < %d AND n.nid < %d AND f.nid = n.nid", $files, $node);
  drush_print("Imported data into files. Affected rows: " . db_affected_rows());
  
  // node
  db_query("REPLACE INTO {node} SELECT `nid`, `vid`, `type`, 'en', `title`, `uid`, `status`, `created`, `changed`, `comment`, `promote`, `moderate`, `sticky`, 0, 0 FROM `{$sourcedb}`.node n WHERE n.nid < %d", $node);
  drush_print("Imported data into node. Affected rows: " . db_affected_rows());

  // node_access
  db_query("REPLACE INTO {node_access} SELECT n.* FROM `{$sourcedb}`.node_access n WHERE n.nid < %d", $node);
  drush_print("Imported data into node_access. Affected rows: " . db_affected_rows());

  // node_comment_statistics
  db_query("REPLACE INTO {node_comment_statistics} SELECT n.* FROM `{$sourcedb}`.node_comment_statistics n WHERE n.nid < %d", $node);
  drush_print("Imported data into node_comment_statistics. Affected rows: " . db_affected_rows());

  // node_counter
  db_query("REPLACE INTO {node_counter} SELECT n.* FROM `{$sourcedb}`.node_counter n WHERE n.nid < %d", $node);
  drush_print("Imported data into node_counter. Affected rows: " . db_affected_rows());

  // node_revisions
  db_query("REPLACE INTO {node_revisions} SELECT `nid`, `vid`, `uid`, `title`, `body`, `teaser`, `log`, `timestamp`, `format` FROM `{$sourcedb}`.node_revisions n WHERE n.vid < %d", $node_revisions);
  drush_print("Imported data into node_revisions. Affected rows: " . db_affected_rows());

  // node_type
  db_query("REPLACE INTO {node_type} SELECT n.* FROM `{$sourcedb}`.node_type n");
  drush_print("Imported data into node_type. Affected rows: " . db_affected_rows());

  // profile_fields
  db_query("REPLACE INTO {profile_fields} SELECT f.* FROM `{$sourcedb}`.profile_fields f WHERE f.fid < %d", $profile_fields);
  drush_print("Imported data into profile_fields. Affected rows: " . db_affected_rows());

  // profile_values
  db_query("REPLACE INTO {profile_values} SELECT p.* FROM `{$sourcedb}`.profile_values p WHERE p.uid < %d AND p.fid < %d AND p.uid > 1", $users, $profile_fields);
  drush_print("Imported data into profile_values. Affected rows: " . db_affected_rows());

  // role
  db_query("REPLACE INTO {role} SELECT r.* FROM `{$sourcedb}`.role r WHERE r.rid < %d", $role);
  drush_print("Imported data into role. Affected rows: " . db_affected_rows());

  // term_data
  db_query("REPLACE INTO {term_data} SELECT t.* FROM `{$sourcedb}`.term_data t WHERE t.tid < %d", $term_data);
  drush_print("Imported data into term_data. Affected rows: " . db_affected_rows());

  // term_hierarchy
  db_query("REPLACE INTO {term_hierarchy} SELECT t.* FROM `{$sourcedb}`.term_hierarchy t WHERE t.tid < %d", $term_data);
  drush_print("Imported data into term_hierarchy. Affected rows: " . db_affected_rows());

  // term_node
  db_query("REPLACE INTO {term_node} SELECT t.`nid`, n.`vid`, t.`tid` FROM `{$sourcedb}`.term_node t, `{$sourcedb}`.node n WHERE t.tid < %d AND n.nid < %d AND t.nid = n.nid", $term_data, $node);
  drush_print("Imported data into term_node.Affected rows: " . db_affected_rows());

  // term_relation
  db_query("REPLACE INTO {term_relation} SELECT '', t.`tid1`, t.`tid2` FROM `{$sourcedb}`.term_relation t WHERE t.tid1 < %d AND t.tid2 < %d", $term_data, $term_data);
  drush_print("Imported data into term_relation. Affected rows: " . db_affected_rows());

  // term_synonym
  db_query("INSERT IGNORE INTO {term_synonym} SELECT '', t.`tid`, t.`name` FROM `{$sourcedb}`.term_synonym t WHERE t.tid < %d", $term_data);
  drush_print("Imported data into term_synonym. Affected rows: " . db_affected_rows());

  // url_alias
  db_query("REPLACE INTO {url_alias} SELECT pid, src, dst, '' FROM `{$sourcedb}`.url_alias u WHERE u.pid < %d", $url_alias);
  drush_print("Imported data into url_alias. Affected rows: " . db_affected_rows());

  // users
  db_query("REPLACE INTO {users} SELECT `uid`, `name`, `pass`, `mail`, `mode`, `sort`, `threshold`, `theme`, `signature`, 0, `created`, `access`, `login`, `status`, `timezone`, `language`, `picture`, `init`, `data` FROM `{$sourcedb}`.users u WHERE u.uid < %d AND u.uid > 1", $users);
  drush_print("Imported data into users.Affected rows: " . db_affected_rows());

  // user_roles
  db_query("REPLACE INTO {users_roles} SELECT u.* FROM `{$sourcedb}`.users_roles u WHERE u.uid < %d AND u.rid < %d", $users, $role);
  drush_print("Imported data into users_roles. Affected rows: " . db_affected_rows());

  // vocabulary
  db_query("REPLACE INTO {vocabulary} SELECT `vid`, `name`, `description`, `help`, `relations`, `hierarchy`, `multiple`, `required`, `tags`, `module`, `weight` FROM `{$sourcedb}`.vocabulary v WHERE v.vid < %d", $vocabulary);
  drush_print("Imported data into vocabulary. Affected rows: " . db_affected_rows());

  // vocabulary_node_types
  db_query("REPLACE INTO {vocabulary_node_types} SELECT * FROM `{$sourcedb}`.vocabulary_node_types v WHERE v.vid < %d", $vocabulary);
  drush_print("Imported data into vocabulary_node_types.Affected rows: " . db_affected_rows());
  
  drush_print("Import done!");
}
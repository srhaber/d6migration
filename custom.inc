<?php
/**
 * @file
 * Custom functions that many custom modules and scripts may reference.
 */
 
/**
 * Custom wrapper for user_save(). 
 *
 * @param $user
 *  A user object. Explicitly set a uid value 
 *  to insert or update a specific user.
 * @return
 *  A user object.
 */
function my_user_save($user) {
  $account = $user;
  $array = (array)$user;
  
  // Dynamically compose a SQL query:
  $user_fields = user_fields();
  if (is_object($account) && $account->uid) {
    user_module_invoke('update', $array, $account, $category);
    $query = '';
    $data = unserialize(db_result(db_query('SELECT data FROM {users} WHERE uid = %d', $account->uid)));
    // Consider users edited by an administrator as logged in, if they haven't
    // already, so anonymous users can view the profile (if allowed).
    if (empty($array['access']) && empty($account->access) && user_access('administer users')) {
      $array['access'] = time();
    }
    foreach ($array as $key => $value) {
      if ($key == 'pass' && !empty($value)) {
        $query .= "$key = '%s', ";
        $v[] = md5($value);
      }
      else if ((substr($key, 0, 4) !== 'auth') && ($key != 'pass')) {
        if (in_array($key, $user_fields)) {
          // Save standard fields.
          $query .= "$key = '%s', ";
          $v[] = $value;
        }
        else if ($key != 'roles') {
          // Roles is a special case: it used below.
          if ($value === NULL) {
            unset($data[$key]);
          }
          else {
            $data[$key] = $value;
          }
        }
      }
    }
    $query .= "data = '%s' ";
    $v[] = serialize($data);

    $success = db_query("UPDATE {users} SET $query WHERE uid = %d", array_merge($v, array($account->uid)));
    if (!$success) {
      // The query failed - better to abort the save than risk further data loss.
      return FALSE;
    }

    // Reload user roles if provided.
    if (isset($array['roles']) && is_array($array['roles'])) {
      db_query('DELETE FROM {users_roles} WHERE uid = %d', $account->uid);

      foreach (array_keys($array['roles']) as $rid) {
        if (!in_array($rid, array(DRUPAL_ANONYMOUS_RID, DRUPAL_AUTHENTICATED_RID))) {
          db_query('INSERT INTO {users_roles} (uid, rid) VALUES (%d, %d)', $account->uid, $rid);
        }
      }
    }

    // Delete a blocked user's sessions to kick them if they are online.
    if (isset($array['status']) && $array['status'] == 0) {
      sess_destroy_uid($account->uid);
    }

    // If the password changed, delete all open sessions and recreate
    // the current one.
    if (!empty($array['pass'])) {
      sess_destroy_uid($account->uid);
      if ($account->uid == $GLOBALS['user']->uid) {
        sess_regenerate();
      }
    }

    // Refresh user object.
    $user = user_load(array('uid' => $account->uid));

    // Send emails after we have the new user object.
    if (isset($array['status']) && $array['status'] != $account->status) {
      // The user's status is changing; conditionally send notification email.
      $op = $array['status'] == 1 ? 'status_activated' : 'status_blocked';
      //_user_mail_notify($op, $user);
    }

    user_module_invoke('after_update', $array, $user, $category);
  }
  else {
    // Allow 'created' to be set by the caller.
    if (!isset($array['created'])) {
      $array['created'] = time();
    }
    // Consider users created by an administrator as already logged in, so
    // anonymous users can view the profile (if allowed).
    if (empty($array['access']) && user_access('administer users')) {
      $array['access'] = time();
    }

    // Note: we wait to save the data column to prevent module-handled
    // fields from being saved there. We cannot invoke hook_user('insert') here
    // because we don't have a fully initialized user object yet.
    foreach ($array as $key => $value) {
      switch ($key) {
        case 'pass':
          $fields[] = $key;
          $values[] = md5($value);
          $s[] = "'%s'";
          break;
        case 'mode':       case 'sort':     case 'timezone':
        case 'threshold':  case 'created':  case 'access':
        case 'login':      case 'status':
          $fields[] = $key;
          $values[] = $value;
          $s[] = "%d";
          break;
        default:
          if (substr($key, 0, 4) !== 'auth' && in_array($key, $user_fields)) {
            $fields[] = $key;
            $values[] = $value;
            $s[] = "'%s'";
          }
          break;
      }
    }
    $success = db_query('INSERT INTO {users} ('. implode(', ', $fields) .') VALUES ('. implode(', ', $s) .')', $values);
    if (!$success) {
      // On a failed INSERT some other existing user's uid may be returned.
      // We must abort to avoid overwriting their account.
      return FALSE;
    }

    // Build the initial user object.
    if (!is_numeric($array['uid'])) {
      $array['uid'] = db_last_insert_id('users', 'uid');
    }
    
    $user = user_load(array('uid' => $array['uid']));

    user_module_invoke('insert', $array, $user, $category);

    // Build and save the serialized data field now.
    $data = array();
    foreach ($array as $key => $value) {
      if ((substr($key, 0, 4) !== 'auth') && ($key != 'roles') && (!in_array($key, $user_fields)) && ($value !== NULL)) {
        $data[$key] = $value;
      }
    }
    db_query("UPDATE {users} SET data = '%s' WHERE uid = %d", serialize($data), $user->uid);

    // Save user roles (delete just to be safe).
    if (isset($array['roles']) && is_array($array['roles'])) {
      db_query('DELETE FROM {users_roles} WHERE uid = %d', $array['uid']);
      foreach (array_keys($array['roles']) as $rid) {
        if (!in_array($rid, array(DRUPAL_ANONYMOUS_RID, DRUPAL_AUTHENTICATED_RID))) {
          db_query('INSERT INTO {users_roles} (uid, rid) VALUES (%d, %d)', $array['uid'], $rid);
        }
      }
    }

    // Build the finished user object.
    $user = user_load(array('uid' => $array['uid']));
  }

  // Save distributed authentication mappings.
  $authmaps = array();
  foreach ($array as $key => $value) {
    if (substr($key, 0, 4) == 'auth') {
      $authmaps[$key] = $value;
    }
  }
  if (sizeof($authmaps) > 0) {
    user_set_authmaps($user, $authmaps);
  }

  return $user;  
}

/**
 * Save a pre-encrypted password for the user.
 *
 * @param $uid
 *  The user ID.
 * @param $pass
 *  A pre-encrypted password using the MD5 algorithm.
 * @return
 *  TRUE is the save was succesful, FALSE otherwise.
 */
function my_user_save_pass($uid, $pass) {
  return db_query("UPDATE {users} SET `pass` = '%s' WHERE `uid` = %d", $pass, $uid);
}

/**
 * Custom wrapper for node_save(). 
 *
 * @param &$node
 *  A reference to a node object. Explicitly set a nid value 
 *  to insert or update a specific node.
 */
function my_node_save(&$node) {
  // Let modules modify the node before it is saved to the database.
  node_invoke_nodeapi($node, 'presave');
  //global $user;
  $user = user_load($node->uid);

  $node->is_new = FALSE;

  // Apply filters to some default node fields:
  if (empty($node->nid)) {
    // Insert a new node.
    $node->is_new = TRUE;

    // When inserting a node, $node->log must be set because
    // {node_revisions}.log does not (and cannot) have a default
    // value.  If the user does not have permission to create
    // revisions, however, the form will not contain an element for
    // log so $node->log will be unset at this point.
    if (!isset($node->log)) {
      $node->log = '';
    }

    // For the same reasons, make sure we have $node->teaser and
    // $node->body.  We should consider making these fields nullable
    // in a future version since node types are not required to use them.
    if (!isset($node->teaser)) {
      $node->teaser = '';
    }
    if (!isset($node->body)) {
      $node->body = '';
    }
  }
  elseif (!empty($node->revision)) {
    $node->old_vid = $node->vid;
  }
  else {
    // When updating a node, avoid clobberring an existing log entry with an empty one.
    if (empty($node->log)) {
      unset($node->log);
    }
  }

  // Set some required fields:
  if (empty($node->created)) {
    $node->created = time();
  }
  // The changed timestamp is always updated for bookkeeping purposes (revisions, searching, ...)
  if (empty($node->changed)) {
    $node->changed = time();
  }

  $node->timestamp = time();
  $node->format = isset($node->format) ? $node->format : FILTER_FORMAT_DEFAULT;

  // Generate the node table query and the node_revisions table query.
  if ($node->is_new) {
    _node_save_revision($node, $user->uid);
    drupal_write_record('node', $node);
    db_query('UPDATE {node_revisions} SET nid = %d WHERE vid = %d', $node->nid, $node->vid);
    $op = 'insert';
  }
  else {
    drupal_write_record('node', $node, 'nid');
    if (!empty($node->revision)) {
      _node_save_revision($node, $user->uid);
      db_query('UPDATE {node} SET vid = %d WHERE nid = %d', $node->vid, $node->nid);
    }
    else {
      _node_save_revision($node, $user->uid, 'vid');
    }
    $op = 'update';
  }

  // Call the node specific callback (if any).
  node_invoke($node, $op);
  node_invoke_nodeapi($node, $op);

  // Update the node access table for this node.
  node_access_acquire_grants($node);

  // Clear the page and block caches.
  cache_clear_all();
}  

/**
 * Custom wrapper for comment_save(). 
 *
 * @param $comment
 *  A comment object. Explicitly set a cid value 
 *  to insert or update a specific comment.
 * @return
 *  If the comment is successfully saved the comment ID is returned. If the comment is not saved, FALSE is returned.
 */
function my_comment_save($comment) {
  $edit = (array)$comment;
  
  global $user;
  if (TRUE || (user_access('post comments') && (user_access('administer comments') || node_comment_mode($edit['nid']) == COMMENT_NODE_READ_WRITE))) {
    if (TRUE || !form_get_errors()) {
      $edit += array(
        'mail' => '',
        'homepage' => '',
        'name' => '',
        'status' => user_access('post comments without approval') ? COMMENT_PUBLISHED : COMMENT_NOT_PUBLISHED,
      );
      if ($edit['cid']) {
        // Update the comment in the database.
        db_query("UPDATE {comments} SET status = %d, timestamp = %d, subject = '%s', comment = '%s', format = %d, uid = %d, name = '%s', mail = '%s', homepage = '%s' WHERE cid = %d", $edit['status'], $edit['timestamp'], $edit['subject'], $edit['comment'], $edit['format'], $edit['uid'], $edit['name'], $edit['mail'], $edit['homepage'], $edit['cid']);

        // Allow modules to respond to the updating of a comment.
        comment_invoke_comment($edit, 'update');

        // Add an entry to the watchdog log.
        watchdog('content', 'Comment: updated %subject.', array('%subject' => $edit['subject']), WATCHDOG_NOTICE, l(t('view'), 'node/'. $edit['nid'], array('fragment' => 'comment-'. $edit['cid'])));
      }
      else {
        // Add the comment to database.
        // Here we are building the thread field. See the documentation for
        // comment_render().
        if ($edit['pid'] == 0) {
          // This is a comment with no parent comment (depth 0): we start
          // by retrieving the maximum thread level.
          $max = db_result(db_query('SELECT MAX(thread) FROM {comments} WHERE nid = %d', $edit['nid']));

          // Strip the "/" from the end of the thread.
          $max = rtrim($max, '/');

          // Finally, build the thread field for this new comment.
          $thread = int2vancode(vancode2int($max) + 1) .'/';
        }
        else {
          // This is comment with a parent comment: we increase
          // the part of the thread value at the proper depth.

          // Get the parent comment:
          $parent = _comment_load($edit['pid']);

          // Strip the "/" from the end of the parent thread.
          $parent->thread = (string) rtrim((string) $parent->thread, '/');

          // Get the max value in _this_ thread.
          $max = db_result(db_query("SELECT MAX(thread) FROM {comments} WHERE thread LIKE '%s.%%' AND nid = %d", $parent->thread, $edit['nid']));

          if ($max == '') {
            // First child of this parent.
            $thread = $parent->thread .'.'. int2vancode(0) .'/';
          }
          else {
            // Strip the "/" at the end of the thread.
            $max = rtrim($max, '/');

            // We need to get the value at the correct depth.
            $parts = explode('.', $max);
            $parent_depth = count(explode('.', $parent->thread));
            $last = $parts[$parent_depth];

            // Finally, build the thread field for this new comment.
            $thread = $parent->thread .'.'. int2vancode(vancode2int($last) + 1) .'/';
          }
        }

        if (empty($edit['timestamp'])) {
          $edit['timestamp'] = time();
        }

        if ($edit['uid'] === $user->uid && isset($user->name)) { // '===' Need to modify anonymous users as well.
          $edit['name'] = $user->name;
        }

        db_query("INSERT INTO {comments} (nid, pid, uid, subject, comment, format, hostname, timestamp, status, thread, name, mail, homepage) VALUES (%d, %d, %d, '%s', '%s', %d, '%s', %d, %d, '%s', '%s', '%s', '%s')", $edit['nid'], $edit['pid'], $edit['uid'], $edit['subject'], $edit['comment'], $edit['format'], ip_address(), $edit['timestamp'], $edit['status'], $thread, $edit['name'], $edit['mail'], $edit['homepage']);
        $edit['cid'] = db_last_insert_id('comments', 'cid');

        // Tell the other modules a new comment has been submitted.
        comment_invoke_comment($edit, 'insert');

        // Add an entry to the watchdog log.
        watchdog('content', 'Comment: added %subject.', array('%subject' => $edit['subject']), WATCHDOG_NOTICE, l(t('view'), 'node/'. $edit['nid'], array('fragment' => 'comment-'. $edit['cid'])));
      }
      _comment_update_node_statistics($edit['nid']);

      // Clear the cache so an anonymous user can see his comment being added.
      cache_clear_all();

      // Explain the approval queue if necessary, and then
      // redirect the user to the node he's commenting on.
      if ($edit['status'] == COMMENT_NOT_PUBLISHED) {
        drupal_set_message(t('Your comment has been queued for moderation by site administrators and will be published after approval.'));
      }
      else {
        comment_invoke_comment($edit, 'publish');
      }
      return $edit['cid'];
    }
    else {
      return FALSE;
    }
  }
  else {
    watchdog('content', 'Comment: unauthorized comment submitted or comment submitted to a closed post %subject.', array('%subject' => $edit['subject']), WATCHDOG_WARNING);
    drupal_set_message(t('Comment: unauthorized comment submitted or comment submitted to a closed post %subject.', array('%subject' => $edit['subject'])), 'error');
    return FALSE;
  }  
}

/**
 * Custom wrapper for node_delete(). 
 *
 * @param $nid
 *  A node id
 */
function my_node_delete($nid) {
	$node = node_load($nid);

	db_query('DELETE FROM {node} WHERE nid = %d', $node->nid);
	db_query('DELETE FROM {node_revisions} WHERE nid = %d', $node->nid);

	// Call the node-specific callback (if any):
	node_invoke($node, 'delete');
	node_invoke_nodeapi($node, 'delete');

	// Clear the page and block caches.
	cache_clear_all();

	// Remove this node from the search index if needed.
	if (function_exists('search_wipe')) {
		search_wipe($node->nid, 'node');
	}
	watchdog('content', '@type: deleted %title.', array('@type' => $node->type, '%title' => $node->title));
	drupal_set_message(t('@type %title has been deleted.', array('@type' => node_get_types('name', $node), '%title' => $node->title)));
}

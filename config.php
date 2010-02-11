<?php

/**
 * Parse config file and return as array.
 */
function __config() {
  static $configs;
  if (!isset($configs)) {
    $configs = parse_ini_file('config.ini');
  }
  return $configs;
}
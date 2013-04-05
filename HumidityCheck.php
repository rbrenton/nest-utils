<?php
/*
 * Window Condensation Defender for Nest 2nd-Gen
 *
 * Check temperature and adjust humidity level to prevent window condensation.
 * Assumes temperature scale is in Fahrenheit.
 */

require_once('nest.class.php');

// Set timezone
date_default_timezone_set('America/New_York');

// Set Nest username and password.
define('USERNAME', 'yournestemail@example.org');
define('PASSWORD', 'yournestpassword');

// Define thermostat serial
define('THERMOSTAT_SERIAL', null);// Set this if you have more than 1 thermostat

// Define humidity range
define('MAX_HUMIDITY', '40');
define('MIN_HUMIDITY', '10');
define('MAX_TEMP_DELTA', '30');// Set higher/lower for better/worse windows


$nest = new Nest();

try {
  // Retrieve data
  $locations = $nest->getUserLocations();
  $thermostat = $nest->getDeviceInfo(THERMOSTAT_SERIAL);

  // Check temperatures
  $inside = $thermostat->current_state->temperature;
  $outside = $locations[0]->outside_temperature;
  $current = $thermostat->target->humidity;

  // Manage humidifier
  function adjustedHumidity($inside, $outside) {
    $target = MAX_HUMIDITY;// A setting of 40 means Nest will target 40-45% RH
    $delta = $inside - $outside;
    if($delta > MAX_TEMP_DELTA) {
      $a = ceil(($delta - MAX_TEMP_DELTA)/10)*5;// Adjust 5 points per 10 degree delta
      $target -= $a; 
    }

    return max($target, MIN_HUMIDITY);
  }

  // Sanity check
  if(!is_numeric($inside) || !is_numeric($outside))
    throw new Exception("temp(inside)={$inside},temp(outside)={$outside},cur(max_humidity)={$current}");

  // Adjust max humidity level if necessary
  $target = adjustedHumidity($inside, $outside);
  if($target!=$current) {
    echo "temp(inside)={$inside},temp(outside)={$outside},cur(max_humidity)={$current},set(max_humidity)={$target}\n";
    $nest->setHumidity($target, THERMOSTAT_SERIAL);
  }

} catch (Exception $e) {
  echo "Exception: ".$e->getMessage()."\n";
}

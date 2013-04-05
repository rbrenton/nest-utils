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

// Target amount of moisture in the air
define('MAX_DEWPOINT', 50);

// Define allowed humidity range
define('MAX_HUMIDITY', 45);
define('MIN_HUMIDITY', 10);

// Max difference between dewpoint and outside temp
define('MAX_DEWPOINT_DELTA', 0);//lower = safer. depends on construction quality

// Max temp deviation from the lower heat setting
define('MAX_HEATING_DELTA', 3);

// Convert F to C
function tempFtoC($f) {
  $f = (double) $f;
  return round(($f-32.0)/1.8,1);
}
// Convert C to F
function tempCtoF($c) {
  $c = (double) $c;
  return round((1.8*$c)+32.0,1);
}

// Calculate dewpoint in Celcius
function calculateDewpoint($c, $rh) {
  $c = (double) $c;
  $rh = (double) $rh;

  $a = ($c>=0) ? 7.5 : 7.6;
  $b = ($c>=0) ? 237.3 : 240.7;
  $ssp = 6.1078 * pow(10, ($a * $c) / ($b + $c));

  $sp = $rh / 100 * $ssp;
  $v = log($sp / 6.1078, 10);

  return round($b * $v / ($a - $v), 1);
}

// Calculate relative humidity
function calculateHumidity($c, $dp) {   
  $c = (double) $c;
  $dp = (double) $dp;

  $a = ($c>=0) ? 7.5 : 7.6;
  $b = ($c>=0) ? 237.3 : 240.7;
  $tssp = 6.1078 * pow(10, ($a * $c) / ($b + $c));

  $a = ($dp>=0) ? 7.5 : 7.6;
  $b = ($dp>=0) ? 237.3 : 240.7;
  $dssp = 6.1078 * pow(10, ($a * $dp) / ($b + $dp));
  
  $rh = round(100 * $dssp / $tssp, 1);

  return $rh;
}

// Calculate max RH
function adjustedHumidity($inside, $outside, $rh) {
  $target = calculateHumidity(tempFtoC($inside), tempFtoC(MAX_DEWPOINT));
  $target = floor($target/5.0)*5;//round to nearest 5
  $dp = calculateDewpoint(tempFtoC($inside), $target);

  // If the outside temperature is lower than the current inside dewpoint, reduce max humidity
  while($dp - $outside > MAX_DEWPOINT_DELTA && $target > MIN_HUMIDITY) {
    $target -= 5;
    $dp = calculateDewpoint(tempFtoC($inside), $target);
  }

  // Respect MIN/MAX settings
  $target = min($target, MAX_HUMIDITY);
  $target = max($target, MIN_HUMIDITY);

  return $target;
}


$nest = new Nest();

try {
  // Retrieve data
  $locations = $nest->getUserLocations();
  $thermostat = $nest->getDeviceInfo(THERMOSTAT_SERIAL);

  $insideTemp = $thermostat->current_state->temperature;
  $outsideTemp = $locations[0]->outside_temperature;
  $insideHumidity = $thermostat->current_state->humidity;
  $targetHumidityCurrent = $thermostat->target->humidity;

  // Determine heat/cool target temperatures
  $mode = $thermostat->target->mode;
  $cool = $heat = $thermostat->target->temperature;
  if($mode=='range') {
    $heat = $heat[0];
    $cool = $cool[0];
  } else if($mode=='cool') {
    $heat = null;
  } else if($mode=='head') {
    $cool = null;
  } else {
    $cool = $head = null;
  }

  // Sanity check that we have data
  if(!is_numeric($insideTemp) || !is_numeric($outsideTemp) || !is_numeric($targetHumidityCurrent) || !is_numeric($insideHumidity))
    throw new Exception("temp(inside)={$insideTemp},temp(outside)={$outsideTemp},humidity(inside)={$insideHumidity},target(max_humidity)={$targetHumidityCurrent}");

  // Calculate inside dewpoint
  $currentDewpoint = calculateDewpoint(tempFtoC($insideTemp), $insideHumidity);
  $currentDewpoint = tempCtoF($currentDewpoint);

  // Determine target inside humidity level
  $targetHumidityAdjusted = adjustedHumidity($insideTemp, $outsideTemp, $insideHumidity);

  // Sanity check in case the humidifier (e.g. steam) has caused the temperature to rise too much 
  if(is_numeric($heat) && ($insideTemp-$heat) >= MAX_HEATING_DELTA) {
    $targetHumidityAdjusted = MIN_HUMIDITY;
  }
  // Adjust max humidity level if necessary
  if($targetHumidityAdjusted!=$targetHumidityCurrent) {
    echo "temp(inside)={$insideTemp},temp(outside)={$outsideTemp},humidity(inside)={$insideHumidity},dewpoint(inside)={$currentDewpoint},target(max_humidity)={$targetHumidityCurrent},new_target(max_humidity)={$targetHumidityAdjusted}\n";
    $nest->setHumidity($targetHumidityAdjusted, THERMOSTAT_SERIAL);
  }

} catch (Exception $e) {
  echo "Exception: ".$e->getMessage()."\n";
}

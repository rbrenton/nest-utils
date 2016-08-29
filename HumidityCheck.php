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
define('THERMOSTAT_SERIAL', null); // Set this if you have more than 1 thermostat
define('THERMOSTAT2_SERIAL', null); // upstairs thermostat - for sanity checking dewpoint
define('WUNDERGROUND_STATION', ''); // wunderground station - for sanity checking outside temp and dewpoint

// Define humidity range
define('MAX_HUMIDITY_TARGET', 40);
define('MIN_HUMIDITY_TARGET', 10);

// Maximum amount of moisture in the air
define('MAX_DEWPOINT', 46.0);//also the targeted depoint

// Max difference between dewpoint and outside temp
define('MAX_DEWPOINT_DELTA', -10.0);//lower = safer. depends on construction quality

// Max temp deviation from the lower heat setting before disabling humidification
define('MAX_HEATING_DELTA', 1.0);

// Convert F to C
function tempFtoC($f)
{
  $f = (double) $f;
  return round(($f-32.0)/1.8,1);
}

// Convert C to F
function tempCtoF($c)
{
  $c = (double) $c;
  return round((1.8*$c)+32.0,1);
}

// Calculate dewpoint in Celcius
function calculateDewpoint($c, $rh)
{
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
function calculateHumidity($c, $dp)
{
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
function determineTargetHumidity($insideTemperature, $insideDewpoint, $outsideTemperature, $outsideDewpoint=null)
{
  static $inc = 5.0;// Nest humidity setting increment

  if ($insideDewpoint >= MAX_DEWPOINT)
    return MIN_HUMIDITY_TARGET;

  $targetHumidity = calculateHumidity(tempFtoC($insideTemperature), tempFtoC(MAX_DEWPOINT));
  $targetHumidity = floor($targetHumidity/$inc)*$inc;//round to nearest 5

  // If the outside temperature is lower than the current inside dewpoint, reduce max humidity
  $dp = calculateDewpoint(tempFtoC($insideTemperature), $targetHumidity);
  while ($dp - $outsideTemperature > MAX_DEWPOINT_DELTA && $targetHumidity > MIN_HUMIDITY_TARGET) {
    $targetHumidity -= $inc;
    $dp = calculateDewpoint(tempFtoC($insideTemperature), $targetHumidity);
  }

  // Respect MIN/MAX settings
  $targetHumidity = min($targetHumidity, MAX_HUMIDITY_TARGET);
  $targetHumidity = max($targetHumidity, MIN_HUMIDITY_TARGET);

  return $targetHumidity;
}


$nest = new Nest();

try {
  // Retrieve data
  $locations = $nest->getUserLocations();
  $thermostat = $nest->getDeviceInfo(THERMOSTAT_SERIAL);

  $insideTemp = $thermostat->current_state->temperature;
  $insideDewpoint = null;
  $insideHumidity = $thermostat->current_state->humidity;
  $targetHumidityCurrent = $thermostat->target->humidity;

  // Calculate inside dewpoint
  if (is_numeric($insideTemp) && is_numeric($insideHumidity)) {
    $insideDewpoint = calculateDewpoint(tempFtoC($insideTemp), $insideHumidity);
    $insideDewpoint = tempCtoF($insideDewpoint);
  }

  $outsideTemp = $locations[0]->outside_temperature;
  $outsideDewpoint = null;
  $outsideHumidity = null;

  // Retrieve wunderground data
  if (WUNDERGROUND_STATION != '') {
    if ($wuXML = file_get_contents('http://api.wunderground.com/weatherstation/WXCurrentObXML.asp?ID='.WUNDERGROUND_STATION)) {
      if (preg_match(';<observation_time_rfc822>(.*?)</observation_time_rfc822>;mi', $wuXML, $regs)) {
        $observationTimeRFC822 = $regs[1];
        $observationsTS = strtotime($observationTimeRFC822);

        if (time() - $observationsTS <= 3600) { // Only accept observations younger than 1 hour
          if (preg_match(';<temp_f>([0-9.]+)</temp_f>;mi', $wuXML, $regs))
            $outsideTemp = (double) $regs[1];
          if (preg_match(';<dewpoint_f>([0-9.]+)</dewpoint_f>;mi', $wuXML, $regs))
            $outsideDewpoint = (double) $regs[1];
          if (preg_match(';<relative_humidity>([0-9.]+)</relative_humidity>;mi', $wuXML, $regs))
            $outsideHumidity = (double) $regs[1];
        }
      }
    }
  }

  // Determine heat/cool target temperatures
  $mode = $thermostat->target->mode;
  $cool = $heat = $thermostat->target->temperature;
  if ($mode=='range') {
    $heat = $heat[0];
    $cool = $cool[1];
  }
  else if ($mode=='cool') {
    $heat = null;
  }
  else if ($mode=='heat') {
    $cool = null;
  }
  else {
    $cool = $heat = null;
  }

  // Track 2nd thermostat for sanity checks
  $insideTemp2 = null;
  $insideHumidity2 = null;
  $insideDewpoint2 = null;

  // Sanity check upstairs thermostat in case dewpoint there exceeds max dewpoint
  if (THERMOSTAT2_SERIAL!= '' && ($thermostat2 = $nest->getDeviceInfo(THERMOSTAT2_SERIAL))) {
    // Calculate upstairs dewpoint
    $insideTemp2 = $thermostat2->current_state->temperature;
    $insideHumidity2 = $thermostat2->current_state->humidity;
    if (is_numeric($insideTemp2) && is_numeric($insideHumidity2)) {
      $insideDewpoint2 = calculateDewpoint(tempFtoC($insideTemp2), $insideHumidity2);
      $insideDewpoint2 = tempCtoF($insideDewpoint2);
    }
  }

  // For debug output
  $status = "THERMOSTAT(temp={$insideTemp}F, dewpoint={$insideDewpoint}F, humidity={$insideHumidity}%, target={$targetHumidityCurrent}%)";
  if (THERMOSTAT2_SERIAL!='') $status .= " - THERMOSTAT2(temp={$insideTemp2}F, dewpoint={$insideDewpoint2}F, humidity={$insideHumidity2}%)";
  $status .= " - OUTSIDE(temp={$outsideTemp}F, dewpoint={$outsideDewpoint}F, humidity={$outsideHumidity}%)";

  // Check that we have the minimum required data
  if (!is_numeric($insideTemp) || !is_numeric($outsideTemp) || !is_numeric($targetHumidityCurrent) || !is_numeric($insideHumidity))
    throw new Exception("{$status} - DATA_ERROR");




  // Determine target inside humidity level
  $targetHumidityAdjusted = determineTargetHumidity($insideTemp, $insideDewpoint, $outsideTemp, $outsideDewpoint);

  // Sanity check with second thermostat/zone
  if (THERMOSTAT2_SERIAL!='') {
    $targetHumidityAdjusted2 = determineTargetHumidity($insideTemp2, $insideDewpoint2, $outsideTemp, $outsideDewpoint);
    $targetHumidityAdjusted = min($targetHumidityAdjusted, $targetHumidityAdjusted2);
  }

  // Sanity check in case the humidifier (i.e. steam) has caused the temperature to rise too much 
  if (is_numeric($heat) && ($insideTemp-$heat) >= MAX_HEATING_DELTA)
    $targetHumidityAdjusted = MIN_HUMIDITY_TARGET;

  // Adjust max humidity level if necessary
  if ($targetHumidityAdjusted!=$targetHumidityCurrent) {
    echo "{$status} - NEW_TARGET_HUMIDITY={$targetHumidityAdjusted}%\n";
    $nest->setHumidity($targetHumidityAdjusted, THERMOSTAT_SERIAL);
  }
  else {
    echo "{$status} - OK\n";
  }


} catch (Exception $e) {
  echo "Exception: ".$e->getMessage()."\n";
}

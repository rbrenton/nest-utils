#!/bin/bash
# Example script to run HumidityCheck.php in a loop.
# e.g. screen -S nestutils /opt/nest-utils/HumidityCheck.sh
WORKINGDIR=/opt/nest-utils
cd $WORKINGDIR || exit 1

while /bin/true; do
  DATE=`date`
  NEST=`php HumidityCheck.php`
  LINE="$DATE - $NEST"
  echo $LINE
  echo $LINE >> HumidityCheck.log
  sleep 60
done

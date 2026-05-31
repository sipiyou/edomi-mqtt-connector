php compile.php

sed -i \
  -e "s|^ *//require('wrapper.php');|require('wrapper.php');|" \
  -e "s|^ *require(dirname(__FILE__).*incl_lbsexec\.php.*|//&|" \
  19002763_lbs.php

scp 19002763_lbs.php root@edomi.home.local:~/edomi/tests/mqtt/
scp wrapper.php root@edomi.home.local:~/edomi/tests/mqtt/
scp run.sh root@edomi.home.local:~/edomi/tests/mqtt/

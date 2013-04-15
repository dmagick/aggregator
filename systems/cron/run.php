<?php

require dirname(dirname(__FILE__)).'/init.php';

require dirname(__FILE__).'/cron.php';

cron::run();


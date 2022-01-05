# WIKIMONITOR OPERATING INSTRUCTIONS

## Basic Instructions
To operate WikiMonitor, follow the following steps:
* Place it in a directory that...
  * Is dedicated (nothing else is in it)
  * Has write permissions (PHP needs to be able to read/write the file cookies.txt)
* Create the file "`conf/logininfo.php`" (see below)
* Create the file "`conf/wikiinfo.php`" (see below)
* Run the file "`main-new.php`" with the command-line code "php -f main-new.php" (add your PHP directory to the PATH if the command isn't recognized)
* As soon as it says "Login success!", make sure the file "cookies.txt" exists (if it doesn't check the directory permissions)
* Do not close the command shell window (doing so will stop executing the script)
  * You can also use tmux or screens to make it run in the background
* Restart the script every few days

## Configuration Files
The file "`conf/logininfo.php`" should look like this:
```
<?php
$wikiusername = 'WikiMonitor'; //replace with the username you are using
$wikipassword = 'password'; //replace with your password
```

The file "conf/wikiinfo.php" should look like this:
```
<?php
define('CONFIG_LOCATION', 'User:WikiMonitor/Configuration');
define('SHUTOFF_PAGE', 'User:WikiMonitor/Disable');
define('TALK_INDICATOR', 'talk:');
```

The following configuration files can be edited as necessary:
`conf/nobotsoverride.txt` - WM will ignore the {{NoBots}} template for any user on this list
`conf/ignore.txt` - WM will unconditially ignore notifications related to any page on the list 
Place one entry per line in each file, and avoid unnecessary whitespace at the beginning or the end. The list is also case-sensitive and requires underscores, not spaces.

# Credits
The DIFF algorithm used in this program was taken from http://github.com/chrisboulton/php-diff. Please read the copyright notice in lib/diffengine/lib/Diff.php for the relevant copyright details on those files.

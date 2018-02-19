while (true); do
	php -f main-new.php;
	echo 'WikiMonitor has been disabled. Trying again in 30 minutes...';
	sleep 30m;
done

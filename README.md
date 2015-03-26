# Footprints-Slack-Notifications
A cron job to push notifications to Slack using Footprints.
Note:  this is a code snippet and not a ready-to-go application.  
You need to be comfortable with PHP and the Footprints API to make this work.

Example lines for crontab (running every 2 minutes)

PRODUCTION:

*/2 * * * * php ~/crons/fpnotifications.php >> /dev/null

DEBUG:

*/2 * * * * php ~/crons/fpnotifications.php >> ~/cronlog.txt

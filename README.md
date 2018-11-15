# google-cloud-compute-auto-manage-snapshots
A project to take and manage daily snapshots and backups of all your gcp instances.

# Similar projects
* https://github.com/grugnog/google-cloud-auto-snapshot
* https://github.com/jacksegal/google-compute-snapshot



# Function
Take one snapshot of every instance in your project once every day
Then as time goes delete snapshots.
Take backups of the sql intances daily and transfer them away from gcp.

# Cleaning of old snapshots
All snapshots are kept for 7 days. After 7 Days snapshots made on Tuesdays and Fridays are kept the rest gets removed. After 31 days snapshots made on Tuesdays are kept the rest gets removed. After 100 days all the snapshots are cleared. This means that it should always be possible to restore about 90 days back in time without having the full performance penalty that 90 snapshots would give.

# How to use
1. Create a new service account in GCP
1. Create an instance on GCP select the correct service account
1. ssh in to the instance:
1. sudo apt-get install php git
1. edit /etc/fstab make sure that /media/gcp_backups is mounted to a offsite nfs server that has daily snapshots.
1. cd /opt
1. git clone https://github.com/callesg/google-cloud-compute-auto-manage-snapshots
1. crontab -e


create cronjob entrys:
```cronjob
05      03      *       *       6       /usr/bin/php /opt/google-cloud-compute-auto-manage-snapshots/manage_snapshots.php offsite_backup
05      01      *       *       *       /usr/bin/php /opt/google-cloud-compute-auto-manage-snapshots/manage_snapshots.php sql_backup
25      01      *       *       *       /usr/bin/php /opt/google-cloud-compute-auto-manage-snapshots/manage_snapshots.php take
25      05      *       *       *       /usr/bin/php /opt/google-cloud-compute-auto-manage-snapshots/manage_snapshots.php free_old
```

To make sure that the gcloud binary can be reached from the cronjob you might have to add this to your crontab:
```
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/games:/usr/local/games:/snap/bin
```

# Service account
It will use the rights given to the GCP instance.
Create a service account with the project Editor role and attach it to the instance.


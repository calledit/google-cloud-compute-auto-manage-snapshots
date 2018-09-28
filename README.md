# google-cloud-compute-auto-manage-snapshots
A project to take and manage daily snapshots of all your gcp instances.

# Similar projects
* https://github.com/grugnog/google-cloud-auto-snapshot
* https://github.com/jacksegal/google-compute-snapshot



# Function
Take one snapshot of every instance in your project once every day
Then as time goes delete snapshots.

# Cleaning of old snapshots
All snapshots are kept for 7 days. After 7 Days snapshots made on Tuesdays and Fridays are kept the rest gets removed. After 31 days snapshots made on Tuesdays are kept the rest gets removed. After 100 days all the snapshots are cleared. This means that it should always be possible to restore about 90 days back in time without having the full performance penalty that 90 snapshots would give.

# How to use
1. Create an instance on GCP give it read/write access to compute engine
1. ssh in to the instance:
1. sudo apt-get install php git
1. cd /opt
1. git clone https://github.com/callesg/google-cloud-compute-auto-manage-snapshots
1. crontab -e


create cronjob entrys:
```cronjob
25      01      *       *       *       /usr/bin/php /opt/google-cloud-compute-auto-manage-snapshots/manage_snapshots.php take
25      05      *       *       *       /usr/bin/php /opt/google-cloud-compute-auto-manage-snapshots/manage_snapshots.php free_old
```

To make sure that the gcloud banary can be reached from the cronjob you might have to add this to your crontab:
```
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/games:/usr/local/games:/snap/bin
```

It will use the rights given to the GCP instance so no credential configuration is required. 

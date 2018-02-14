# google-cloud-compute-auto-manage-snapshots
A project to take and manage daily snapshots of all your gcp instances

# Function
Take one snapshot of every instance in your project once every day
Then as time goes delete snapshots.

# How to use
1. Create an instance on gcp give it read/write acces to comupte engine
1. ssh in to the instance:
1. cd /opt
1. git clone https://github.com/callesg/google-cloud-compute-auto-manage-snapshots
1. crontab -e 


create an entry:

25      01      *       *       *       /usr/bin/php /opt/google-cloud-compute-auto-manage-snapshots/manage_snapshots.php take


It will use the rights given to the GCP instance so no crediental configuration is required. 

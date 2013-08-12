Task: New S3/Memcached cluster file handler

Today eZ Publish support only the clustered storage via eZDFS(kernel/private/classes/clusterfilehandlers/ezdfsfilehandler.php) the on a NFS Device.

This has following disadvantages:

* It brings a single point of failure to the installation.
* it has a performance bottle neck if you need to write many cache files.

The S3 / Memcached handler

We do need a new handler now that will store files that need a permanent storage under S3 (http://aws.amazon.com/de/s3/). Those files commonly habe the path "*/storage/*". In addtion all files that have a short time of live will do in a memcached storge (http://en.wikipedia.org/wiki/Memcached, http://www.php.net/manual/en/book.memcached.php).

Repository

https://github.com/xrowgmbh/xrowaws



Current Storage Model

eZ Publish

- var/(*/|)cache/* 
	- Mysql ezdfsfile for metadata
    - NFS /mnt/nas/var/cache/* for binary data
- var/(*/|)storage/*
    - Mysql ezdfsfile for metadata
    - NFS /mnt/nas/var/storage/* for binary data

Future Storage model

eZ Publish

- var/(*/|)cache/* 
	- Memcached 
- var/(*/|)storage/*
    - Mysql ezdfsfile for metadata
    - S3 for binary data
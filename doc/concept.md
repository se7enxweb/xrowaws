Task: New S3/Memcached cluster file handler

Today eZ Publish support only the clustered storage via eZDFS(kernel/private/classes/clusterfilehandlers/ezdfsfilehandler.php) on a NFS Device.

This has following disadvantages:

* It brings a single point of failure to the installation.
* it has a performance bottle neck if you need to write many cache files.

The S3 / Memcached handler

We do need a new handler now that will store files that need a permanent storage under S3 (http://aws.amazon.com/de/s3/). Those files commonly habe the path "*/storage/*". In addtion all files that have a short time of live will do in a memcached storge (http://en.wikipedia.org/wiki/Memcached, http://www.php.net/manual/en/book.memcached.php).

Repository

https://github.com/xrowgmbh/xrowaws

Current Storage Model

eZ Publish

* var/(*/|)cache/* 
	* Mysql ezdfsfile for metadata
    * NFS /mnt/nas/var/cache/* for binary data
* var/(*/|)storage/*
    * Mysql ezdfsfile for metadata
    * NFS /mnt/nas/var/storage/* for binary data

Future storage model

eZ Publish

* var/(*/|)cache/* 
	* Memcached 
* var/(*/|)storage/*
    * Mysql ezdfsfile for metadata
    * S3 for binary data

Required Features

* The Memcached implementation should be based on http://stash.tedivm.com and should use the local file system driver as a fallback if the Memcached fails. Don't use the composite driver.
* The S3 implementation may fail if a resource isn't writeable or readable.

Optional features

* Cache objects stored in memcache have the metadata not stored in ezdfsfile
* Supply a prototype of a S3 based IO handler for eZ 5.x (https://github.com/ezsystems/ezpublish-kernel/tree/master/eZ/Publish/Core/IO)

Test cases

* The site should not fail wiht a 503 when the memcache is not reachable instead local storage should be used.
* The test script needs to be written to benchmark the new storage plug with the old one dfs storage plugin.
  The following tests need to be compared.
  *  Store 1000 2 MB Files on Storage, Cache
  *  Delete 1000 2 MB Files on Storage, Cache
  *  Read 1000 2 MB Files on Storage, Cache
  *  Store 1000 2 KB Files on Storage, Cache
  *  Delete 1000 2 KB Files on Storage, Cache
  *  Read 1000 2 KB Files on Storage, Cache
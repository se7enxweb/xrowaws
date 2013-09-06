<?php

//$file1 = "var/cache/test.cache";
$file2 = "var/storage/test1.png";
//$files = array($file1, $file2);
$files = array($file2);

//copy( "extension/xrowaws/test/test.cache" , $file1);
copy( "extension/xrowaws/test/test1.png" , $file2);

echo "Create Files\n";

foreach( $files as $filepath )
{
	echo "Doing $filepath\n";
	if (file_exists( $filepath ) )
	{
		echo "File is on local\n";
	}

	$file = eZClusterFileHandler::instance( $filepath );

	if ( !$file->exists() )
	{
		echo "STORE\n";
		$file->fileStore( $filepath, false, false, false);
	}
	echo "Size: " . $file->size() ."\n";

	if (file_exists( $filepath ) ) 
	{
		unlink($filepath);
	}

	$file->fileFetch( $filepath );

    echo "File Path: $filepath\n";
	
	if (file_exists( $filepath ) )
	{ 
		echo "File exists \n"; 
	}
	
}

echo "Delete Files\n";

/*foreach( $files as $file )
{
	echo "Doing $file\n";
	$file = eZClusterFileHandler::instance( $filepath );
	$file->delete();
}
$cssfile = "var/cache/Public/test.css";*/
//$content = "/* CSS */";
/*$clusterFileHandler = eZClusterFileHandler::instance( $cssfile );

$clusterFileHandler->fileStoreContents( $cssfile, $content, 'ezjscore', 'text/css' );

if ( $clusterFileHandler->fileExists( $cssfile ) )
{
  echo "Css is in Memory\n";
  $pass=$clusterFileHandler->passthrough();
  
  echo $pass;
}
*/


/*$userId = 14;
$cacheFilePath = eZUser::getCacheDir( $userId ). "/user-data-{$userId}.cache.php" ;
$cacheFile = eZClusterFileHandler::instance( $cacheFilePath );
$cacheFile->processCache( array( 'eZUser', 'retrieveUserCacheFromFile' ),
                                         array( 'eZUser', 'generateUserCacheForFile' ),
                                         null,
                                         userInfoExpiry(),
                                         $userId );

function userInfoExpiry()
{*/
	/* Figure out when the last update was done */
	/*eZExpiryHandler::registerShutdownFunction();
	$handler = eZExpiryHandler::instance();
	if ( $handler->hasTimestamp( 'user-info-cache' ) )
	{
		$expiredTimestamp = $handler->timestamp( 'user-info-cache' );
	}
	else
	{
		$expiredTimestamp = time();
		$handler->setTimestamp( 'user-info-cache', $expiredTimestamp );
	}

	return $expiredTimestamp;
}*/
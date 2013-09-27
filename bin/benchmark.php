<?php

use Lavoiesl\PhpBenchmark\Benchmark;

$benchmark = new Benchmark();

$benchmark->setCount( 1000 ); 
mkdir( 'var/cache/test', 0777, true );
mkdir( 'var/storage/test', 0777, true );

/*benchmark for Memcached*/
$filepath = "var/cache/test/test.". microtime() . ".cache";
copy( "extension/xrowaws/test/test.cache", $filepath );

$test = eZClusterFileHandler::instance( $filepath );
$test->fileStore( $filepath, false, false, false );

$benchmark->add( '1# Read Memcache', function ( )
{
    global $filepath;
    $file = eZClusterFileHandler::instance( $filepath );
    $file->fileFetch( $filepath );
} );

$benchmark->add( '2# File Exists Memcache', function ()
{
    global $filepath;
    $file = eZClusterFileHandler::instance( $filepath );
    $file->exists( $filepath );
} );

$benchmark->add( '3# Fetch Metadata Memcache', function ()
{
    global $filepath;
    $file = eZClusterFileHandler::instance( $filepath );
    $file->loadMetaData( );
    $size = $file->size();

    if( empty( $size ) )
    {
        echo "Problem size";
    }
} );

$benchmark->add( '4# Write Memcache', function ()
{
    global $filepath;
    $file = eZClusterFileHandler::instance( $filepath );
    $file->fileStore( $filepath, false, false, false );
} );

/*benchmark for S3*/

$s3file = "var/storage/test/test1.". microtime() . ".png";
copy( "extension/xrowaws/test/test1.png", $s3file );

$test1 = eZClusterFileHandler::instance( $s3file );
$test1->fileStore( $s3file, false, false, false );

$benchmark->add( '5# Read S3', function ( )
{
    global $s3file;
    $file = eZClusterFileHandler::instance( $s3file );
    $file->fileFetch( $s3file );
} );

$benchmark->add( '6# File Exists S3', function ()
{
    global $s3file;
    $file = eZClusterFileHandler::instance( $s3file );
    $file->exists( $s3file );
} );

$benchmark->add( '7# Fetch Metadata S3', function ()
{
    global $s3file;
    $file = eZClusterFileHandler::instance( $s3file );
    $file->loadMetaData();
    $size = $file->size();

    if( empty( $size ) )
    {
        echo "Problem size";
    }
} );

$benchmark->add( '8# Write S3', function ()
{
    global $s3file;
    $file = eZClusterFileHandler::instance( $s3file );
    $file->fileStore( $s3file, false, false, false );
} );


/*$benchmark->add( '5# Create and delete in Memcache', function ()
{
    $file = "var/cache/test/test.". microtime() . ".cache";
    copy( "extension/xrowaws/test/test.cache", $file );
    createanddelete( $file );
} );
 
$benchmark->add( '6# Create and delete in S3', function ()
{
    $file = "var/storage/test/test1.". microtime() . ".png";
    copy( "extension/xrowaws/test/test1.png", $file );
    createanddelete( $file );
} );

$benchmark->add( '7# StoreContents and Passthrough in Memcache', function ()
{
    $cssfile = "var/cache/test/test.". microtime() . ".cache" . microtime();
    $content = "// CSS";
    $file = eZClusterFileHandler::instance( $cssfile );
    
    $file->fileStoreContents( $cssfile, $content, 'ezjscore', 'text/css' );
    
    if ( $file->fileExists( $cssfile ) )
    {
        $file->passthrough();
    }
    $file->delete();
} );

$benchmark->add( '8# StoreContents and Passthrough in S3', function ()
{
    $cssfile = "var/storage/test/test.". microtime() . ".css";
    $content = "// CSS";
    $file = eZClusterFileHandler::instance( $cssfile );
    
    $file->fileStoreContents( $cssfile, $content, 'ezjscore', 'text/css' );
    
    if ( $file->fileExists( $cssfile ) )
    {
        $file->passthrough();
    }
    $file->delete();
} );*/

$benchmark->run();
unlink($filepath);
unlink($s3file);
$test1->delete();
/*function createanddelete( $filepath )
{
    $file = eZClusterFileHandler::instance( $filepath );
    
    $file->fileStore( $filepath, false, false, false );
    
    $file->size();
    
    $file->exists();

    unlink( $filepath );
    
    $file->fileFetch( $filepath );

    $file->delete();
}
*/
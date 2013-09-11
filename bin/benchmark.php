<?php

use Lavoiesl\PhpBenchmark\Benchmark;

$benchmark = new Benchmark();

$benchmark->setCount( 1000 ); 

$benchmark->add( '1# Create and delete in Memcache', function ()
{
    $file = "var/cache/test/test.". time() . ".cache";
    copy( "extension/xrowaws/test/test.cache", $file );
    createanddelete( $file );
} );
 
$benchmark->add( '2# Create and delete in S3', function ()
{
    $file = "var/storage/test/test1.". time() . ".png";
    copy( "extension/xrowaws/test/test1.png", $file );
    createanddelete( $file );
} );


$benchmark->add( '3# StoreContents and Passthrough in Memcache', function ()
{
    $cssfile = "var/cache/test/test.". time() . ".cache" . time();
    $content = "// CSS";
    $file = eZClusterFileHandler::instance( $cssfile );
    
    $file->fileStoreContents( $cssfile, $content, 'ezjscore', 'text/css' );
    
    if ( $file->fileExists( $cssfile ) )
    {
        $file->passthrough();
    }
    $file->delete();
} );

$benchmark->add( '4# StoreContents and Passthrough in S3', function ()
{
    $cssfile = "var/storage/test/test.". time() . ".css";
    $content = "// CSS";
    $file = eZClusterFileHandler::instance( $cssfile );
    
    $file->fileStoreContents( $cssfile, $content, 'ezjscore', 'text/css' );
    
    if ( $file->fileExists( $cssfile ) )
    {
        $file->passthrough();
    }
    $file->delete();
} );

$benchmark->run();

function createanddelete( $filepath )
{
    $file = eZClusterFileHandler::instance( $filepath );
    
    $file->fileStore( $filepath, false, false, false );
    
    $file->size();
    
    $file->exists();

    unlink( $filepath );
    
    $file->fileFetch( $filepath );

    $file->delete();
}

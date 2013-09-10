<?php


$bench = new Benchmark_Iterate;

// run the getDate function 10 times
$bench->run(10, 'test1');
$bench->run(10, 'test2');
$bench->run(10, 'test3');

// print the profiling information
print_r($bench->get());

function test1()
{
    $file = "var/cache/test.cache";
    copy( "extension/xrowaws/test/test.cache" , $file);
    createanddelete( $file1 );
}
function test2()
{
    $file = "var/storage/test1.png";
    copy( "extension/xrowaws/test/test1.png" , $file);
    createanddelete( $file );
}
function createanddelete( $filepath )
{
	$file = eZClusterFileHandler::instance( $filepath );

	$file->fileStore( $filepath, false, false, false);

	$file->size();

	unlink($filepath);

	$file->fileFetch( $filepath );

	$file->delete();
}

function test3()
{
    $cssfile = "var/cache/test.cache";
    $content = "/* CSS */";
    $file = eZClusterFileHandler::instance( $cssfile );

    $file->fileStoreContents( $cssfile, $content, 'ezjscore', 'text/css' );

    if ( $file->fileExists( $cssfile ) )
    {
        $file->passthrough();
    }
    $file->delete();
}
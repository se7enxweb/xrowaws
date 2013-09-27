<?php
/**
 * File containing the eZDFSFileHandlerDFSBackend class.
 *
 * @copyright Copyright (C) 1999-2012 eZ Systems AS. All rights reserved.
 * @license http://ez.no/Resources/Software/Licenses/eZ-Business-Use-License-Agreement-eZ-BUL-Version-2.1 eZ Business Use License Agreement eZ BUL Version 2.1
 * @version 4.7.0
 * @package kernel
 */

use Stash\Driver\Memcache;
use Stash\Driver\FileSystem;
use Stash\Item;
use Stash\Pool;

use Aws\Common\Aws;
use Aws\S3\Exception\S3Exception;
use Aws\Common\Enum\Region;
use Aws\CloudFront\Exception\Exception;
use Aws\S3\S3Client;

class xrowS3MemcachedBackend
{
    public function __construct()
    {

        $mountPointPath = eZINI::instance( 'file.ini' )->variable( 'eZDFSClusteringSettings', 'MountPointPath' );

        if ( substr( $mountPointPath, -1 ) != '/' )
            $mountPointPath = "$mountPointPath/";

        $this->mountPointPath = $mountPointPath;

        $this->filePermissionMask = octdec( eZINI::instance()->variable( 'FileSettings', 'StorageFilePermissions' ) );
        
        //Memcache implement(Stash)

            $memINI = eZINI::instance( 'xrowaws.ini' );
            if($memINI->hasVariable("MemcacheSettings", "Host")
               AND $memINI->hasVariable("MemcacheSettings", "Port"))
            {
                $this->mem_host = $memINI->variable( "MemcacheSettings", "Host" );
                $this->mem_port = $memINI->variable( "MemcacheSettings", "Port" );
                $this->driver = new Memcache(array('servers' => array($this->mem_host, $this->mem_port)));
                $this->pool = new Pool($this->driver);
            }else
            {
                eZDebugSetting::writeDebug( 'Memcache', "Missing INI Variables in configuration block MemcacheSettings." );
            }

        //S3 implement

            $s3ini = eZINI::instance( 'xrowaws.ini' );
            $awskey = $s3ini->variable( 'Settings', 'AWSKey' );
            $secretkey = $s3ini->variable( 'Settings', 'SecretKey' );
            $region = $s3ini->hasVariable( 'Settings', 'AWSRegion' ) ? $s3ini->variable( 'Settings', 'AWSRegion' ) : Region::US_EAST_1 ;
            
            $this->bucket = $s3ini->variable( 'Settings', 'Bucket' );
            
            // Instantiate an S3 client
            $this->s3 = Aws::factory(array('key' => $awskey,
                                           'secret' => $secretkey,
                                           'region' => $region))->get('s3');
    }

    /**
     * Creates a copy of $srcFilePath from DFS to $dstFilePath on DFS
     *
     * @param string $srcFilePath Local source file path
     * @param string $dstFilePath Local destination file path
     */
    public function copyFromDFSToDFS( $srcFilePath, $dstFilePath )
    {
        
        $this->accumulatorStart();

        $srcFilePath = $this->makeDFSPath( $srcFilePath );
        $dstFilePath = $this->makeDFSPath( $dstFilePath );
        
        if(strpos($srcFilePath,'/storage/') !== FALSE )
        {
            $result = $this->s3->getObject(array('Bucket' => $this->bucket,
                                                    'Key' => $srcFilePath));

            
            $contentdata = (string) $result['Body'];
            $ret = $this->createFile( $dstFilePath,$contentdata );
        }else{
            $item_src = $this->pool->getItem($srcFilePath); 
            $item_dst = $this->pool->getItem($dstFilePath);
            $item_dst->lock();
            $item_dst->set($item_src->get($srcFilePath));
            $ret =true;
        }
        
        $this->accumulatorStop();
        
        return $ret;
    }

    /**
     * Copies the DFS file $srcFilePath to FS
     * @param string $srcFilePath Source file path (on DFS)
     * @param string $dstFilePath
     *        Destination file path (on FS). If not specified, $srcFilePath is
     *        used
     * @return bool
     */
    public function copyFromDFS( $srcFilePath, $dstFilePath = false )
    {
        $this->accumulatorStart();

        if ( $dstFilePath === false )
        {
            $dstFilePath = $srcFilePath;
        }

        if(strpos($srcFilePath,'/storage/') !== FALSE )
        {
            $result = $this->s3->getObject(array('Bucket' => $this->bucket,
                                                     'Key' => $srcFilePath));
            
            $contentdata = (string) $result['Body'];
            $ret = $this->createFileOnLFS( $dstFilePath,$contentdata );
        }else{

            $srcFilePath = $this->makeDFSPath( $srcFilePath );
            $item_src = $this->pool->getItem($srcFilePath);

            $ret = $this->createFileOnLFS( $dstFilePath, $item_src->get($srcFilePath) );
        }
        $this->accumulatorStop();

        return $ret;
    }

    /**
     * Copies the local file $filePath to DFS under the same name, or a new name
     * if specified
     *
     * @param string $srcFilePath Local file path to copy from
     * @param string $dstFilePath
     *        Optional path to copy to. If not specified, $srcFilePath is used
     */
    public function copyToDFS( $srcFilePath, $dstFilePath = false )
    { 
        $this->accumulatorStart();

        if ( $dstFilePath === false )
        {
            $dstFilePath = $srcFilePath;
        }
        $dstFilePath = $this->makeDFSPath( $dstFilePath );
        
        if(strpos($dstFilePath,'/storage/') !== FALSE )
        {
            $ret = $this->createFile( $dstFilePath, file_get_contents( $srcFilePath ), true );
        }else{
            $srcFilePath = $this->makeDFSPath( $srcFilePath );
            $item_src = $this->pool->getItem($srcFilePath);
            $item_src->get($srcFilePath);
            
            $item_dst = $this->pool->getItem($dstFilePath);
            if($item_src->isMiss())
            {
                $item_src->lock();
                $item_src->set(file_get_contents( $srcFilePath ));
                $item_dst->lock();
                $item_dst->set(file_get_contents( $srcFilePath ));
                $ret=true;
            }else
            {
                $item_dst->lock();
                $item_dst->set($item_src->get($srcFilePath));
                $ret=true;
            }
        }
        
        $this->accumulatorStop();

        return $ret;
    }

    /**
     * Deletes one or more files from DFS
     *
     * @param string|array $filePath
     *        Single local filename, or array of local filenames
     * @return bool true if deletion was successful, false otherwise
     * @todo Improve error handling using exceptions
     */
    public function delete( $filePath )
    { 
        $this->accumulatorStart();
        
        if ( is_array( $filePath ) )
        {
            foreach( $filePath as $file )
            {
                if(strpos($file,'/storage/') !== FALSE )
                {   
                    try
                    {
                        $this->s3->deleteObject(array('Bucket' => $this->bucket,
                                                      'Key' => $file ));
                        $ret = true;
                    }catch(S3Exception $e)
                    {
                        $ret = false;
                        echo "There was an error deletting the object.$file\n";
                    }
                }
                else
                {
                    $item = $this->pool->getItem($file);
                    $item->clear();

                    $dfsPath = $this->makeDFSPath( $file );
                    $ret = @unlink( $dfsPath );

                    if ( $ret )
                        eZClusterFileHandler::cleanupEmptyDirectories( $dfsPath );
                }
            }
        }
        else
        {
            if(strpos($filePath,'/storage/') !== FALSE )
            {
                try
                {
                    $this->s3->deleteObject(array('Bucket' => $this->bucket,
                                                  'Key' => $filePath));
                    $ret=true;
                }catch(S3Exception $e)
                {
                    $ret=false;
                    echo "There was an error deletting the object.$file\n";
                }
            }
            else
            {
                $item = $this->pool->getItem($filePath);
                $item->clear();

                $dfsPath = $this->makeDFSPath( $filePath );
                $ret = @unlink( $dfsPath );

                if ( $ret )
                    eZClusterFileHandler::cleanupEmptyDirectories( $dfsPath );
            }
        }
        
        $this->accumulatorStop();

        return $ret;
    }

    /**
     * Sends the contents of $filePath to default output
     *
     * @param string $filePath File path
     * @param int $startOffset Starting offset
     * @param false|int $length Length to transmit, false means everything
     * @return bool true, or false if operation failed
     */
    public function passthrough( $filePath, $startOffset = 0, $length = false )
    { 
        $file = $this->makeDFSPath( $filePath );
        $range= 'bytes='.$startOffset.'-'.$length;
        if(strpos($file,'/storage/') !== FALSE )
        {
            if($length !== false)
            {
                try
                {
                    $result = $this->s3->getObject(array('Bucket' => $this->bucket, 
                                                         'Key' => $file,
                                                         'Range'  => $range));
                }catch(S3Exception $e)
                {
                    echo "There was an error getting the object.$file\n";
                }
            }else{
                try
                {
                    $result = $this->s3->getObject(array('Bucket' => $this->bucket, 
                                                            'Key' => $file));
                }catch(S3Exception $e)
                {
                    echo "There was an error getting the object.$file\n";
                }
            }

            if(isset($result))
            { 
                $contentdata = (string) $result['Body'];
                echo $contentdata;
                return true;
            }else{
                return false;
            }
        }
        else
        {
            $item = $this->pool->getItem($file);
            $item->get($file);
            if($item->isMiss())
            {
                if ( !file_exists( $file ) )
                {
                    eZDebug::writeError( "'$file' does not exist", __METHOD__ );
                    return false;
                }
                if ( ( $fp = fopen( $file, 'rb' ) ) === false )
                {
                    eZDebug::writeError( "An error occured opening '$file' for reading", __METHOD__ );
                    return false;
                }
                
                $fileSize = filesize( $file );
                
                // an offset has been given: move the pointer to that offset if it seems valid
                if ( $startOffset !== false && $startOffset <= $fileSize && fseek( $fp, $startOffset ) === -1 )
                {
                    eZDebug::writeError( "Error while setting offset on '{$file}'", __METHOD__ );
                    return false;
                }
                
                $transferred = $startOffset;
                $packetSize = self::READ_PACKET_SIZE;
                $endOffset = ( $length === false ) ? $fileSize - 1 : $length + $startOffset - 1;
                
                $item = $this->pool->getItem($file);

                while ( !feof( $fp ) && $transferred < $endOffset + 1 )
                {
                    if ( $transferred + $packetSize > $endOffset + 1 )
                    {
                        $packetSize = $endOffset + 1 - $transferred;
                    }
                    echo fread( $fp, $packetSize );
                    
                    $item->lock();
                    $item->set(fread( $fp, $packetSize ));

                    $transferred += $packetSize;
                }
                fclose( $fp );
                return true;
            }else
            {
                $item = $this->pool->getItem($file);
                echo $item->get($file);
                return true;
            }
        }
    }
    /**
     * Returns the binary content of $filePath from DFS
     *
     * @param string $filePath local file path
     * @return binary|bool file's content, or false
     * @todo Handle errors using exceptions
     */
    public function getContents( $filePath )
    {
        if(strpos($filePath,'/storage/') !== FALSE )
        {
            $result = $this->s3->getObject(array('Bucket' => $this->bucket,
                                                     'Key' => $filePath));
            $ret = (string) $result['Body'];
        }
        else
        {
            $this->accumulatorStart();

            $item = $this->pool->getItem($filePath);
            $item->get($filePath);
            
            if($item->isMiss())
            {
                $item->lock();
                $item->set(@file_get_contents( $this->makeDFSPath( $filePath ) ));
                $ret = @file_get_contents( $this->makeDFSPath( $filePath ) );
            }else
            {
                $ret = $item->get($filePath);
            }
            
            $this->accumulatorStop();
         }

         return $ret;
    }

    /**
     * Creates the file $filePath on DFS with content $contents
     *
     * @param string $filePath
     * @param binary $contents
     *
     * @return bool
     */
    public function createFileOnDFS( $filePath, $contents )
    {
        $this->accumulatorStart();
        $filePath = $this->makeDFSPath( $filePath );
        $ret = $this->createFile( $filePath, $contents, false );
        $this->accumulatorStop();
        return $ret;
    }

    /**
     * Renamed DFS file $oldPath to DFS file $newPath
     *
     * @param string $oldPath
     * @param string $newPath
     * @return bool
     */
    public function renameOnDFS( $oldPath, $newPath )
    {
        $this->accumulatorStart();

        $oldPath = $this->makeDFSPath( $oldPath );
        $newPath = $this->makeDFSPath( $newPath );

        if(strpos($oldPath,'/storage/') !== FALSE )
        {
            try
            {
                $result = $this->s3->getObject(array('Bucket' => $this->bucket,
                                                       'Key' => $oldPath));
                $contents=(string) $result['Body'];
                
                $this->s3->putObject(array('Bucket' => $this->bucket,
                                           'Key' => $newPath,
                                           'Body' => $contents,
                                           'ACL' => 'public-read'));
                
                $this->s3->deleteObject(array('Bucket' => $this->bucket,
                                              'Key' => $oldPath ));
                $ret = true;
            }catch(S3Exception $e){
                $ret =false;
            }
        }
        else
        {
            $item_old = $this->pool->getItem($oldPath);
            $item_new = $this->pool->getItem($newPath);
        
            $item_new->lock();
            $item_new->set($item_old->get($oldPath));
            $item_old->clear();

            $ret = eZFile::rename( $oldPath, $newPath, true );

            if ( $ret )
            eZClusterFileHandler::cleanupEmptyDirectories( $oldPath );

           // $ret = true;
        }
        
        $this->accumulatorStop();

        return $ret;
    }

    /**
     * Checks if a file exists on the DFS
     *
     * @param string $filePath
     * @return bool
     */
    public function existsOnDFS( $filePath )
    {
        if(strpos($filePath,'/storage/') !== FALSE )
        {
            $s3result= $this->s3->doesObjectExist( $this->bucket, $filePath);

            if($s3result)
            {
                return true;
            }
            else{
                return false;
            }
        }else
        {
            $item = $this->pool->getItem($filePath);
            $item->get($filePath);
            if($item->isMiss())
            {
                return file_exists( $this->makeDFSPath( $filePath ) );
            }
            else
            {
                return true;
            }
        }
    }

    /**
     * Returns the mount point
     *
     * @return string
     */
    public function getMountPoint()
    {
        return $this->mountPointPath;
    }

    /**
     * Computes the DFS file path based on a relative file path
     * @param string $filePath
     * @return string the absolute DFS file path
     */
    protected function makeDFSPath( $filePath )
    {
        return $filePath;
    }

    protected function accumulatorStart()
    {
        eZDebug::accumulatorStart( 's3memcache_cluster_operations', 'S3/Memcache Cluster', 'S3/Memcache operations' );
    }

    protected function accumulatorStop()
    {
        eZDebug::accumulatorStop( 's3memcache_cluster_operations' );
    }

    protected function fixPermissions( $filePath )
    {
        chmod( $filePath, $this->filePermissionMask );
    }

    protected function createFile( $filePath, $contents, $atomic = true )
    {
        if(strpos($filePath,'/storage/') !== FALSE )
        {
            try{
                $this->s3->putObject(array(
                    'Bucket' => $this->bucket,
                    'Key' => $filePath,
                    'Body' => $contents,
                    'ACL' => 'public-read'));
                
                $createResult = true;
            }catch(S3Exception $e){
                eZDebug::writeError( "There was an error uploading the file. " );
                $createResult = false;
            }
        }else{
            $createResult = eZFile::create( basename( $filePath ), dirname( $filePath ), $contents, $atomic );
            
            $item = $this->pool->getItem($filePath);
            $item->lock();
            $item->set($contents);
            
            if ( $createResult )
                $this->fixPermissions( $filePath );
        }
        
        return $createResult;
    }

    protected function createFileOnLFS( $filePath, $contents, $atomic = true )
    {
        $createResult = eZFile::create( basename( $filePath ), dirname( $filePath ), $contents, $atomic );
        
        if ( $createResult )
            $this->fixPermissions( $filePath );
    
        return $createResult;
    }
    /**
     * Path to the local distributed filesystem mount point
     * @var string
     */
    protected $mountPointPath;

    /**
     * Permission mask that must be applied to created files
     * @var int
     */
    
    private $filePermissionMask;
    
    const READ_PACKET_SIZE = 16384;
}
?>

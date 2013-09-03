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

        if ( !$mountPointPath = realpath( $mountPointPath ) )
            throw new eZDFSFileHandlerNFSMountPointNotFoundException( $mountPointPath );

        if ( !is_writeable( $mountPointPath ) )
            throw new eZDFSFileHandlerNFSMountPointNotWriteableException( $mountPointPath );

        if ( substr( $mountPointPath, -1 ) != '/' )
            $mountPointPath = "$mountPointPath/";

        $this->mountPointPath = $mountPointPath;

        $this->filePermissionMask = octdec( eZINI::instance()->variable( 'FileSettings', 'StorageFilePermissions' ) );
        
        //Memcache implement(Stash)
       try {
            $memINI = eZINI::instance( 'xrowaws.ini' );
            if($memINI->hasVariable("MemcacheSettings", "memhost")
               AND $memINI->hasVariable("MemcacheSettings", "memport"))
            {
                $this->mem_host = $memINI->variable( "MemcacheSettings", "memhost" );
                $this->mem_port = $memINI->variable( "MemcacheSettings", "memport" );
                $this->driver = new Memcache(array('servers' => array($this->mem_host, $this->mem_port)));
                $this->pool = new Pool($this->driver);
            }else
            {
                eZDebugSetting::writeDebug( 'Memcache', "Missing INI Variables in configuration block MemcacheSettings." );
            }
        }catch(Exception $e){
            eZDebugSetting::writeDebug( 'Memcache', "dfs::ctor('$e')" );
        }
        
        //S3 implement
        try {
            $s3ini = eZINI::instance( 'xrowaws.ini' );
            $awskey = $s3ini->variable( 'Settings', 'AWSKey' );
            $secretkey = $s3ini->variable( 'Settings', 'SecretKey' );
            $this->bucket = $s3ini->variable( 'Settings', 'Bucket' );
            
            // Instantiate an S3 client
            $this->s3 = Aws::factory(array('key' => $awskey,
                                           'secret' => $secretkey,
                                           'region' => Region::US_EAST_1))->get('s3');
        }catch(Exception $e){
            eZDebugSetting::writeDebug( 'Amazon S3', "dfs::ctor('$e')" );
            }
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
        if ( file_exists( dirname( $dstFilePath ) ) )
        {
            $ret = copy( $srcFilePath, $dstFilePath );
            if ( $ret )
                $this->fixPermissions( $dstFilePath );
        }
        else
        {
            $ret = $this->createFile( $dstFilePath, file_get_contents( $srcFilePath ), false );
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
        $srcFilePath = $this->makeDFSPath( $srcFilePath );

        if ( file_exists( dirname( $dstFilePath ) ) )
        {
            $ret = copy( $srcFilePath, $dstFilePath );
            if ( $ret )
                $this->fixPermissions( $dstFilePath );
        }
        else
        {
            $ret = $this->createFile( $dstFilePath, file_get_contents( $srcFilePath ) );
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
        $ret = $this->createFile( $dstFilePath, file_get_contents( $srcFilePath ), true );

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
            $ret = true;
            foreach( $filePath as $file )
            {
                $dfsPath = $this->makeDFSPath( $file );
                $locRet = @unlink( $dfsPath );
                $ret = $ret and $locRet;

                if ( $locRet )
                    eZClusterFileHandler::cleanupEmptyDirectories( $dfsPath );
            }
        }
        else
        {
            $dfsPath = $this->makeDFSPath( $filePath );
            $ret = @unlink( $dfsPath );

            if ( $ret )
                eZClusterFileHandler::cleanupEmptyDirectories( $dfsPath );
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
        return eZFile::downloadContent(
            $this->makeDFSPath( $filePath ),
            $startOffset,
            $length
        );
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
        $this->accumulatorStart();
        $filePath_key=$this->makeDFSPath( $filePath );
        // @todo Throw an exception if it fails
        //       (FileNotFound, or FileNotReadable, depends on testing)

        $item = $this->pool->getItem($filePath_key);
        $item->get($filePath_key);
        
        if($item->isMiss())
        {
            $ret = @file_get_contents( $filePath_key );
            $item->lock();
            $item->set($ret);
        }else{
            $ret = @file_get_contents( $filePath_key );
            $item->lock();
            $item->set($ret);
        }

        $this->accumulatorStop();
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
        
        $item = $this->pool->getItem($filePath);
        $item->get($filePath);
        
        if($item->isMiss())
        {
            $ret = $this->createFile( $filePath, $contents, false );
            eZLog::write('CreateFile: ' . $filePath, 'memcache_createFileOnDFS.log');
            
            $item->lock();
            $item->set($contents);
        }else{
            $ret=true;
            $item->lock();
            $item->set($contents);
        }

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

        $ret = eZFile::rename( $oldPath, $newPath, true );

        if ( $ret )
            eZClusterFileHandler::cleanupEmptyDirectories( $oldPath );

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
        if(strpos($filePath,'storage') != FALSE )
        {
            return $this->s3->doesObjectExist(array( 'Bucket' => $this->bucket,
                                                     'Key' => $filePath )); 
        }else
        {
            return file_exists( $this->makeDFSPath( $filePath ) );
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
        if(strpos($filePath,'/storage/') !== FALSE )
        {
            return $filePath;
        }else
        {
            return $this->mountPointPath . $filePath;
        }
    }

    protected function accumulatorStart()
    {
        eZDebug::accumulatorStart( 'mysql_cluster_dfs_operations', 'MySQL Cluster', 'DFS operations' );
    }

    protected function accumulatorStop()
    {
        eZDebug::accumulatorStop( 'mysql_cluster_dfs_operations' );
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
                    'ACL' => 'public-read',
                ));
                
                $createResult = true;
            }catch(S3Exception $e){
                echo "There was an error uploading the file.\n";
            }
        }else{
            $item = $this->pool->getItem($filePath);
            $item->get($filePath);
            
            if($item->isMiss())
            {
                $createResult = eZFile::create( basename( $filePath ), dirname( $filePath ), $contents, $atomic );

                if ( $createResult )
                    $this->fixPermissions( $filePath );
                
                $item->lock();
                $item->set($contents);
            }else{
                $item->lock();
                $item->set($contents);
                $createResult = true;
            }
        }
        
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
}
?>

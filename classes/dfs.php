<?php
/**
 * File containing the eZDFSFileHandlerDFSBackend class.
 *
 * @copyright Copyright (C) 1999-2013 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 * @package kernel
 */
use Aws\Common\Aws;
use Aws\S3\Exception\S3Exception;
use Aws\Common\Enum\Region;
use Aws\CloudFront\Exception\Exception;

class xrowS3FileHandlerDFSBackend
{
    public function __construct()
    {
        $mountPointPath = eZINI::instance( 'file.ini' )->variable( 'eZDFSClusteringSettings', 'MountPointPath' );

        if ( substr( $mountPointPath, -1 ) != '/' )
            $mountPointPath = "$mountPointPath/";

        $this->mountPointPath = $mountPointPath;

        $this->filePermissionMask = octdec( eZINI::instance()->variable( 'FileSettings', 'StorageFilePermissions' ) );

        $ini = eZINI::instance( 'xrowaws.ini' );
        $awskey = $ini->variable( 'Settings', 'AWSKey' );
        $secretkey = $ini->variable( 'Settings', 'SecretKey' );
        $this->bucket = $ini->variable( 'Settings', 'Bucket' );

        
        // Instantiate an S3 client
        $this->s3 = Aws::factory(
array(
  'key'    => $awskey,
  'secret' => $secretkey,
  'region' => Region::US_EAST_1
)

)->get('s3');
        
        // Upload a publicly accessible file. The file size, file type, and MD5 hash are automatically calculated by the SDK
       /*

         try {
            $s3->putObject(array(
            'Bucket' => 'my-bucket',
            'Key'    => 'my-object',
            'Body'   => fopen('/path/to/file', 'r'),
            'ACL'    => 'public-read',
            ));
        } catch (S3Exception $e) {
            echo "There was an error uploading the file.\n";
        }
        
        
        try {
    $s3->upload('my-bucket', 'my-object', fopen('/path/to/file', 'r'), 'public-read');
} catch (S3Exception $e) {
    echo "There was an error uploading the file.\n";
}
*/
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
     * @param bool|string $dstFilePath
     *        Optional path to copy to. If not specified, $srcFilePath is used
     * @return bool
     */
    public function copyToDFS( $srcFilePath, $dstFilePath = false )
    {
        $this->accumulatorStart();

        $srcFileContents = file_get_contents( $srcFilePath );
        if ( $srcFileContents === false )
        {
            $this->accumulatorStop();
            eZDebug::writeError( "Error getting contents of file FS://'$srcFilePath'.", __METHOD__ );
            return false;
        }

        if ( $dstFilePath === false )
        {
            $dstFilePath = $srcFilePath;
        }

        $dstFilePath = $this->makeDFSPath( $dstFilePath );
        $ret = $this->createFile( $dstFilePath, $srcFileContents, true );


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
                $this->s3->deleteObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $file
            ));
            }
        }
        else
        {
            $this->s3->deleteObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $filePath
            ));
        }

        $this->accumulatorStop();

        return true;
    }

    /**
     * Sends the contents of $filePath to default output
     *
     * @param string $filePath File path
     * @param int $startOffset Starting offset
     * @param bool|int $length Length to transmit, false means everything
     * @return bool true, or false if operation failed
     */
    public function passthrough( $filePath, $startOffset = 0, $length = false )
    {
        throw new Exception("Function not implemented");
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
        
        $result = $this->s3->getObject(array(
        'Bucket' => $this->bucket,
        'Key'    => $filePath
        ));
        
        $ret = (string) $result['Body'];

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

        $ret = eZFile::rename( $oldPath, $newPath, true );

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
        return $this->s3->doesObjectExist(array(
        'Bucket' => $this->bucket,
        'Key'    => $filePath
        ));
    }

    /**
     * Returns the mount point
     *
     * @return string
     */
    public function getMountPoint()
    {
        throw new Exception("Function not used");
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

        // $contents can result from a failed file_get_contents(). In this case
        if ( $contents === false )
            return false;

            $this->s3->putObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $filePath,
            'Body'   => $contents,
            'ACL'    => 'public-read',
            ));

        return true;
    }

    /**
     * Returns size of a file in the DFS backend, from a relative path.
     *
     * @param string $filePath The relative file path we want to get size of
     * @return int
     */
    public function getDfsFileSize( $filePath )
    {
        return filesize( $this->makeDFSPath( $filePath ) );
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
    
    private $s3;
}
?>

<?php
use Stash\Driver\Memcache;
use Stash\Item;
use Stash\Pool;

use Aws\Common\Aws;
use Aws\S3\Exception\S3Exception;
use Aws\Common\Enum\Region;
use Aws\CloudFront\Exception\Exception;
use Aws\S3\S3Client;

class xrowS3MemcachedClusterGateway extends ezpClusterGateway
{
    protected $port = 3306;

    public function connect()
    {
        if ( !$this->db = mysqli_connect( $this->host, $this->user, $this->password, $this->name, $this->port ) )
            throw new RuntimeException( "Failed connecting to the MySQL database " .
                "(error #". mysqli_errno( $this->db ).": " . mysqli_error( $this->db ) );

        if ( !mysqli_set_charset( $this->db, $this->charset ) )
            throw new RuntimeException( "Failed to set database charset to '$this->charset' " .
                "(error #". mysqli_errno( $this->db ).": " . mysqli_error( $this->db ) );
    }

    public function fetchFileMetadata( $filepath )
    {
        $filePathHash = md5( $filepath );
        $sql = "SELECT * FROM ezdfsfile WHERE name_hash='$filePathHash'" ;
        
        $memINI = eZINI::instance( 'xrowaws.ini' );
        
        if($memINI->hasVariable("MemcacheSettings", "Host") AND $memINI->hasVariable("MemcacheSettings", "Port"))
        {
            $this->mem_host = $memINI->variable( "MemcacheSettings", "Host" );
            $this->mem_port = $memINI->variable( "MemcacheSettings", "Port" );
            $driver = new Memcache(array('servers' => array($this->mem_host, $this->mem_port)));
            $pool = new Pool($driver);
        }
        else
        {
            eZDebugSetting::writeDebug( 'Memcache', "Missing INI Variables in configuration block MemcacheSettings." );
        }
        
        $filePath_key = $filepath . "metadata";
        $item = $pool->getItem($filePath_key);
        $item->get($filePath_key);

        if($item->isMiss())
        {
            $item->lock();
            
            if ( !$res = mysqli_query( $this->db, $sql ) )
                throw new RuntimeException( "Failed to fetch file metadata for '$filepath' " . "(error #". mysqli_errno( $this->db ).": " . mysqli_error( $this->db ) );
    
            if ( mysqli_num_rows( $res ) == 0 )
            {
                return false;
            }
    
            $metadata = mysqli_fetch_assoc( $res );
            
            $item->lock();
            
            $item->set($metadata);
            
            mysqli_free_result( $res );
        }else
        {
            $metadata=$item->get($filePath_key);
        }
        
        return $metadata;
    }

    public function passthrough( $filepath, $filesize, $offset = false, $length = false )
    {
        $dfsFilePath = $filepath; 

        $memINI = eZINI::instance( 'xrowaws.ini' );

        if($memINI->hasVariable("MemcacheSettings", "Host") AND $memINI->hasVariable("MemcacheSettings", "Port"))
        {
            $this->mem_host = $memINI->variable( "MemcacheSettings", "Host" );
            $this->mem_port = $memINI->variable( "MemcacheSettings", "Port" );
            $driver = new Memcache(array('servers' => array($this->mem_host, $this->mem_port)));
            $pool = new Pool($driver);
        }
        else
        {
            eZDebugSetting::writeDebug( 'Memcache', "Missing INI Variables in configuration block MemcacheSettings." );
        }
        
        //S3 implement
        try 
        {
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
        
        if(strpos($dfsFilePath,'/storage/') !== FALSE )
        {
            try
            {
                $result = $this->s3->getObject(array('Bucket' => $this->bucket,
                                                     'Key' => $dfsFilePath));
            }catch(S3Exception $e)
            {
                echo "There was an error getting the object.$dfsFilePath\n";
            }
        
            $contentdata = (string) $result['Body'];
            echo $contentdata;
        }
        else
        {
            $item = $pool->getItem($dfsFilePath);
            
            if($item->isMiss())
            {
                if ( !file_exists( $dfsFilePath ) )
                    throw new RuntimeException( "Unable to open DFS file '$dfsFilePath'" );
    
                $fp = fopen( $dfsFilePath, 'rb' );
                
                if ( $offset !== false && @fseek( $fp, $offset ) === -1 )
                    throw new RuntimeException( "Failed to seek offset $offset on file '$filepath'" );
                
                if ( $offset === false && $length === false )
                {    
                    fpassthru( $fp );
                }
                else
                {   $item->lock();
                    $item->set(fread( $fp, $length ));
                    echo fread( $fp, $length );
                }
                
                fclose( $fp );
            }
            else
            {
                echo $item->get($dfsFilePath);
            }
        }
    }

    public function close()
    {
        mysqli_close( $this->db );
        unset( $this->db );
    }
}

ezpClusterGateway::setGatewayClass( 'xrowS3MemcachedClusterGateway' );

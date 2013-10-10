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

        //Memcached implement
        $memINI = eZINI::instance( 'xrowaws.ini' );
        $mem_host = $memINI->variable( "MemcacheSettings", "Host" );
        if($memINI->hasVariable("MemcacheSettings", "Host") && $memINI->hasVariable("MemcacheSettings", "Port") && !empty($mem_host))
        {
            $this->mem_host = $memINI->variable( "MemcacheSettings", "Host" );
            $this->mem_port = $memINI->variable( "MemcacheSettings", "Port" );
            $this->driver = new Memcache(array('servers' => array($this->mem_host, $this->mem_port)));
            $this->pool = new Pool($this->driver);
        }else{
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

    public function fetchFileMetadata( $filepath )
    {
        $filePath_key = $filepath . "metadata";
        $item = $this->pool->getItem($filePath_key);
        $item->get($filePath_key);

        if($item->isMiss())
        {
            $filePathHash = md5( $filepath );
            $sql = "SELECT * FROM ezdfsfile WHERE name_hash='$filePathHash'" ;

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

        if(strpos($dfsFilePath,'/storage/') !== FALSE )
        {
            if($length !== false)
            {
                $range= 'bytes='.$offset.'-'.$length;
                try
                {
                    $result = $this->s3->getObject(array('Bucket' => $this->bucket,
                                                         'Key' => $dfsFilePath,
                                                         'Range' => $range));
                }catch(S3Exception $e)
                {
                    throw new RuntimeException( "There was an error getting the object.$dfsFilePath\n" );
                }
            }else{
                try
                {
                    $result = $this->s3->getObject(array('Bucket' => $this->bucket,
                                                         'Key' => $dfsFilePath));
                }catch(S3Exception $e)
                {
                    throw new RuntimeException( "There was an error getting the object.$dfsFilePath\n" );
                }
            }
        
            $contentdata = (string) $result['Body'];
            echo $contentdata;
        }
        else
        {
            $item = $this->pool->getItem($dfsFilePath);
            $datainfo=$item->get($dfsFilePath);

            if($item->isMiss())
            {
                throw new RuntimeException( "There was an error getting the object.$dfsFilePath\n" );
            }
            else
            {
                if ( $length === false )
                { 
                    echo $datainfo;
                }
                else
                {
                   if ( !file_exists( $dfsFilePath ) )
                   {
                        $tmp=eZFile::create( basename( $dfsFilePath ), dirname( $dfsFilePath ), $datainfo, $atomic );
                        if($tmp)
                        {
                           $fp = fopen( $dfsFilePath, 'rb' );

                           if ( $offset !== false && @fseek( $fp, $offset ) === -1 )
                                throw new RuntimeException( "Failed to seek offset $offset on file '$filepath'" );
                           echo fread( $fp, $length );
                        }else{
                            throw new RuntimeException( "Unable to open DFS file '$dfsFilePath'" );
                        }
                   }else
                   {
                       $fp = fopen( $dfsFilePath, 'rb' );
        
                      if ( $offset !== false && @fseek( $fp, $offset ) === -1 )
                          throw new RuntimeException( "Failed to seek offset $offset on file '$filepath'" );
        
                      if ( $offset === false && $length === false )
                          fpassthru( $fp );
                      else
                          echo fread( $fp, $length );
                   }
                
                   fclose( $fp );
                }
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
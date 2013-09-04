<?php
use Stash\Driver\Memcache;
use Stash\Item;
use Stash\Pool;

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
        if($memINI->hasVariable("MemcacheSettings", "Host")
           AND $memINI->hasVariable("MemcacheSettings", "Port"))
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
                throw new RuntimeException( "Failed to fetch file metadata for '$filepath' " .
                    "(error #". mysqli_errno( $this->db ).": " . mysqli_error( $this->db ) );
    
            if ( mysqli_num_rows( $res ) == 0 )
            {
                return false;
            }
    
            $metadata = mysqli_fetch_assoc( $res );
            
            $item->set($metadata);
            
            mysqli_free_result( $res );
        }else{
            $metadata=$item->get($filePath_key);
        }
        
        return $metadata;
    }

    public function passthrough( $filepath, $filesize, $offset = false, $length = false )
    {
        $dfsFilePath = CLUSTER_MOUNT_POINT_PATH . '/' . $filepath;
           
        $memINI = eZINI::instance( 'xrowaws.ini' );
        if($memINI->hasVariable("MemcacheSettings", "Host")
           AND $memINI->hasVariable("MemcacheSettings", "Port"))
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
        
        $item = $pool->getItem($dfsFilePath);
        $item->get($dfsFilePath);
        
        if($item->isMiss())
        {
        
	        if ( !file_exists( $dfsFilePath ) )
	            throw new RuntimeException( "Unable to open DFS file '$dfsFilePath'" );
	
	        $fp = fopen( $dfsFilePath, 'rb' );
	        if ( $offset !== false && @fseek( $fp, $offset ) === -1 )
	            throw new RuntimeException( "Failed to seek offset $offset on file '$filepath'" );
	        if ( $offset === false && $length === false )
	        {    fpassthru( $fp );
	            $item->lock();
	            $item->set(fpassthru( $fp ));
	        }
	        else
	        {   $item->lock();
	            $item->set(fread( $fp, $length ));
	            echo fread( $fp, $length );
	        }
	        fclose( $fp );
	        
	        
        }else{
            echo $item->get($dfsFilePath);
        }
    }

    public function close()
    {
        mysqli_close( $this->db );
        unset( $this->db );
    }
}

ezpClusterGateway::setGatewayClass( 'xrowS3MemcachedClusterGateway' );
